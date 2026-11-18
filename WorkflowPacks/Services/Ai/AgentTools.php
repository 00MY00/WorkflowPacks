<?php

namespace Modules\WorkflowPacks\Services\Ai;

use Modules\WorkflowPacks\Entities\Agent;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowVersion;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\History;

/**
 * What a connected AI agent may do, the same through the JSON API (AgentApiController) and the MCP connector of
 * Claude (McpController): read the catalog and the flows, read the tickets of the mailboxes it was given (anonymised
 * unless chosen otherwise; none given: no ticket at all), propose flows as switched-off drafts, change and test its
 * own flows, publish them only when allowed. Every change is written in History. Refusals: AgentToolError.
 */
class AgentTools
{
    /** @var Agent */
    protected $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    public function me()
    {
        $a = $this->agent;

        return ['name' => $a->name, 'mode' => $a->mode, 'mailbox_ids' => array_map('intval', (array) $a->mailbox_ids),
            'tickets' => $this->mayReadTickets(), 'anonymise' => $a->anonymise, 'read_only' => (bool) $a->read_only,
            'can_publish' => $a->can_publish && !$a->read_only, 'can_activate' => $this->mayActivate(),
            'expires_at' => $a->expires_at ? $a->expires_at->toIso8601String() : null];
    }

    /** The blocks and the rules of a flow: the same as in the brief of the AI assistant. */
    public function catalog()
    {
        return ['rules' => Brief::RULES, 'blocks' => (new Brief())->blocks(), 'example' => Brief::example()];
    }

    /**
     * The flows: its own ones ($all false, the JSON API), or every flow to read them, its own ones marked ($all true:
     * the connector, "see the rules"). Only its own ones can be changed.
     */
    public function flows($all = false)
    {
        $numbers = FlowVersion::pluck('number', 'id');
        $q = $all ? Flow::orderBy('id') : Flow::where('agent_id', $this->agent->id)->orderBy('id');

        return ['flows' => $q->get()->map(function (Flow $f) use ($numbers) {
            return ['id' => (int) $f->id, 'name' => $f->name, 'active' => $f->active, 'version' => $numbers[$f->version_id] ?? null,
                'mailbox_ids' => array_map('intval', (array) $f->mailbox_ids), 'mine' => (int) $f->agent_id === (int) $this->agent->id,
                'draft' => $f->draft];
        })->values()->all()];
    }

    public function mayReadTickets()
    {
        return (bool) array_filter((array) $this->agent->mailbox_ids);
    }

    /** Tickets of the mailboxes it was given: text cut, anonymised unless the administrator chose otherwise. */
    public function tickets(array $in)
    {
        if (!$this->mayReadTickets()) {
            throw new AgentToolError(403, 'tickets_off');
        }
        $mailboxes = !empty($in['mailbox_id']) ? [(int) $in['mailbox_id']] : array_map('intval', (array) $this->agent->mailbox_ids);
        foreach ($mailboxes as $id) {
            if (!$this->agent->mayRead($id)) {
                throw new AgentToolError(403, 'mailbox_denied');
            }
        }

        $tickets = (new Brief())->tickets([
            'mailbox_ids' => $mailboxes, 'days' => (int) ($in['days'] ?? 30), 'limit' => (int) ($in['limit'] ?? 100),
            'search' => (string) ($in['search'] ?? ''), 'anonymise' => $this->agent->anonymise]);
        // personal data read by an outside tool: written in History each time
        History::add('agent', 'ok', ['user_id' => $this->agent->created_by, 'subject' => $this->agent->name,
            'message' => 'read '.count($tickets).' ticket(s)'.($this->agent->anonymise ? ' (anonymised)' : ' (full text)')]);

        return ['anonymised' => $this->agent->anonymise, 'tickets' => $tickets];
    }

    /** Proposing and changing flows: not for an agent that only reads. */
    public function mayWrite()
    {
        return !$this->agent->read_only;
    }

    protected function write()
    {
        if (!$this->mayWrite()) {
            throw new AgentToolError(403, 'read_only');
        }
    }

    /** A new flow (same JSON as the answer of the AI assistant), saved as a draft, switched off. */
    public function propose($json)
    {
        $this->write();
        $answer = $this->read($json);
        $this->scope($answer['draft']);
        $agent = $this->agent;
        $flow = Flow::create(['name' => $answer['name'] ?: $agent->name, 'mailbox_ids' => array_map('intval', (array) $agent->mailbox_ids),
            'active' => false, 'once' => true, 'draft' => $answer['draft'], 'created_by' => $agent->created_by, 'agent_id' => $agent->id]);
        $this->log('proposed', $flow);
        // an access for one use closes after its proposal
        if ($agent->mode === Agent::ONCE) {
            $agent->revoke();
        }

        return ['id' => (int) $flow->id, 'name' => $flow->name, 'state' => 'draft'];
    }

    /** Its own flow changed (draft); the published version keeps running until the next publishing. */
    public function update($id, $json)
    {
        $this->write();
        $flow = $this->own($id);
        $answer = $this->read($json);
        $this->scope($answer['draft']);
        $flow->update(['draft' => $answer['draft']] + ($answer['name'] !== '' ? ['name' => $answer['name']] : []));
        $this->log('changed', $flow);

        return ['id' => (int) $flow->id, 'state' => 'draft'];
    }

    /** Hours a passed test stays valid for "activate" (the draft must not change in between). */
    const TEST_VALID_MINUTES = 1440;

    /**
     * The draft played on tickets of its mailboxes (dry run, nothing written): what each one would get, and the verdict:
     * passed = at least one ticket played and none stopped on an error. A passed verdict is kept for this very draft
     * (its fingerprint), so that "activate" can rely on it.
     */
    public function test($id, array $ticketIds = [])
    {
        $result = $this->play($id, $ticketIds);
        $flow = $this->own($id);
        $failed = array_keys(array_filter($result['tickets'], function ($t) {
            return ($t['end'] ?? '') === \Modules\WorkflowPacks\Entities\FlowRun::FAILED;
        }));
        $passed = count($result['tickets']) > 0 && !$failed;
        \Cache::put(self::testKey($flow->id), ['agent' => (int) $this->agent->id, 'hash' => self::fingerprint($flow), 'passed' => $passed,
            'tickets' => count($result['tickets'])], self::TEST_VALID_MINUTES);
        $result['verdict'] = ['passed' => $passed, 'tickets' => count($result['tickets']), 'failed' => array_values($failed),
            'can_activate' => $passed && $this->mayActivate()];

        return $result;
    }

    /** May switch on its own flows: allowed to publish, or allowed to switch on after a passed test (never read only). */
    public function mayActivate()
    {
        return !$this->agent->read_only && ($this->agent->can_publish || $this->agent->can_activate);
    }

    /**
     * Its own flow published and switched on. Allowed to publish: at once. Allowed to switch on after a test only: the
     * last test_flow of this very draft (unchanged since) must have passed, within a day. Within its mailboxes only.
     */
    public function activate($id)
    {
        if (!$this->mayActivate()) {
            throw new AgentToolError(403, 'activate_denied');
        }
        $flow = $this->own($id);
        $this->inMailboxes($flow);
        $tested = \Cache::get(self::testKey($flow->id));
        $ok = $tested && (int) $tested['agent'] === (int) $this->agent->id && $tested['passed'] && $tested['hash'] === self::fingerprint($flow);
        if (!$this->agent->can_publish && !$ok) {
            throw new AgentToolError(409, 'test_required');
        }
        $result = (new Publisher())->publish($flow, $this->agent->created_by ? \App\User::find($this->agent->created_by) : null);
        if (!$result['ok']) {
            throw new AgentToolError(422, 'invalid_flow', $result['problems']);
        }
        $flow->update(['active' => true]);
        \Cache::forget(self::testKey($flow->id));
        $this->log($ok ? 'switched on after a passed test ('.$tested['tickets'].' ticket(s))' : 'published and switched on', $flow);

        return ['id' => (int) $flow->id, 'version' => (int) $result['version']->number, 'active' => true, 'tested' => (bool) $ok];
    }

    protected static function testKey($flowId)
    {
        return 'workflowpacks.agent.tested.'.(int) $flowId;
    }

    /** What exactly was tested: the blocks and links of the draft. */
    protected static function fingerprint(Flow $flow)
    {
        return hash('sha256', json_encode((array) $flow->draft));
    }

    /** The dry run itself. */
    protected function play($id, array $ticketIds = [])
    {
        $flow = $this->own($id);
        $agent = $this->agent;
        $ids = array_map('intval', $ticketIds);
        $allowed = \App\Conversation::whereIn('id', $ids ?: [0])->get(['id', 'mailbox_id'])->filter(function ($c) use ($agent) {
            return $agent->mayRead($c->mailbox_id);
        })->pluck('id')->all();
        if (!$ids && $this->mayReadTickets()) {
            $allowed = array_column((new Brief())->tickets(['mailbox_ids' => (array) $agent->mailbox_ids, 'limit' => 100, 'anonymise' => true]), 'id');
        }

        return (new Answer())->test((array) $flow->draft, $allowed);
    }

    /** Publish (and switch on) its own flow: only when the administrator allowed it for this agent. */
    public function publish($id, $activate = false)
    {
        if (!$this->agent->can_publish || $this->agent->read_only) {
            throw new AgentToolError(403, 'publish_denied');
        }
        $flow = $this->own($id);
        $this->inMailboxes($flow);
        $result = (new Publisher())->publish($flow, $this->agent->created_by ? \App\User::find($this->agent->created_by) : null);
        if (!$result['ok']) {
            throw new AgentToolError(422, 'invalid_flow', $result['problems']);
        }
        if ($activate) {
            $flow->update(['active' => true]);
        }
        $this->log('published', $flow);

        return ['id' => (int) $flow->id, 'version' => (int) $result['version']->number, 'active' => (bool) $flow->fresh()->active];
    }

    /** Published, a flow runs on its mailboxes (none: every mailbox): only within the mailboxes given to the agent. */
    protected function inMailboxes(Flow $flow)
    {
        $mine = array_map('intval', array_filter((array) $this->agent->mailbox_ids));
        $targets = array_map('intval', (array) $flow->mailbox_ids);
        if (!$mine || !$targets || array_diff($targets, $mine)) {
            throw new AgentToolError(403, 'mailbox_denied');
        }
        $this->scope((array) $flow->draft);
    }

    /**
     * A flow of an agent stays within what it was given: a mailbox named in a block ("file into a folder") and the
     * example tickets of "Looks like" must belong to its mailboxes (the tickets it may read).
     */
    protected function scope(array $draft)
    {
        $mine = array_map('intval', array_filter((array) $this->agent->mailbox_ids));
        foreach ((array) ($draft['blocks'] ?? []) as $block) {
            $params = (array) ($block['params'] ?? []);
            if (!empty($params['mailbox']) && !in_array((int) $params['mailbox'], $mine, true)) {
                throw new AgentToolError(403, 'mailbox_denied');
            }
            if (($block['type'] ?? '') === 'condition.similar') {
                $ids = \Modules\WorkflowPacks\Blocks\Conditions\Similar::ids($params['examples'] ?? []);
                if ($ids && (!$mine || \App\Conversation::whereIn('id', $ids)->whereNotIn('mailbox_id', $mine)->exists())) {
                    throw new AgentToolError(403, 'examples_denied');
                }
            }
        }
    }

    protected function read($json)
    {
        $answer = (new Answer())->read(is_string($json) ? $json : json_encode($json));
        if (!$answer['ok'] || $answer['problems']) {
            throw new AgentToolError(422, 'invalid_flow', $answer['problems']);
        }

        return $answer;
    }

    protected function own($id)
    {
        $flow = Flow::where('id', (int) $id)->where('agent_id', $this->agent->id)->first();
        if (!$flow) {
            throw new AgentToolError(404, 'not_found');
        }

        return $flow;
    }

    protected function log($what, Flow $flow)
    {
        History::add('agent', 'ok', ['user_id' => $this->agent->created_by, 'subject' => $this->agent->name.' → '.$flow->name, 'message' => $what.' #'.$flow->id]);
    }
}
