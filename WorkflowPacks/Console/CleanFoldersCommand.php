<?php

namespace Modules\WorkflowPacks\Console;

use Illuminate\Console\Command;
use Modules\WorkflowPacks\Services\Imap\Retention;

/**
 * php artisan workflowpacks:clean-folders     automatic clean-up of the managed e-mail folders ("[WP] …"): the
 *                                             e-mails older than the delay set for their folder go to the trash
 *                                             (or are deleted). Run every 15 minutes by FreeScout's scheduler.
 */
class CleanFoldersCommand extends Command
{
    protected $signature = 'workflowpacks:clean-folders';

    protected $description = 'WorkflowPacks: delete the old e-mails of the managed e-mail folders, as set for each folder';

    public function handle()
    {
        $total = (new Retention())->run(function ($line) {
            $this->line($line);
        });
        $this->info($total['moved'].' moved to the trash, '.$total['deleted'].' deleted, '.$total['errors'].' error(s).');
        // once a day: current folders of tickets removed without FreeScout's action
        $pruned = \Modules\WorkflowPacks\Services\Imap\TicketFolders::prune();
        if ($pruned) {
            $this->line($pruned.' current folder(s) of deleted tickets removed.');
        }

        return $total['errors'] ? 1 : 0;
    }
}
