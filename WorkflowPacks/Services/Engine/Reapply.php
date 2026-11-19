<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowReapply;
use Modules\WorkflowPacks\Entities\FlowRun;

/**
 * A flow applied to the tickets already there (docs/flux-internes.md):
 *   - "running": the tickets waiting in an older version go on in the current one, at the same block (a block that no
 *     longer exists: the ticket leaves the flow, nothing done);
 *   - "existing": the tickets of the flow's mailboxes over a period (and statuses) run through the flow, as if their
 *     e-mail had just arrived ("once per ticket" still applies).
 * Estimated first (counting queries only), tried on a sample (dry run), then done in batches by the scheduler
 * (workflowpacks:engine-tick): one batch per due job per minute, claimed atomically, resumed at its cursor.
 */
class Reapply
{
    const BATCHES = [10, 25, 50, 100, 200, 500];
    const EVERY = [1, 2, 5, 10, 30, 60];
    /** quick choices of the period (days, 0: all); any number of days from 1 to MAX_DAYS is accepted too */
    const PERIODS = [7, 30, 90, 365, 0];
    /** at most this many days back (ten years) */
    const MAX_DAYS = 3650;
    const SAMPLE = 20;
    /** blocks worth a warning and a ticked confirmation before starting */
    const RISKY = ['action.delete', 'action.push'];

    /** @var Engine */
    protected $engine;

    public function __construct(Engine $engine = null)
    {
        $this->engine = $engine ?: new Engine();
    }

    /**
     * A period in days: 0 (all) or 1 to MAX_DAYS; anything else (not a number, negative, too far): null.
     */
    public static function period($value)
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\s*\d{1,6}\s*$/', $value))) {
            $days = (int) $value;

            return $days >= 0 && $days <= self::MAX_DAYS ? $days : null;
        }

        return null;
    }

    /** Options from a request, always within the allowed values. */
    public static function options(array $in)
    {
        $statuses = array_values(array_intersect((array) ($in['statuses'] ?? ['active', 'pending']), ['active', 'pending', 'closed']));
        $period = self::period($in['period'] ?? 30);
        $batch = (int) ($in['batch'] ?? 50);
        $every = (int) ($in['every'] ?? 1);

        return [
            'scope'    => ($in['scope'] ?? '') === FlowReapply::SCOPE_RUNNING ? FlowReapply::SCOPE_RUNNING : FlowReapply::SCOPE_EXISTING,
            'period'   => $period === null ? 30 : $period,
            'statuses' => $statuses ?: ['active', 'pending'],
            'batch'    => in_array($batch, self::BATCHES, true) ? $batch : 50,
            'every'    => in_array($every, self::EVERY, true) ? $every : 1,
        ];
    }

    /**
     * The filters of a run: those chosen (statuses, period; for an agent also its mailboxes, the assigned users, tags),
     * within the mailboxes of the flow.
     */
    public static function filters(Flow $flow, array $o)
    {
        $f = TicketScope::clean($o);
        $own = array_values(array_filter(array_map('intval', (array) $flow->mailbox_ids)));
        if ($own) {
            // both given: only the mailboxes in both (none in common: no ticket)
            $f['mailboxes'] = $f['mailboxes'] ? (array_values(array_intersect($f['mailboxes'], $own)) ?: [0]) : $own;
        }

        return $f;
    }

    /** Existing tickets concerned (ordered by id, for the cursor; newest first for a sample); the tags are checked afterwards (TicketScope). */
    public static function tickets(Flow $flow, array $o, $afterId = 0, $newestFirst = false)
    {
        $q = \App\Conversation::where('state', \App\Conversation::STATE_PUBLISHED)->where('id', '>', (int) $afterId)->orderBy('id', $newestFirst ? 'desc' : 'asc');
        TicketScope::apply($q, self::filters($flow, $o));
        if ($flow->once) {
            $q->whereNotIn('id', FlowRun::where('flow_id', $flow->id)->select('conversation_id'));
        }

        return $q;
    }

    /** Tickets waiting in an older version of the flow. */
    public static function runs(Flow $flow)
    {
        return FlowRun::where('flow_id', $flow->id)->where('state', FlowRun::WAITING)->where('version_id', '!=', (int) $flow->version_id);
    }

    /**
     * Flow never published: the draft is used for the dry run (it is published when the run is started); its problems,
     * when there are some, instead of the dry run.
     *
     * @return array ['total', 'minutes', 'risky' => [types], 'sample' => Answer::test()|null, 'draft' => bool, 'problems' => [texts]]
     */
    public function estimate(Flow $flow, array $o, $withSample = true)
    {
        $existing = $o['scope'] === FlowReapply::SCOPE_EXISTING;
        $tags = self::filters($flow, $o)['tags'];
        $total = $existing ? TicketScope::count(self::tickets($flow, $o), $tags) : self::runs($flow)->count();
        $draft = !$flow->version_id;
        $data = $draft ? (array) ($flow->draft ?: []) : (array) $flow->version->data;
        $problems = $draft ? array_column(Definition::from($data)->validate($this->engine->registry()), 'message') : [];
        $types = array_column((array) ($data['blocks'] ?? []), 'type');
        $sample = null;
        if ($existing && $withSample && $total && !$problems) {
            $ids = self::tickets($flow, $o, 0, true)->limit($tags ? 500 : self::SAMPLE)->pluck('id')->all();
            $ids = array_slice(TicketScope::withTags($ids, $tags), 0, self::SAMPLE);
            $sample = (new \Modules\WorkflowPacks\Services\Ai\Answer($this->engine->registry()))->test($data, $ids);
        }

        return ['total' => $total, 'minutes' => (int) ceil($total / $o['batch']) * $o['every'],
            'risky' => array_values(array_intersect(self::RISKY, $types)), 'sample' => $sample, 'draft' => $draft, 'problems' => $problems];
    }

    /** Null: a job is already going on for this flow (one at a time), or nothing to do. $agentId: started by an agent. */
    public function start(Flow $flow, array $o, $user, $agentId = null)
    {
        if (!$flow->version_id || self::current($flow)) {
            return null;
        }
        $total = $this->estimate($flow, $o, false)['total'];
        if (!$total) {
            return null;
        }
        $job = FlowReapply::create(['flow_id' => $flow->id, 'version_id' => $flow->version_id, 'scope' => $o['scope'],
            'options' => self::kept($o), 'total' => $total, 'batch' => $o['batch'],
            'every' => $o['every'], 'state' => FlowReapply::RUNNING, 'next_at' => \Carbon\Carbon::now(), 'created_by' => $user ? $user->id : null,
            'agent_id' => $agentId ? (int) $agentId : null]);
        \Modules\WorkflowPacks\Services\History::add('flow', 'queued', ['subject' => $flow->name,
            'message' => __('workflowpacks::reapply.history_start', ['count' => $total, 'scope' => __('workflowpacks::reapply.scope_'.$o['scope'])])]);

        return $job;
    }

    /** The filters kept with a job (read again for each batch). */
    protected static function kept(array $o)
    {
        $f = TicketScope::clean($o);

        return array_filter(['period' => $f['period'], 'statuses' => $f['statuses'], 'mailboxes' => $f['mailboxes'],
            'assignees' => $f['assignees'], 'tags' => $f['tags'], 'ids' => $f['ids']], function ($v) {
                return $v !== [];
            });
    }

    /** The job going on (running or paused) for a flow. */
    public static function current(Flow $flow)
    {
        return FlowReapply::where('flow_id', $flow->id)->whereIn('state', [FlowReapply::RUNNING, FlowReapply::PAUSED])->latest('id')->first();
    }

    public static function control(FlowReapply $job, $action)
    {
        $to = ['pause' => FlowReapply::PAUSED, 'resume' => FlowReapply::RUNNING, 'stop' => FlowReapply::STOPPED][$action] ?? null;
        if ($to && $job->active()) {
            $job->update(['state' => $to, 'next_at' => $to === FlowReapply::RUNNING ? \Carbon\Carbon::now() : $job->next_at]);
        }

        return $job;
    }

    /** Every minute (scheduler): one batch of each due job. @return int tickets handled */
    public function tick()
    {
        $now = \Carbon\Carbon::now();
        $handled = 0;
        foreach (FlowReapply::where('state', FlowReapply::RUNNING)->where('next_at', '<=', $now)->orderBy('next_at')->get() as $job) {
            // claimed: two schedulers at once never run the same batch
            $claimed = FlowReapply::where('id', $job->id)->where('state', FlowReapply::RUNNING)->where('next_at', $job->next_at)
                ->update(['next_at' => $now->copy()->addMinutes(max(1, (int) $job->every))]);
            if ($claimed) {
                try {
                    $handled += $this->batch($job->fresh());
                } catch (\Throwable $e) {
                    \Log::error('[WorkflowPacks] [engine] reapply #'.$job->id.': '.$e->getMessage());
                }
            }
        }

        return $handled;
    }

    /** One batch of a job; the job is finished when nothing is left. @return int tickets handled */
    public function batch(FlowReapply $job)
    {
        $flow = Flow::with('version')->find($job->flow_id);
        // a temporary flow only ever runs on its list: a job without it is stopped
        if (!$flow || !$flow->version || ($flow->temporary && empty($job->options['ids']))) {
            $job->update(['state' => FlowReapply::STOPPED]);

            return 0;
        }
        $o = ['period' => (int) ($job->options['period'] ?? 30), 'statuses' => (array) ($job->options['statuses'] ?? ['active', 'pending'])]
            + array_intersect_key((array) $job->options, array_flip(['mailboxes', 'assignees', 'tags', 'ids']));
        // how the tickets enter the flow, told to the other modules with each change
        $origin = $flow->temporary ? FlowRun::ORIGIN_TEMPORARY : ($job->agent_id ? FlowRun::ORIGIN_AGENT : FlowRun::ORIGIN_REAPPLY);
        $tags = self::filters($flow, $o)['tags'];
        $count = 0;
        $handled = 0;
        $started = 0;
        $cursor = (int) $job->cursor;
        if ($job->scope === FlowReapply::SCOPE_RUNNING) {
            $blocks = array_column((array) ($flow->version->data['blocks'] ?? []), 'id');
            foreach (self::runs($flow)->orderBy('id')->limit($job->batch)->get() as $run) {
                // the same block in the new version: goes on there; else it leaves the flow (nothing undone)
                $kept = in_array($run->block, $blocks, true);
                FlowRun::where('id', $run->id)->where('state', FlowRun::WAITING)
                    ->update($kept ? ['version_id' => $flow->version_id] : ['state' => FlowRun::CANCELLED]);
                $started += $kept ? 1 : 0;
                $count++;
            }
        } else {
            $scanned = self::tickets($flow, $o, $cursor)->limit($job->batch)->get();
            $kept = array_flip(TicketScope::withTags($scanned->pluck('id')->all(), $tags));
            foreach ($scanned as $conversation) {
                $cursor = (int) $conversation->id;
                if (isset($kept[$cursor])) {
                    $started += $this->engine->start($flow, $conversation, $origin) ? 1 : 0;
                    $handled++;
                }
                $count++;
            }
        }
        // tickets in progress: each one handled; existing tickets: those having the tags among the ones looked at
        $handled = $job->scope === FlowReapply::SCOPE_RUNNING ? $count : $handled;
        $done = $count < $job->batch;
        $job->update(['cursor' => $cursor, 'done' => min($job->total, $job->done + $handled), 'started' => $job->started + $started,
            'state' => $done ? FlowReapply::DONE : $job->fresh()->state]);
        if ($done) {
            \Modules\WorkflowPacks\Services\History::add('flow', 'ok', ['subject' => $flow->name,
                'message' => __('workflowpacks::reapply.history_done', ['done' => $job->done, 'started' => $job->started])]);
        }

        return $count;
    }
}
