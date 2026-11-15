<?php

namespace Modules\WorkflowPacks\Console;

use Illuminate\Console\Command;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Scheduler;

/**
 * php artisan workflowpacks:engine-tick     wakes the tickets of the internal flows whose wait is over (one indexed
 *                                           query, at most 200 per minute). Run every minute by FreeScout's scheduler
 *                                           when the internal engine is on; does nothing when it is off.
 */
class EngineTickCommand extends Command
{
    protected $signature = 'workflowpacks:engine-tick';

    protected $description = 'WorkflowPacks: wake the tickets of the internal flows whose wait is over';

    public function handle()
    {
        if (!Engine::enabled()) {
            return 0;
        }
        $this->line((new Scheduler())->tick().' ticket(s) woken.');
        // flows applied to the tickets already there: one batch of each due job
        $this->line((new \Modules\WorkflowPacks\Services\Engine\Reapply())->tick().' ticket(s) of re-applied flows.');

        return 0;
    }
}
