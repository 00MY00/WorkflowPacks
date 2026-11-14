<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\FlowRun;

/**
 * Wakes the sleeping runs whose time has come (every minute, workflowpacks:engine-tick). One indexed query, a limited
 * batch; the rest waits for the next minute, so the load stays flat. Each run is claimed with an UPDATE … WHERE
 * state = waiting: two schedulers at once never take the same ticket, without any global lock (MySQL and PostgreSQL).
 */
class Scheduler
{
    const BATCH = 200;
    /** a run claimed but not finished for that long: the process died, it is put back */
    const STALE_MINUTES = 10;

    /** @var Engine */
    protected $engine;

    public function __construct(Engine $engine = null)
    {
        $this->engine = $engine ?: new Engine();
    }

    /** @return int runs woken */
    public function tick($limit = self::BATCH)
    {
        $now = \Carbon\Carbon::now();
        FlowRun::where('state', FlowRun::RUNNING)->whereNotNull('claimed_at')->where('claimed_at', '<', $now->copy()->subMinutes(self::STALE_MINUTES))
            ->update(['state' => FlowRun::WAITING, 'claimed_at' => null]);
        $ids = FlowRun::where('state', FlowRun::WAITING)->whereNotNull('wake_at')->where('wake_at', '<=', $now)
            ->orderBy('wake_at')->limit($limit)->pluck('id');
        $woken = 0;
        foreach ($ids as $id) {
            $claimed = FlowRun::where('id', $id)->where('state', FlowRun::WAITING)
                ->update(['state' => FlowRun::RUNNING, 'claimed_at' => $now]);
            if (!$claimed) {
                continue;
            }
            try {
                $this->engine->wake(FlowRun::find($id));
                $woken++;
            } catch (\Throwable $e) {
                \Log::error('[WorkflowPacks] [engine] run #'.$id.': '.$e->getMessage());
                FlowRun::where('id', $id)->update(['state' => FlowRun::FAILED, 'claimed_at' => null]);
            }
        }

        return $woken;
    }
}
