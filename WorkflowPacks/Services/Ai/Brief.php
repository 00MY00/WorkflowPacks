<?php

namespace Modules\WorkflowPacks\Services\Ai;

use Modules\WorkflowPacks\Services\Engine\BlockRegistry;
use Modules\WorkflowPacks\Services\Engine\Context;

/**
 * The file given to an AI (Claude, ChatGPT…) so that it writes a flow: the request, the rules of the answer, the
 * blocks it may use (from the registry: always up to date), and the tickets — id + text, anonymised by default. To
 * group tickets that look alike, the AI gives their ids to the block "Looks like": the vectors are computed here,
 * locally, and never leave the server.
 */
class Brief
{
    const FORMAT = 'workflowpacks-ai-brief';
    const MAX_TICKETS = 200;

    /** Rules of the answer, written for the AI (in English: understood by every model; the flow follows the task). */
    const RULES = [
        'Reply with ONE JSON object only (no text around it): {"format": "workflowpacks-flow", "version": 1, "name": "...", "description": "...", "blocks": [...], "links": [...]}.',
        'blocks: [{"id": "b1", "type": "...", "params": {...}}]; ids are short and unique. Exactly one trigger block, "trigger.new_mail".',
        'links: [{"from": "b1", "port": "next", "to": "b2"}]; "port" is one of the outputs of the "from" block. An output that leads nowhere ends that branch.',
        'Use only the block types listed in "blocks" and only their fields. Never invent a type, a field or a value.',
        '"condition.ticket" tests a variable given by a block before it (the trigger gives ticket.subject, ticket.body, ticket.from, ticket.status, ticket.assigned, ticket.replied). Operators: text: contains, not_contains, equal, not_equal, starts, ends, regex; choice: is, is_not; bool: yes, no.',
        'A regex is written without delimiters, case-insensitive, at most 2000 characters. Keep it readable; prefer a few clear words.',
        'To catch tickets that look like some tickets of this brief, use "condition.similar" with "examples": [their ids] (taken from "tickets") and "threshold": a percentage (75 is a good start). Pick examples that clearly belong to the group.',
        'A wait is "wait.duration" with "delay": {"number": 2, "metric": "h"} (metric: i = minutes, h = hours, d = days).',
        'Never put personal data (e-mail addresses, names, phone numbers) in the flow. Markers like [email] or [link:domain] in the tickets are anonymised data.',
        'Prefer few blocks. Put a short explanation in "description", in the language of the task.',
    ];

    /** @var BlockRegistry */
    protected $registry;

    public function __construct(BlockRegistry $registry = null)
    {
        $this->registry = $registry ?: new BlockRegistry();
    }

    /**
     * @param array $options mailbox_ids (int[]), days, limit, search, task, anonymise
     * @return array the brief (JSON-ready)
     */
    public function build(array $options)
    {
        $limit = max(1, min(self::MAX_TICKETS, (int) ($options['limit'] ?? 100)));
        $query = \App\Conversation::whereIn('mailbox_id', (array) $options['mailbox_ids'])
            ->where('state', \App\Conversation::STATE_PUBLISHED)
            ->where('created_at', '>=', \Carbon\Carbon::now()->subDays(max(1, (int) ($options['days'] ?? 30))));
        if (trim((string) ($options['search'] ?? '')) !== '') {
            $query->where('subject', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($options['search'])).'%');
        }
        $anonymise = (bool) ($options['anonymise'] ?? true);
        $tickets = [];
        foreach ($query->orderBy('id', 'desc')->limit($limit)->get() as $conversation) {
            $text = TicketText::of($conversation, $anonymise);
            $tickets[] = array_filter([
                'id'          => (int) $conversation->id,
                'subject'     => $text['subject'],
                'excerpt'     => $text['excerpt'],
                'from'        => $text['from'],
                'from_domain' => $text['from_domain'],
                'status'      => Context::STATUS[(int) $conversation->status] ?? 'active',
                'flags'       => $text['flags'],
                'attachments' => $text['attachments'],
            ], function ($v) {
                return $v !== '' && $v !== [];
            });
        }

        return [
            'format'     => self::FORMAT,
            'version'    => 1,
            'task'       => mb_substr(trim((string) ($options['task'] ?? '')), 0, 4000),
            'anonymised' => $anonymise,
            'rules'      => self::RULES,
            'blocks'     => $this->blocks(),
            'example'    => self::example(),
            'tickets'    => $tickets,
        ];
    }

    /** The blocks, as the AI needs them: type, family, what it does, fields, outputs, variables. */
    protected function blocks()
    {
        $out = [];
        foreach ($this->registry->types() as $type => $class) {
            $out[] = ['type' => $type, 'family' => $class::family(), 'help' => __('workflowpacks::blocks.'.$type.'.help'),
                'fields' => $class::fields(), 'outputs' => $class::outputs(), 'variables' => $class::variables([])];
        }

        return $out;
    }

    /** A complete answer, as a model to follow. */
    public static function example()
    {
        return [
            'format' => 'workflowpacks-flow', 'version' => 1, 'name' => 'Newsletters: close after 2 days',
            'description' => 'Mailing lists and newsletters are closed two days after their arrival.',
            'blocks' => [
                ['id' => 'b1', 'type' => 'trigger.new_mail', 'params' => []],
                ['id' => 'b2', 'type' => 'condition.ticket', 'params' => ['var' => 'ticket.subject', 'operator' => 'regex', 'value' => 'newsletter|unsubscribe|digest']],
                ['id' => 'b3', 'type' => 'condition.similar', 'params' => ['examples' => [101, 117, 123], 'threshold' => 75]],
                ['id' => 'b4', 'type' => 'wait.duration', 'params' => ['delay' => ['number' => 2, 'metric' => 'd']]],
                ['id' => 'b5', 'type' => 'action.status', 'params' => ['status' => 'closed']],
            ],
            'links' => [
                ['from' => 'b1', 'port' => 'next', 'to' => 'b2'],
                ['from' => 'b2', 'port' => 'yes', 'to' => 'b4'],
                ['from' => 'b2', 'port' => 'no', 'to' => 'b3'],
                ['from' => 'b3', 'port' => 'yes', 'to' => 'b4'],
                ['from' => 'b4', 'port' => 'next', 'to' => 'b5'],
            ],
        ];
    }
}
