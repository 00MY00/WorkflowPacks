<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Services\ModuleInfo;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Manage › Settings › Workflow Packs (same layout as RefreshGlobal), saved by FreeScout; who updates the module:
 * UpdateGuard when it is installed, else FreeScout's own Modules page (2.0.0: no built-in updater any more).
 */
class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        \App\Module::setActive('updateguard', false);
        \App\Module::clearModulesCache();
        parent::tearDown();
    }

    public function testWithoutUpdateGuardFreeScoutUpdatesTheModuleAndUpdateGuardIsAdvised()
    {
        $admin = $this->user([], true);
        $page = $this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks']));
        $page->assertStatus(200);
        $this->seeIn($page, 'class="subheader"');
        $this->seeIn($page, e(__('workflowpacks::messages.updates_version', ['version' => ModuleInfo::version()])));
        $this->seeIn($page, e(__('workflowpacks::messages.updates_native')));
        $this->seeIn($page, ModuleInfo::UPDATEGUARD_URL);
        $this->assertStringNotContainsString('settings[workflowpacks.auto_update]', $page->getContent(), 'no automatic update switch any more');
        // the same on the diagnostic page
        $this->seeIn($this->actingAs($admin)->get(route('workflowpacks.diagnostic')), e(__('workflowpacks::messages.updates_advice')));
        // the built-in updater is gone
        foreach (['workflowpacks.update_now', 'workflowpacks.check_update', 'workflowpacks.auto_update'] as $route) {
            $this->assertFalse(\Route::has($route), $route);
        }
        $this->assertArrayNotHasKey('workflowpacks:update', \Artisan::all());
    }

    public function testWithUpdateGuardItIsTheOneUpdatingTheModule()
    {
        \App\Module::setActive('updateguard', true);
        \App\Module::clearModulesCache();
        $this->assertTrue(ModuleInfo::updateGuard());
        $page = $this->actingAs($this->user([], true))->get(route('settings', ['section' => 'workflowpacks']));
        $this->seeIn($page, e(__('workflowpacks::messages.updates_guard')));
        $this->assertStringNotContainsString(e(__('workflowpacks::messages.updates_advice')), $page->getContent());
    }

    public function testModuleJsonPointsFreeScoutAndUpdateGuardToTheLatestRelease()
    {
        $json = json_decode(file_get_contents(base_path('Modules/WorkflowPacks/module.json')), true);
        $this->assertSame('https://github.com/00MY00/WorkflowPacks/releases/latest/download/version.txt', $json['latestVersionUrl']);
        $this->assertSame('https://github.com/00MY00/WorkflowPacks/releases/latest/download/WorkflowPacks.zip', $json['latestVersionZipUrl']);
        $this->assertSame('workflowpacks:health', $json['healthCommand']);
        $this->assertSame('backward-compatible', $json['updateGuard']['migrations']);
    }

    public function testNonAdministratorsCannotSeeTheSettings()
    {
        $a = $this->mailbox('A');
        $agent = $this->user([$a], false, true);
        $this->actingAs($agent)->get(route('settings', ['section' => 'workflowpacks']))->assertStatus(403);
    }

    public function testTheHealthCommandSaysWhenTheModuleIsHealthyOrNot()
    {
        $this->assertSame(0, \Artisan::call('workflowpacks:health', ['--json' => true]));
        $out = json_decode(trim(\Artisan::output()), true);
        $this->assertSame('ok', $out['status'], json_encode($out));
        $this->assertSame(['WorkflowPacks', ModuleInfo::version()], [$out['module'], $out['version']]);
        foreach (['files', 'classes', 'views', 'routes', 'assets', 'database'] as $check) {
            $this->assertSame('ok', $out['checks'][$check], $check);
        }
        $this->assertArrayHasKey('workflows', $out['checks'], 'Workflows reported');

        // a migration of the module not applied: unhealthy, and said
        $last = basename(collect(glob(base_path('Modules/WorkflowPacks/Database/Migrations/*.php')))->sort()->last(), '.php');
        $row = (array) \DB::table('migrations')->where('migration', $last)->first();
        \DB::table('migrations')->where('migration', $last)->delete();
        try {
            $this->assertSame(1, \Artisan::call('workflowpacks:health', ['--json' => true]));
            $out = json_decode(trim(\Artisan::output()), true);
            $this->assertSame(['fail', 'fail'], [$out['status'], $out['checks']['database']]);
            $this->assertSame([$last], $out['details']['database']['pending_migrations']);
        } finally {
            \DB::table('migrations')->insert(['migration' => $last, 'batch' => $row['batch'] ?? 1]);
        }
    }
}
