<?php

namespace Modules\WorkflowPacks\Console;

use Illuminate\Console\Command;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Support\Texts;

/**
 * php artisan workflowpacks:check                 state of WorkflowPacks (versions, tables, optional modules)
 * php artisan workflowpacks:check --json          same, as JSON
 * php artisan workflowpacks:check --pack=file     also reads a pack of flows (format workflowpacks-flows), nothing imported
 *
 * Exit code: 0 ok / warning, 2 blocking, 1 invalid pack file.
 */
class CheckCommand extends Command
{
    protected $signature = 'workflowpacks:check {--json : JSON output} {--pack= : Path of a pack file to validate}';

    protected $description = 'WorkflowPacks: state of the module on this instance (and reading of a pack of flows)';

    public function handle()
    {
        $result = Checker::run(true);
        $packProblems = null;
        if ($this->option('pack')) {
            $file = (string) $this->option('pack');
            $json = is_file($file) ? (string) file_get_contents($file) : '';
            $packs = new \Modules\WorkflowPacks\Services\Engine\FlowPack();
            // its parameters are asked at import: read here with a sample value each (the structure is what is checked)
            $sample = [];
            foreach ((array) $packs->read($json)['params'] as $param) {
                $sample[$param['key']] = 'example';
            }
            $read = $packs->read($json, $sample);
            $problems = array_map('strval', (array) $read['problems']);
            // the problems of each flow (a block without a required field, a broken link…)
            foreach ((array) $read['flows'] as $flow) {
                foreach ((array) ($flow['problems'] ?? []) as $p) {
                    $problems[] = (string) ($flow['name'] ?? '?').' : '.(string) ($p['message'] ?? '');
                }
            }
            $packProblems = $read['ok'] && !$problems ? [] : ($problems ?: [Texts::t('cmd_pack_invalid')]);
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
            $this->line(Texts::t('cmd_state', ['state' => strtoupper($result['state'])]));
            if ($packProblems !== null) {
                $this->line(Texts::t($packProblems ? 'cmd_pack_invalid' : 'cmd_pack_valid'));
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
