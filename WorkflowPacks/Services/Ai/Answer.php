<?php

namespace Modules\WorkflowPacks\Services\Ai;

use Modules\WorkflowPacks\Services\Engine\BlockRegistry;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Definition;
use Modules\WorkflowPacks\Services\Engine\Engine;

/**
 * The answer of the AI: read (a JSON object, also when wrapped in ```json fences), placed on the diagram, checked like
 * a draft before publishing, then played on the tickets of the brief in a dry run (nothing written, nothing sent).
 */
class Answer
{
    const MAX_BYTES = 262144;
    const GAP_X = 300;
    const GAP_Y = 110;

    /** @var BlockRegistry */
    protected $registry;

    public function __construct(BlockRegistry $registry = null)
    {
        $this->registry = $registry ?: new BlockRegistry();
    }

    /**
     * @return array ['ok' => bool, 'name', 'description', 'draft' => {blocks, links}, 'problems' => [...]]
     */
    public function read($text)
    {
        $text = trim((string) $text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $text = $m[1];
        }
        $data = strlen($text) <= self::MAX_BYTES ? json_decode($text, true) : null;
        if (!is_array($data) || !isset($data['blocks'])) {
            return ['ok' => false, 'name' => '', 'description' => '', 'draft' => null,
                'problems' => [['block' => null, 'field' => null, 'message' => __('workflowpacks::ai.not_json')]]];
        }
        $draft = ['blocks' => [], 'links' => []];
        foreach ((array) $data['blocks'] as $b) {
            if (is_array($b)) {
                $draft['blocks'][] = ['id' => (string) ($b['id'] ?? ''), 'type' => (string) ($b['type'] ?? ''), 'params' => (array) ($b['params'] ?? [])];
            }
        }
        foreach ((array) ($data['links'] ?? []) as $l) {
            if (is_array($l)) {
                $draft['links'][] = ['from' => (string) ($l['from'] ?? ''), 'port' => (string) ($l['port'] ?? 'next'), 'to' => (string) ($l['to'] ?? '')];
            }
        }
        $draft = self::place($draft);

        return ['ok' => true, 'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 191),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 2000), 'draft' => $draft,
            'problems' => Definition::from($draft)->validate($this->registry)];
    }

    /** Left to right by distance from the trigger, one row per block of a column. */
    public static function place(array $draft)
    {
        $depth = [];
        $next = [];
        foreach ($draft['links'] as $l) {
            $next[$l['from']][] = $l['to'];
        }
        $first = $draft['blocks'][0]['id'] ?? null;
        $queue = $first !== null ? [$first] : [];
        if ($first !== null) {
            $depth[$first] = 0;
        }
        while ($queue) {
            $id = array_shift($queue);
            foreach ($next[$id] ?? [] as $to) {
                if (!isset($depth[$to])) {
                    $depth[$to] = $depth[$id] + 1;
                    $queue[] = $to;
                }
            }
        }
        $rows = [];
        foreach ($draft['blocks'] as $i => $b) {
            $col = $depth[$b['id']] ?? (max($depth ?: [0]) + 1);
            $row = $rows[$col] = ($rows[$col] ?? -1) + 1;
            $draft['blocks'][$i]['x'] = 40 + $col * self::GAP_X;
            $draft['blocks'][$i]['y'] = 80 + $row * self::GAP_Y;
        }

        return $draft;
    }

    /** A change in words, in the language of the user: "Status: Closed". */
    public static function change(array $c)
    {
        $labels = ['status' => 'workflowpacks::blocks.vars.ticket.status', 'assignee' => 'workflowpacks::blocks.vars.ticket.assigned',
            'folder' => 'workflowpacks::flows.field_path', 'note' => 'workflowpacks::flows.field_text'];
        $value = (string) $c[2];
        if ($c[0] === 'status') {
            $value = __('workflowpacks::blocks.values.status.'.$value);
        }

        return (isset($labels[$c[0]]) ? __($labels[$c[0]]) : $c[0]).' : '.$value;
    }

    /**
     * The flow played on tickets (dry run): what each one would get. Grouped by outcome, so that the result reads at a
     * glance ("31 tickets: Status closed", "12 tickets: no action").
     *
     * @return array ['groups' => [['changes' => [...], 'tickets' => [ids]]], 'tickets' => [id => ['end', 'path', 'changes']]]
     */
    public function test(array $draft, array $ticketIds)
    {
        $engine = new Engine($this->registry);
        $def = Definition::from($draft);
        $groups = [];
        $tickets = [];
        foreach (\App\Conversation::whereIn('id', array_slice($ticketIds, 0, Brief::MAX_TICKETS))->get() as $conversation) {
            $trace = $engine->simulate($def, [], 31536000, Context::forConversation($conversation, [], true));
            $changes = [];
            foreach ($trace['events'] as $ev) {
                foreach ($ev['changes'] as $c) {
                    $changes[] = self::change($c);
                }
            }
            $key = $changes ? implode(' · ', $changes) : '';
            $groups[$key][] = (int) $conversation->id;
            $tickets[(int) $conversation->id] = ['end' => $trace['end'], 'path' => array_column($trace['events'], 'block'),
                'summary' => array_values(array_filter(array_column($trace['events'], 'summary')))];
        }
        uasort($groups, function ($a, $b) {
            return count($b) - count($a);
        });
        $out = [];
        foreach ($groups as $key => $ids) {
            $out[] = ['changes' => $key, 'tickets' => $ids];
        }

        return ['groups' => $out, 'tickets' => $tickets];
    }
}
