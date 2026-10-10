<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\ImportLog;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/** Permissions, dependencies (Tags), Workflows missing, transactional rollback. */
class SafetyTest extends TestCase
{
    public function testPermissionsAreCheckedForEveryMailboxOnTheServer()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a, $b], false, true);   // may manage workflows…
        \DB::table('mailbox_user')->where('user_id', $agent->id)->where('mailbox_id', $b->id)->delete(); // …but only in A now
        $agent = $agent->fresh();

        $plan = $this->plan($this->pack([$this->rule('r1')]), [$a, $b], $agent);
        $this->assertSame(['r1' => [$a->id => 'create', $b->id => 'denied']], $this->statuses($plan));

        // forged decision for the forbidden mailbox: refused, nothing written
        list(, $result) = $this->import($this->pack([$this->rule('r1')]), [$a, $b], $agent, ['r1' => [$b->id => 'create']], ['ack_blocked' => true]);
        $this->assertFalse($result['ok']);
        $this->assertCount(0, $this->workflows($a));
        $this->assertCount(0, $this->workflows($b));

        list(, $result) = $this->import($this->pack([$this->rule('r1')]), [$a, $b], $agent, [], ['ack_blocked' => true]);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->workflows($a));
        $this->assertCount(0, $this->workflows($b));
    }

    public function testUsersWithoutTheWorkflowsPermissionCannotOpenTheModule()
    {
        $a = $this->mailbox('A');
        $plain = $this->user([$a]);
        $this->actingAs($plain)->get(route('workflowpacks.index'))->assertStatus(403);
        $this->actingAs($plain)->post(route('workflowpacks.import.preview'), ['pack_json' => '{}'])->assertStatus(403);
        $this->actingAs($this->user([$a], false, true))->get(route('workflowpacks.index'))->assertStatus(200);
        $this->actingAs($this->user([$a], false, true))->get(route('workflowpacks.diagnostic'))->assertStatus(403);
        auth()->logout();
        $this->get(route('workflowpacks.index'))->assertStatus(302);
    }

    public function testCsrfIsRequired()
    {
        $admin = $this->user([], true);
        // FreeScout's VerifyCsrfToken is skipped by Laravel when running unit tests: check the route is in the "web" group
        $route = \Route::getRoutes()->getByName('workflowpacks.import.confirm');
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotNull($admin);
    }

    public function testMissingTagsBlocksTheRuleUnlessExplicitlySkipped()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        \App\Module::setActive('tags', false);
        \App\Module::clearModulesCache();
        $pack = $this->pack([
            $this->rule('r1'),
            $this->rule('tagged', ['requires' => ['tags'], 'actions' => [['type' => 'status', 'value' => '2']]]),
        ]);

        $plan = $this->plan($pack, [$a], $admin);
        $this->assertSame('blocked', $plan['rules']['tagged']['items'][$a->id]['status']);
        $this->assertStringContainsString('tags', implode(' ', $plan['rules']['tagged']['items'][$a->id]['reasons']));

        list(, $result) = $this->import($pack, [$a], $admin);
        $this->assertFalse($result['ok'], 'blocked rules must be skipped explicitly');
        $this->assertCount(0, $this->workflows($a));

        list(, $result) = $this->import($pack, [$a], $admin, [], ['ack_blocked' => true]);
        $this->assertTrue($result['ok']);
        $this->assertSame(['Règle r1'], $this->workflows($a)->pluck('name')->all(), 'the Tags rule is not imported without its actions');
    }

    public function testTypesAddedByAnActiveModuleAreAccepted()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        \App\Module::setActive('tags', true);
        \App\Module::clearModulesCache();
        // what a module does to extend Workflows: filter on the catalog
        \Eventy::addFilter('workflows.actions_config', function ($config) {
            $config['tags_test'] = ['title' => 'Tags', 'items' => ['tags_test_add' => ['title' => 'Add tag']]];

            return $config;
        });
        \Modules\WorkflowPacks\Services\Workflows\Bridge::forgetCatalogs();
        $pack = $this->pack([$this->rule('tagged', ['requires' => ['tags'], 'actions' => [['type' => 'tags_test_add', 'value' => ['facture']]]])]);
        list($plan, $result) = $this->import($pack, [$a], $admin);
        $this->assertSame('create', $plan['rules']['tagged']['items'][$a->id]['status']);
        $this->assertTrue($result['ok']);
        $this->assertSame([[['type' => 'tags_test_add', 'value' => ['facture']]]], $this->workflows($a)->first()->actions);
    }

    public function testWorkflowsMissingBlocksWithAnExplicitMessage()
    {
        $admin = $this->user([], true);
        \App\Module::setActive('workflows', false);
        \App\Module::clearModulesCache();
        Checker::forget();
        $this->assertTrue(Checker::blocking());
        $this->assertStringContainsString('WP-WF-01', implode(' ', Checker::blockingReasons()));
        $response = $this->actingAs($admin)->get(route('workflowpacks.import'));
        $response->assertStatus(200);
        $this->seeIn($response, 'WP-WF-01');
        \App\Module::setActive('workflows', true);
        \App\Module::clearModulesCache();
    }

    public function testAnErrorCancelsTheWholeImport()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $existing = $this->workflows($a)->first()->toArray();
        $links = Link::count();
        $logs = ImportLog::count();
        $total = Workflow::count();

        $saved = 0;
        Workflow::saving(function () use (&$saved) {
            if (++$saved === 3) {
                throw new \RuntimeException('panne simulée');
            }
        });
        $pack = $this->pack([$this->rule('r1', ['name' => 'Renommée']), $this->rule('r2'), $this->rule('r3')]);
        list(, $result) = $this->import($pack, [$a, $b], $admin);
        Workflow::flushEventListeners();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('panne simulée', $result['error']);
        $this->assertSame($total, Workflow::count(), 'no workflow created');
        $this->assertSame($existing, $this->workflows($a)->first()->toArray(), 'updated rule restored');
        $this->assertSame($links, Link::count());
        $this->assertSame($logs, ImportLog::count());
    }

    public function testThePreviewTokenDetectsAChangeBeforeConfirmation()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $json = json_encode($this->pack([$this->rule('r1', ['name' => 'Nouveau nom'])]));
        $plan = $this->plan(json_decode($json, true), [$a], $admin);

        $wf = $this->workflows($a)->first();
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'changé entre-temps']]];
        $wf->save();

        $response = $this->actingAs($admin)->post(route('workflowpacks.import.confirm'), [
            'pack_b64' => base64_encode($json), 'mailboxes' => [$a->id], 'token' => $plan['token'],
        ]);
        $response->assertStatus(200);
        $this->seeIn($response, e(__('workflowpacks::messages.plan_changed')));
        $this->assertSame('Règle r1', $wf->fresh()->name);
    }
}
