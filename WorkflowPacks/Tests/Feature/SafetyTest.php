<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Access to the module: administrators only (every page and every action checked on the server), CSRF on every form,
 * the engine switched off said plainly, and the module working without the Workflows module.
 */
class SafetyTest extends TestCase
{
    /** Pages of the module (GET), opened by an administrator. */
    const PAGES = ['workflowpacks.flows', 'workflowpacks.flowpack', 'workflowpacks.flowpack.export', 'workflowpacks.untreated', 'workflowpacks.ai',
        'workflowpacks.history', 'workflowpacks.diagnostic'];

    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
    }

    public function testOnlyAdministratorsOpenTheModuleEvenWithTheWorkflowsPermission()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        foreach ([$this->user([$a]), $this->user([$a], false, true)] as $user) {
            foreach (array_merge(self::PAGES, ['workflowpacks.index', 'workflowpacks.separation', 'workflowpacks.separation.backup']) as $page) {
                $this->actingAs($user)->get(route($page))->assertStatus(403);
            }
            $this->actingAs($user)->post(route('workflowpacks.separation.confirm'), ['choice' => 'confirm', 'understood' => 1])->assertStatus(403);
            $this->actingAs($user)->postJson(route('workflowpacks.flows.store'), ['name' => 'x'])->assertStatus(403);
            $this->actingAs($user)->get(route('workflowpacks.backup', ['id' => 1]))->assertStatus(403);
        }
        foreach (self::PAGES as $page) {
            $html = $this->actingAs($admin)->get(route($page))->assertStatus(200)->getContent();
            $this->assertStringNotContainsString('workflowpacks::', strip_tags($html), $page.': every text exists');
        }
        // not logged in: the login page
        \Auth::logout();
        $this->get(route('workflowpacks.index'))->assertStatus(302);
    }

    public function testEveryFormNeedsTheCsrfToken()
    {
        foreach (\Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (strpos($name, 'workflowpacks.') !== 0 || strpos($name, 'workflowpacks.api.') === 0 || $name === 'workflowpacks.mcp') {
                continue;
            }
            if (array_intersect(['POST', 'PUT', 'DELETE'], $route->methods())) {
                $this->assertContains('web', $route->gatherMiddleware(), $name.': CSRF of the "web" group');
                $this->assertContains('auth', $route->gatherMiddleware(), $name.': logged in');
            }
        }
    }

    public function testEngineOffAPageSaysSoInsteadOfAnError()
    {
        $admin = $this->user([], true);
        \Option::set(Engine::OPTION, false);
        $page = $this->actingAs($admin)->get(route('workflowpacks.flows'))->assertStatus(200)->getContent();
        $this->assertStringContainsString(e(__('workflowpacks::messages.engine_off')), $page);
        $this->assertStringContainsString(route('settings', ['section' => 'workflowpacks']), $page);
        $this->actingAs($admin)->postJson(route('workflowpacks.flows.store'), ['name' => 'x'])->assertStatus(409);
        // history and diagnostic stay open
        $this->actingAs($admin)->get(route('workflowpacks.history'))->assertStatus(200);
        $this->actingAs($admin)->get(route('workflowpacks.diagnostic'))->assertStatus(200);
    }

    public function testTheModuleWorksWithoutTheWorkflowsModule()
    {
        $admin = $this->user([], true);
        \App\Module::setActive('workflows', false);
        \App\Module::clearModulesCache();
        Checker::forget();
        try {
            $state = Checker::run(true);
            $this->assertSame('ok', $state['state']);
            $this->assertSame([], array_filter(array_column($state['checks'], 'code'), function ($code) {
                return strpos($code, 'WP-WF-') === 0;
            }), 'no check of Workflows any more');
            foreach (self::PAGES as $page) {
                $this->actingAs($admin)->get(route($page))->assertStatus(200);
            }
            $this->assertSame(0, \Artisan::call('workflowpacks:health'));
        } finally {
            \App\Module::setActive('workflows', true);
            \App\Module::clearModulesCache();
            Checker::forget();
        }
    }
}
