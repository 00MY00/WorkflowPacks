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
    const PERIODS = [7, 30, 90, 365, 0];   // days, 0: all
    const SAMPLE = 20;
    /** blocks worth a warning and a ticked confirmation before starting */
    const RISKY = ['action.delete', 'action.push'];

    /** @var Engine */
    protected $engine;

    public function __construct(Engine $engine = null)
    {
        $this->engine = $engine ?: new Engine();
    }

    /** Options from a request, always within the allowed values. */
    public static function options(array $in)
    {
        $statuses = array_values(array_intersect((array) ($in['statuses'] ?? ['active', 'pending']), ['active', 'pending', 'closed']));
        $period = (int) ($in['period'] ?? 30);
        $batch = (int) ($in['batch'] ?? 50);
        $every = (int) ($in['every'] ?? 1);

        return [
            'scope'    => ($in['scope'] ?? '') === FlowReapply::SCOPE_RUNNING ? FlowReapply::SCOPE_RUNNING : FlowReapply::SCOPE_EXISTING,
            'period'   => in_array($period, self::PERIODS, true) ? $period : 30,
            'statuses' => $statuses ?: ['active', 'pending'],
            'batch'    => in_array($batch, self::BATCHES, true) ? $batch : 50,
            'every'    => in_array($every, self::EVERY, true) ? $every : 1,
        ];
    }

    /** Existing tickets concerned (ordered by id, for the cursor). */
    public static function tickets(Flow $flow, array $o, $afterId = 0)
    {
        $statuses = array_values(array_filter(array_map(function ($s) {
            return array_search($s, Context::STATUS, true);
        }, $o['statuses'])));
        $q = \App\Conversation::where('state', \App\Conversation::STATE_PUBLISHED)->whereIn('status', $statuses ?: [0])
            ->where('id', '>', (int) $afterId)->orderBy('id');
        $mailboxes = array_map('intval', (array) $flow->mailbox_ids);
        if ($mailboxes) {
            $q->whereIn('mailbox_id', $mailboxes);
        }
        if ($o['period'] > 0) {
            $q->where('created_at', '>=', \Carbon\Carbon::now()->subDays($o['period']));
        }
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

    /** @return array ['total', 'minutes', 'risky' => [types], 'running' => count, 'sample' => Answer::test()|null] */
    public function estimate(Flow $flow, array $o, $withSample = true)
    {
        $existing = $o['scope'] === FlowReapply::SCOPE_EXISTING;
        $total = $existing ? self::tickets($flow, $o)->count() : self::runs($flow)->count();
        $types = array_column((array) ($flow->version->data['blocks'] ?? []), 'type');
        $sample = null;
        if ($existing && $withSample && $total) {
            $ids = self::tickets($flow, $o)->orderBy('id', 'desc')->limit(self::SAMPLE)->pluck('id')->all();
            $sample = (new \Modules\WorkflowPacks\Services\Ai\Answer($this->engine->registry()))->test((array) $flow->version->data, $ids);
        }

        return ['total' => $total, 'minutes' => (int) ceil($total / $o['batch']) * $o['every'],
            'risky' => array_values(array_intersect(self::RISKY, $types)), 'sample' => $sample];
    }

    /** Null: a job is already going on for this flow (one at a time), or nothing to do. */
    public function start(Flow $flow, array $o, $user)
    {
        if (!$flow->version_id || self::current($flow)) {
            return null;
        }
        $total = $this->estimate($flow, $o, false)['total'];
        if (!$total) {
            return null;
        }
        $job = FlowReapply::create(['flow_id' => $flow->id, 'version_id' => $flow->version_id, 'scope' => $o['scope'],
            'options' => ['period' => $o['period'], 'statuses' => $o['statuses']], 'total' => $total, 'batch' => $o['batch'],
            'every' => $o['every'], 'state' => FlowReapply::RUNNING, 'next_at' => \Carbon\Carbon::now(), 'created_by' => $user ? $user->id : null]);
        \Modules\WorkflowPacks\Services\History::add('flow', 'queued', ['subject' => $flow->name,
            'message' => __('workflowpacks::reapply.history_start', ['count' => $total, 'scope' => __('workflowpacks::reapply.scope_'.$o['scope'])])]);

        return $job;
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
        if (!$flow || !$flow->version) {
            $job->update(['state' => FlowReapply::STOPPED]);

            return 0;
        }
        $o = ['period' => (int) ($job->options['period'] ?? 30), 'statuses' => (array) ($job->options['statuses'] ?? ['active', 'pending'])];
        $count = 0;
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
            foreach (self::tickets($flow, $o, $cursor)->limit($job->batch)->get() as $conversation) {
                $cursor = (int) $conversation->id;
                $started += $this->engine->start($flow, $conversation) ? 1 : 0;
                $count++;
            }
        }
        $done = $count < $job->batch;
        $job->update(['cursor' => $cursor, 'done' => min($job->total, $job->done + $count), 'started' => $job->started + $started,
            'state' => $done ? FlowReapply::DONE : $job->fresh()->state]);
        if ($done) {
            \Modules\WorkflowPacks\Services\History::add('flow', 'ok', ['subject' => $flow->name,
                'message' => __('workflowpacks::reapply.history_done', ['done' => $job->done, 'started' => $job->started])]);
        }

        return $count;
    }
}
