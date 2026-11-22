<?php

namespace Modules\WorkflowPacks\Console;

use Illuminate\Console\Command;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\ModuleInfo;

/**
 * php artisan workflowpacks:health [--json]
 *
 * Health of the installed module, for UpdateGuard (run after an update, after a restore and at the end of its
 * observation; "healthCommand" of module.json) and for the administrator. Read only, quick, no network.
 * Exit code 0 = healthy, 1 = something of the module itself is broken (files, classes, views, routes, assets,
 * tables, migrations). The state of the instance (workflowpacks:check) is reported, never makes it fail.
 */
class HealthCommand extends Command
{
    protected $signature = 'workflowpacks:health {--json : One JSON object (UpdateGuard)}';

    protected $description = 'WorkflowPacks: health of the installed module (files, classes, views, routes, assets, database)';

    const FILES = ['module.json', 'Providers/WorkflowPacksServiceProvider.php', 'Http/routes.php', 'Resources/views/layout.blade.php',
        'Resources/views/flows.blade.php', 'Public/js/flows/editor.js', 'Public/js/flows/icons.js', 'Public/css/flows.css'];
    const CLASSES = ['Services\Compat\Checker', 'Services\Engine\Engine', 'Services\Engine\Listener', 'Services\Engine\Scheduler',
        'Services\Engine\Reapply', 'Services\Engine\FlowPack', 'Services\Engine\TempFlows', 'Services\Engine\FlowReport',
        'Services\Ai\AgentTools', 'Services\Imap\ImapFolders', 'Services\Imap\TicketFolders', 'Services\History', 'Services\Separation',
        'Jobs\FileIntoImapFolder'];
    const VIEWS = ['flows', 'reapply', 'untreated', 'ai', 'history', 'diagnostic', 'settings', 'separation', 'layout'];
    const ROUTES = ['workflowpacks.index', 'workflowpacks.flows', 'workflowpacks.flows.data', 'workflowpacks.history', 'workflowpacks.diagnostic',
        'workflowpacks.flowpack', 'workflowpacks.ai', 'workflowpacks.api.me', 'workflowpacks.mcp',
        // linked to by other modules (RefreshGlobal): never removed without them knowing
        'workflowpacks.graph'];

    public function handle()
    {
        $checks = [];
        $details = [];
        $base = base_path('Modules/'.ModuleInfo::MODULE);

        $missing = array_values(array_filter(self::FILES, function ($f) use ($base) { return !is_file($base.'/'.$f); }));
        $checks['files'] = $missing ? 'fail' : 'ok';
        $details['files'] = $missing;

        $missing = array_values(array_filter(self::CLASSES, function ($c) { return !class_exists('Modules\\WorkflowPacks\\'.$c); }));
        $checks['classes'] = $missing ? 'fail' : 'ok';
        $details['classes'] = $missing;

        $missing = array_values(array_filter(self::VIEWS, function ($v) { return !view()->exists('workflowpacks::'.$v); }));
        $checks['views'] = $missing ? 'fail' : 'ok';
        $details['views'] = $missing;

        $missing = array_values(array_filter(self::ROUTES, function ($r) { return !\Route::has($r); }));
        $checks['routes'] = $missing ? 'fail' : 'ok';
        $details['routes'] = $missing;

        // public/modules/workflowpacks: link made by FreeScout to Public/ (scripts and styles of the pages)
        $checks['assets'] = is_file(public_path('modules/'.ModuleInfo::ALIAS.'/js/flows/editor.js')) ? 'ok' : 'fail';

        try {
            $missing = array_values(array_filter(ModuleInfo::TABLES, function ($t) { return !\Schema::hasTable($t); }));
            $files = array_map(function ($f) { return basename($f, '.php'); }, glob($base.'/Database/Migrations/*.php') ?: []);
            $done = \Schema::hasTable('migrations') ? \DB::table('migrations')->whereIn('migration', $files ?: [''])->pluck('migration')->all() : [];
            $pending = array_values(array_diff($files, $done));
            $checks['database'] = $missing || $pending ? 'fail' : 'ok';
            $details['database'] = ['missing_tables' => $missing, 'pending_migrations' => $pending];
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
            $details['database'] = $e->getMessage();
        }

        // reported only (see above)
        try {
            $checks['instance'] = Checker::run(true)['state'];
        } catch (\Throwable $e) {
            $checks['instance'] = 'unknown';
        }

        $failed = array_keys(array_filter($checks, function ($state, $key) { return $key !== 'instance' && $state === 'fail'; }, ARRAY_FILTER_USE_BOTH));
        $status = $failed ? 'fail' : 'ok';
        if ($this->option('json')) {
            $this->line(json_encode(['status' => $status, 'module' => ModuleInfo::MODULE, 'version' => ModuleInfo::version(),
                'checks' => $checks, 'details' => array_filter($details)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($checks as $name => $state) {
                $detail = $details[$name] ?? null;
                $this->line(sprintf('%-6s %-10s %s', strtoupper($state), $name, $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''));
            }
            $this->line(ModuleInfo::MODULE.' '.ModuleInfo::version().': '.strtoupper($status));
        }

        return $failed ? 1 : 0;
    }
}
