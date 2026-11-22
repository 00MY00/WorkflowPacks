<?php

namespace Modules\WorkflowPacks\Services\Ai;

use Modules\WorkflowPacks\Entities\Agent;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowVersion;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\Engine\Reapply;
use Modules\WorkflowPacks\Services\Engine\TicketScope;
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
            'tickets' => $this->mayReadTickets(), 'anonymise' => $a->anonymise, 'permissions' => array_values($a->rights()),
            'read_only' => !$this->mayAny(Agent::WRITES), 'can_publish' => $this->mayAny(['own.publish', 'others.publish']),
            'can_activate' => $this->mayActivate(), 'can_see_all' => $a->may('others.see'), 'can_delete' => $this->mayDelete(),
            'can_deactivate' => $this->mayDeactivate(), 'can_reapply' => $this->mayReapply(),
            'reapply_limits' => $this->mayReapply() ? self::limitsShown($a->reapplyLimits()) : null, 'expires_at' => $a->expires_at ? $a->expires_at->toIso8601String() : null];
    }

    /**
     * Its limits as an agent reads them: period_max in days back (any number up to Reapply::MAX_DAYS, 0: no limit),
     * said plainly too, so that it is never taken for a fixed list of choices.
     */
    protected static function limitsShown(array $limits)
    {
        return $limits + ['period_unlimited' => $limits['period_max'] === 0, 'period_most' => Reapply::MAX_DAYS,
            'period_help' => $limits['period_max'] === 0
                ? 'period: any number of days back from 1 to '.Reapply::MAX_DAYS.', or 0 for all'
                : 'period: any number of days back from 1 to '.$limits['period_max']];
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
        // the temporary flows (a list of tickets handled once by an administrator) are never shown to an agent
        $q = $all && $this->agent->may('others.see') ? Flow::permanent()->orderBy('id') : Flow::permanent()->where('agent_id', $this->agent->id)->orderBy('id');

        return ['flows' => $q->get()->map(function (Flow $f) use ($numbers) {
            return ['id' => (int) $f->id, 'name' => $f->name, 'active' => $f->active, 'version' => $f->version_id ? ($numbers[$f->version_id] ?? null) : null,
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

    /** One of these rights at least. */
    public function mayAny(array $rights)
    {
        return (bool) array_intersect($rights, $this->agent->rights());
    }

    /** The refusal of an action on its own flows: "read_only" for an agent that changes nothing at all. */
    protected function denied($action)
    {
        return new AgentToolError(403, $this->mayAny(Agent::WRITES) ? $action.'_denied' : 'read_only');
    }

    /** A new flow (same JSON as the answer of the AI assistant), saved as a draft, switched off. */
    public function propose($json)
    {
        if (!$this->agent->may('own.propose')) {
            throw $this->denied('propose');
        }
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

    /**
     * A flow changed (draft): its own ones, or the others of its mailboxes with "edit the other flows". The published
     * version keeps running until the next publishing.
     */
    public function update($id, $json)
    {
        $flow = $this->target($id, 'edit');
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
        $flow = $this->target($id, 'test');
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

    const REPLAY_MINUTES = 1440;

    /**
     * A flow simulated step by step, with the e-mail given or, without one, an e-mail made to take its positive path
     * (Services\Engine\SampleMail). Every flow can be simulated (it reads them all); nothing is written or sent.
     * Steps (block, decision, changes, time), a story in plain words, and an address where an administrator replays it
     * in the editor (the same e-mail, played on the diagram).
     */
    public function simulate($id, $mail = null)
    {
        $flow = $this->target($id, 'see');
        $registry = new \Modules\WorkflowPacks\Services\Engine\BlockRegistry();
        $draft = (array) ($flow->draft ?: []);
        $problems = \Modules\WorkflowPacks\Services\Engine\Definition::from($draft)->validate($registry);
        if ($problems) {
            throw new AgentToolError(422, 'invalid_flow', $problems);
        }
        $sample = new \Modules\WorkflowPacks\Services\Engine\SampleMail($registry);
        $made = !is_array($mail) || !array_filter($mail, function ($v) {
            return $v !== null && $v !== '';
        });
        if ($made) {
            $mail = $sample->build($draft)['mail'];
        }
        $statuses = ['active', 'pending', 'closed', 'spam'];
        $mail = ['subject' => mb_substr((string) ($mail['subject'] ?? ''), 0, 255), 'from' => mb_substr((string) ($mail['from'] ?? ''), 0, 191),
            'body' => mb_substr((string) ($mail['body'] ?? ''), 0, 20000), 'status' => in_array($mail['status'] ?? '', $statuses, true) ? $mail['status'] : 'active',
            'assigned' => !empty($mail['assigned']), 'replied' => !empty($mail['replied'])];
        $trace = (new \Modules\WorkflowPacks\Services\Engine\Engine($registry))->simulate(\Modules\WorkflowPacks\Services\Engine\Definition::from($draft), $sample->ticketOf($mail));
        $types = [];
        $titles = [];
        foreach ((array) ($draft['blocks'] ?? []) as $b) {
            $class = $registry->get($b['type'] ?? '');
            $types[$b['id'] ?? ''] = (string) ($b['type'] ?? '');
            $titles[$b['id'] ?? ''] = $class ? $class::title((array) ($b['params'] ?? [])) : (string) ($b['type'] ?? '');
        }
        $steps = [];
        foreach ($trace['events'] as $i => $ev) {
            $steps[] = ['n' => $i + 1, 'at_seconds' => (int) $ev['at'], 'block' => $ev['block'], 'type' => $types[$ev['block']] ?? '',
                'title' => $titles[$ev['block']] ?? $ev['block'], 'output' => $ev['output'], 'decision' => (string) $ev['summary'],
                'changes' => array_map([Answer::class, 'change'], (array) $ev['changes']), 'level' => (int) $ev['level']];
        }
        $story = array_map(function ($s) {
            return $s['n'].'. '.$s['title'].($s['decision'] !== '' && $s['decision'] !== $s['title'] ? ' — '.$s['decision'] : '')
                // the decision of a condition already ends with its output ("… → yes")
                .($s['output'] && strpos($s['decision'], '→') === false ? ' → '.$s['output'] : '').($s['changes'] ? ' ['.implode(' ; ', $s['changes']).']' : '');
        }, $steps);
        $key = bin2hex(random_bytes(12));
        \Cache::put('workflowpacks.replay.'.$key, ['flow' => (int) $flow->id, 'mail' => $mail], self::REPLAY_MINUTES);

        return ['flow' => ['id' => (int) $flow->id, 'name' => $flow->name], 'mail' => $mail, 'mail_made' => $made, 'steps' => $steps,
            'end' => $trace['end'], 'story' => $story,
            'replay_url' => route('workflowpacks.flows', ['flow' => $flow->id, 'replay' => $key])];
    }

    /** May delete flows (its own ones or others). */
    public function mayDelete()
    {
        return $this->mayAny(['own.delete', 'others.delete']);
    }

    /**
     * A flow renamed: its own ones, or the others of its mailboxes when it may act on them. Only the name changes.
     */
    public function rename($id, $name)
    {
        $name = mb_substr(trim((string) $name), 0, 191);
        if ($name === '') {
            throw new AgentToolError(422, 'name_required');
        }
        $flow = $this->target($id, 'rename');
        $before = $flow->name;
        $flow->update(['name' => $name]);
        History::add('agent', 'ok', ['user_id' => $this->agent->created_by, 'subject' => $this->agent->name.' → '.$name,
            'message' => 'renamed #'.$flow->id.' ("'.$before.'" → "'.$name.'")']);

        return ['id' => (int) $flow->id, 'name' => $name, 'before' => $before];
    }

    /**
     * A flow deleted, only when asked with confirm: true. Its own ones, or the others of its mailboxes when it may act on
     * them. Saved first (blocks, links, settings: a backup an administrator downloads), then deleted as from the editor:
     * the tickets waiting in it leave it, they stay as they are.
     */
    public function deleteFlow($id, $confirm)
    {
        $flow = $this->target($id, 'delete');
        if (!$confirm) {
            throw new AgentToolError(409, 'confirm_required');
        }
        $backup = \Modules\WorkflowPacks\Entities\Backup::create(['pack_key' => 'flow-'.$flow->id.'-'.\Modules\WorkflowPacks\Services\Engine\FlowPack::slug($flow->name),
            'user_id' => $this->agent->created_by, 'content' => json_encode(['deleted_by_agent' => $this->agent->name, 'deleted_at' => \Carbon\Carbon::now()->toIso8601String(),
                'flow' => ['id' => (int) $flow->id, 'name' => $flow->name, 'active' => (bool) $flow->active, 'once' => (bool) $flow->once,
                    'mailbox_ids' => (array) $flow->mailbox_ids], 'pack' => \Modules\WorkflowPacks\Services\Engine\FlowPack::export([$flow], $flow->name)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $name = $flow->name;
        $flow->erase();
        History::add('agent', 'ok', ['user_id' => $this->agent->created_by, 'subject' => $this->agent->name.' → '.$name,
            'message' => 'deleted #'.(int) $id.' (backup #'.$backup->id.')']);

        return ['id' => (int) $id, 'deleted' => true, 'backup_id' => (int) $backup->id,
            'backup_url' => route('workflowpacks.backup', ['id' => $backup->id, 'part' => 'pack'])];
    }

    /** May switch on its own flows: allowed to publish, or allowed to switch on after a passed test (never read only). */
    public function mayActivate()
    {
        return $this->mayAny(['own.activate', 'own.publish', 'others.activate', 'others.publish']);
    }

    /**
     * Its own flow published and switched on. Allowed to publish: at once. Allowed to switch on after a test only: the
     * last test_flow of this very draft (unchanged since) must have passed, within a day. Within its mailboxes only.
     */
    public function activate($id)
    {
        // switched on at will with "publish", after a passed test with "switch on after a test"
        $flow = $this->target($id, ['activate', 'publish']);
        $this->inMailboxes($flow);
        $free = $this->agent->may($this->side($flow).'.publish');
        $tested = \Cache::get(self::testKey($flow->id));
        $ok = $tested && (int) $tested['agent'] === (int) $this->agent->id && $tested['passed'] && $tested['hash'] === self::fingerprint($flow);
        if (!$free && !$ok) {
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

    /** May apply flows to the tickets already there (its own, or the others of its mailboxes). */
    public function mayReapply()
    {
        return $this->mayAny(['own.reapply', 'others.reapply']);
    }

    /**
     * A published flow applied to the tickets already there, within the limits the administrator gave this agent and
     * within its mailboxes. Without confirm: only the estimate (how many tickets, a dry run on the most recent ones,
     * nothing done). With confirm: true: started, batch after batch, as from the editor ("once per ticket" applies).
     *   scope "existing" (default): tickets by statuses, period (days), tags, assigned users ("unassigned": nobody);
     *   scope "running": the tickets waiting in an older version go on in the current one.
     */
    public function reapply($id, array $in, $confirm, $confirmRisky = false)
    {
        $flow = $this->target($id, 'reapply');
        // it touches tickets: always within its mailboxes, its own flows too
        $this->inMailboxes($flow);
        if (!$flow->version_id) {
            throw new AgentToolError(409, 'not_published');
        }
        $limits = $this->agent->reapplyLimits();
        $o = $this->reapplyOptions($in, $limits);
        $service = new Reapply();
        $estimate = $service->estimate($flow, $o, !$confirm);
        if ($estimate['risky'] && !$limits['risky']) {
            throw new AgentToolError(403, 'risky_denied', ['blocks' => $estimate['risky']]);
        }
        if ($estimate['total'] > $limits['max_tickets']) {
            throw new AgentToolError(422, 'too_many_tickets', ['total' => $estimate['total'], 'max' => $limits['max_tickets']]);
        }
        $current = Reapply::current($flow);
        $answer = ['id' => (int) $flow->id, 'scope' => $o['scope'], 'filters' => array_intersect_key(Reapply::filters($flow, $o), array_flip(['statuses', 'period', 'tags', 'assignees'])),
            'total' => (int) $estimate['total'], 'minutes' => (int) $estimate['minutes'], 'risky' => $estimate['risky'], 'running' => $current ? self::job($current) : null];
        if (!$confirm) {
            return $answer + ['started' => false, 'sample' => $estimate['sample']];
        }
        if ($estimate['risky'] && !$confirmRisky) {
            throw new AgentToolError(409, 'confirm_risky_required', ['blocks' => $estimate['risky']]);
        }
        if ($current) {
            throw new AgentToolError(409, 'already_running');
        }
        $job = $service->start($flow, $o, $this->agent->created_by ? \App\User::find($this->agent->created_by) : null, $this->agent->id);
        if (!$job) {
            throw new AgentToolError(422, 'nothing_to_do');
        }
        $this->log('applied to the existing tickets ('.$job->total.' ticket(s), '.$o['scope'].')', $flow);

        return ['started' => true, 'job' => self::job($job)] + $answer;
    }

    /** The run of a flow on the existing tickets: its state (status), or paused, resumed, stopped. */
    public function reapplyControl($id, $action)
    {
        $flow = $this->target($id, 'reapply');
        $this->inMailboxes($flow);
        $action = (string) $action;
        if (!in_array($action, ['status', 'pause', 'resume', 'stop'], true)) {
            throw new AgentToolError(422, 'unknown_action');
        }
        $job = Reapply::current($flow);
        if ($action !== 'status') {
            if (!$job) {
                throw new AgentToolError(409, 'nothing_running');
            }
            Reapply::control($job, $action);
            $this->log('existing tickets: '.$action, $flow);
        }
        $job = $job ? $job->fresh() : \Modules\WorkflowPacks\Entities\FlowReapply::where('flow_id', $flow->id)->latest('id')->first();

        return ['id' => (int) $flow->id, 'job' => $job ? self::job($job) : null];
    }

    /** The options of a run asked by the agent, each one within its limits (else refused, with what is allowed). */
    protected function reapplyOptions(array $in, array $limits)
    {
        $scope = ($in['scope'] ?? 'existing') === 'running' ? 'running' : 'existing';
        $statuses = isset($in['statuses']) ? array_values(array_unique(array_map('strval', (array) $in['statuses']))) : $limits['statuses'];
        if (!$statuses || array_diff($statuses, $limits['statuses'])) {
            throw new AgentToolError(403, 'limit_statuses', ['allowed' => $limits['statuses']]);
        }
        // any number of days back, up to its limit (0 = all, only when it has no limit)
        $period = isset($in['period']) ? Reapply::period($in['period']) : $limits['period_max'];
        if ($period === null || ($limits['period_max'] > 0 && ($period === 0 || $period > $limits['period_max']))) {
            throw new AgentToolError(403, 'limit_period', ['max_days' => $limits['period_max'], 'most' => Reapply::MAX_DAYS]);
        }
        $tags = isset($in['tags']) ? TicketScope::tags((array) $in['tags']) : $limits['tags'];
        if ($limits['tags'] && (!$tags || array_diff($tags, $limits['tags']))) {
            throw new AgentToolError(403, 'limit_tags', ['allowed' => $limits['tags']]);
        }
        if ($tags && !TicketScope::tagSource()) {
            throw new AgentToolError(422, 'tags_unavailable');
        }
        $assignees = isset($in['assignees']) ? TicketScope::assignees((array) $in['assignees']) : $limits['assignees'];
        if ($limits['assignees'] && (!$assignees || array_diff($assignees, $limits['assignees']))) {
            throw new AgentToolError(403, 'limit_assignees', ['allowed' => $limits['assignees']]);
        }

        return ['scope' => $scope, 'statuses' => $statuses, 'period' => $period, 'tags' => $tags, 'assignees' => $assignees,
            'mailboxes' => array_map('intval', (array) $this->agent->mailbox_ids), 'batch' => 50, 'every' => 1];
    }

    /** A run as an agent reads it (no user names). */
    protected static function job(\Modules\WorkflowPacks\Entities\FlowReapply $job)
    {
        return ['state' => $job->state, 'scope' => $job->scope, 'total' => (int) $job->total, 'done' => (int) $job->done,
            'started' => (int) $job->started, 'by_agent' => $job->agent_id !== null];
    }

    /** May switch flows off (its own, or the others of its mailboxes). */
    public function mayDeactivate()
    {
        return $this->mayAny(['own.deactivate', 'others.deactivate']);
    }

    /**
     * A flow switched off, as from the editor: it no longer starts on new e-mails; its published version and its draft
     * stay, an administrator (or an agent allowed to) switches it on again. Already off: nothing changes.
     */
    public function deactivate($id)
    {
        $flow = $this->target($id, 'deactivate');
        if (!$flow->active) {
            return ['id' => (int) $flow->id, 'active' => false, 'changed' => false];
        }
        $flow->update(['active' => false]);
        \Cache::forget(self::testKey($flow->id));
        $this->log('switched off', $flow);

        return ['id' => (int) $flow->id, 'active' => false, 'changed' => true];
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
        $flow = $this->target($id, 'test');
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
        $flow = $this->target($id, 'publish');
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

    /**
     * Published, a flow runs on its mailboxes: only within the mailboxes given to the agent. A flow on every mailbox
     * (none chosen) only for an agent given every mailbox there is (a mailbox added later and not given: refused).
     */
    protected function inMailboxes(Flow $flow)
    {
        $mine = array_map('intval', array_filter((array) $this->agent->mailbox_ids));
        $targets = array_map('intval', array_filter((array) $flow->mailbox_ids));
        if (!$targets) {
            $targets = \App\Mailbox::pluck('id')->map(function ($id) {
                return (int) $id;
            })->all();
        }
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

    /** "own" for the flows it proposed, "others" for the rest. */
    protected function side(Flow $flow)
    {
        return (int) $flow->agent_id === (int) $this->agent->id ? 'own' : 'others';
    }

    /**
     * The flow of an action ("see", "test", "edit", "rename", "delete", "activate", "publish"; several: any of them).
     * Its own flows: seen and tested always, the rest with the matching "own." right (else refused, with the reason).
     * The other flows: only with the matching "others." right (else "not found": their existence is not told), and
     * for a change, only when they run on its mailboxes only.
     */
    protected function target($id, $actions)
    {
        $actions = (array) $actions;
        $flow = Flow::find((int) $id);
        if (!$flow || $flow->temporary) {
            throw new AgentToolError(404, 'not_found');
        }
        $side = $this->side($flow);
        $reading = !array_diff($actions, ['see', 'test']);
        if ($side === 'own' && $reading) {
            return $flow;
        }
        $allowed = $this->mayAny(array_map(function ($a) use ($side) {
            return $side.'.'.$a;
        }, $actions));
        if (!$allowed) {
            if ($side === 'own') {
                throw $this->denied($actions[0]);
            }
            throw new AgentToolError(404, 'not_found');
        }
        if ($side === 'others' && !$reading) {
            $this->inMailboxes($flow);
        }

        return $flow;
    }

    protected function log($what, Flow $flow)
    {
        History::add('agent', 'ok', ['user_id' => $this->agent->created_by, 'subject' => $this->agent->name.' → '.$flow->name, 'message' => $what.' #'.$flow->id]);
    }
}
