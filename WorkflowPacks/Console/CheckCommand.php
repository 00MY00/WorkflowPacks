<?php

namespace Modules\WorkflowPacks\Console;

use Illuminate\Console\Command;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;

/**
 * php artisan workflowpacks:check                 state of the Workflows module and of WorkflowPacks
 * php artisan workflowpacks:check --json          same, as JSON (used by the updater)
 * php artisan workflowpacks:check --pack=file     also validates the structure of a pack file
 *
 * Exit code: 0 ok / warning, 2 blocking, 1 invalid pack file.
 */
class CheckCommand extends Command
{
    protected $signature = 'workflowpacks:check {--json : JSON output} {--pack= : Path of a pack file to validate}';

    protected $description = 'WorkflowPacks: check that the Workflows module is installed and compatible';

    public function handle()
    {
        $result = Checker::run(true);
        $packProblems = null;
        if ($this->option('pack')) {
            $file = (string) $this->option('pack');
            $json = is_file($file) ? (string) file_get_contents($file) : '';
            $packProblems = (new PackValidator((array) config('workflowpacks.limits')))->validateJson($json)['problems']->lines();
        }

        if ($this->option('json')) {
            $out = $result;
            if ($packProblems !== null) {
                $out['pack_problems'] = $packProblems;
            }
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['checks'] as $check) {
                $this->line(sprintf('%-9s %-11s %s%s', strtoupper($check['state']), $check['code'], $check['label'], $check['detail'] !== '' ? ' — '.$check['detail'] : ''));
            }
            $this->line('');
            $this->line(Problems::t('cmd_state', ['state' => strtoupper($result['state'])]));
            if ($packProblems !== null) {
                $this->line(Problems::t($packProblems ? 'cmd_pack_invalid' : 'cmd_pack_valid'));
                foreach ($packProblems as $line) {
                    $this->line('  '.$line);
                }
            }
        }

        if ($packProblems) {
            return 1;
        }

        return $result['state'] === 'blocking' ? 2 : 0;
    }
}
