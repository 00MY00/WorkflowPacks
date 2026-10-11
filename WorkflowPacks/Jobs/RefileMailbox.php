<?php

namespace Modules\WorkflowPacks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WorkflowPacks\Services\History;
use Modules\WorkflowPacks\Services\Imap\Refiler;

/**
 * "File again", in the background: looks at the tickets of a mailbox one page at a time (newest first), queues the
 * filings, then queues itself for the next page. The entry of the history shows the progress, then the result.
 */
class RefileMailbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $mailboxId;
    public $workflowIds;
    public $activityId;
    public $beforeId;
    /** totals of the pages already done: seen, queued, skipped */
    public $totals;

    public function __construct($mailboxId, array $workflowIds, $activityId = null, $beforeId = null, array $totals = [])
    {
        $this->mailboxId = (int) $mailboxId;
        $this->workflowIds = array_map('intval', $workflowIds);
        $this->activityId = $activityId ? (int) $activityId : null;
        $this->beforeId = $beforeId ? (int) $beforeId : null;
        $this->totals = $totals + ['seen' => 0, 'queued' => 0, 'skipped' => 0];
    }

    public function handle()
    {
        $page = (new Refiler())->page($this->mailboxId, $this->workflowIds, $this->beforeId);
        foreach (['seen', 'queued', 'skipped'] as $k) {
            $this->totals[$k] += $page[$k];
        }
        $text = __('workflowpacks::messages.refile_result', ['seen' => $this->totals['seen'], 'queued' => $this->totals['queued'], 'skipped' => $this->totals['skipped']]);
        if ($page['next']) {
            History::update($this->activityId, 'refile', ['message' => $text.' — '.__('workflowpacks::messages.refile_going_on')]);
            self::dispatch($this->mailboxId, $this->workflowIds, $this->activityId, $page['next'], $this->totals);

            return;
        }
        History::update($this->activityId, 'ok', ['message' => $text]);
        \Log::info('[WorkflowPacks] [imap] mailbox '.$this->mailboxId.': file again done, '.$text);
    }
}
