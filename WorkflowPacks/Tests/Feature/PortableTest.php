<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Resource;
use Modules\WorkflowPacks\Services\Export\Exporter;
use Modules\WorkflowPacks\Services\Plan\Importer;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/** Pack v2: mailbox selectors evaluated locally, logical resources mapped by key, portable export. */
class PortableTest extends TestCase
{
    protected function mailboxWith($email, $name)
    {
        $mailbox = new \App\Mailbox();
        $mailbox->name = $name;
        $mailbox->email = $email;
        $mailbox->save();

        return $mailbox;
    }

    protected function v2(array $extra = [], array $rules = null)
    {
        return $this->pack($rules ?: [$this->rule('r1', ['settings' => ['apply_to_prev' => false, 'max_executions' => 1]])], $extra + ['version' => 2]);
    }

    public function testSelectorIsEvaluatedLocallyAndOutsideMailboxesAreNotTargeted()
    {
        $u = self::uid();
        $a = $this->mailboxWith('contact-'.$u.'@example.com', 'Contact');
        $b = $this->mailboxWith('perso-'.$u.'@other.test', 'Perso');
        $admin = $this->user([], true);
        $pack = $this->v2(['mailboxes' => ['select' => 'match', 'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '@example.com']]]]);

        // step 2: the matching mailbox is ticked, the other one shown as not matching
        $page = $this->actingAs($admin)->post(route('workflowpacks.import.configure'), ['pack_json' => json_encode($pack)]);
        $page->assertStatus(200);
        $this->assertSame(1, preg_match('/value="'.$a->id.'"\s+checked/', $page->getContent()), 'matching mailbox ticked');
        $this->assertSame(0, preg_match('/value="'.$b->id.'"\s+checked/', $page->getContent()), 'other mailbox not ticked');
        $this->seeIn($page, '@example.com');

        // forced anyway: "outside the selector", nothing created there, no acknowledgement needed
        list($plan, $result) = $this->import($pack, [$a, $b], $admin);
        $this->assertSame(['r1' => [$a->id => 'create', $b->id => 'not_selected']], $this->statuses($plan));
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertCount(1, $this->workflows($a));
        $this->assertCount(0, $this->workflows($b));
    }

    public function testSelectorNeverReachesAMailboxWithoutTheWorkflowsPermission()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $pack = $this->v2(['mailboxes' => ['select' => 'all']]);
        $page = $this->actingAs($agent)->post(route('workflowpacks.import.configure'), ['pack_json' => json_encode($pack)]);
        $this->assertStringNotContainsString('value="'.$b->id.'"', $page->getContent());
        list($plan, $result) = $this->import($pack, [$a, $b], $agent, [], ['ack_blocked' => true]);
        $this->assertSame('denied', $plan['rules']['r1']['items'][$b->id]['status']);
        $this->assertCount(0, $this->workflows($b));
    }

    public function testResourceIsMappedByKeyStoredAndReused()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $billing = $this->user([$a]);
        $pack = $this->v2(['resources' => [['key' => 'billing-user', 'type' => 'user', 'name' => 'Comptabilité']]],
            [$this->rule('r1', ['actions' => [['type' => 'assign', 'value' => '{{resource.billing-user}}']]])]);

        // not mapped: the rule is blocked (never imported without its action)
        $plan = $this->plan($pack, [$a], $admin);
        $this->assertSame('blocked', $plan['rules']['r1']['items'][$a->id]['status']);
        $this->assertStringContainsString('billing-user', implode(' ', $plan['rules']['r1']['items'][$a->id]['reasons']));

        $plan = (new \Modules\WorkflowPacks\Services\Plan\Planner())->plan($this->validated($pack), [$a->id], [], $admin, ['billing-user' => (string) $billing->id]);
        $result = (new Importer())->apply($plan, [], [], $admin);
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame($billing->id, $this->workflows($a)->first()->actions[0][0]['value'], 'local id written as an integer');
        $mapping = Resource::where('pack_key', 'test-pack')->where('resource_key', 'billing-user')->first();
        $this->assertSame((int) $billing->id, (int) $mapping->local_id);
        $this->assertFalse((bool) $mapping->created_by_pack);

        // next import: the mapping is reused without asking, nothing changes
        $again = $this->plan($pack, [$a], $admin);
        $this->assertSame('mapped', $again['resources']['billing-user']['status']);
        $this->assertSame('unchanged', $again['rules']['r1']['items'][$a->id]['status']);
    }

    public function testAResourceWithTheSameNameIsOnlySuggestedNeverAdopted()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $homonym = $this->user([$a]);
        $homonym->first_name = 'Comptabilité';
        $homonym->last_name = '';
        $homonym->save();
        $pack = $this->v2(['resources' => [['key' => 'billing-user', 'type' => 'user', 'name' => 'Comptabilité']]],
            [$this->rule('r1', ['actions' => [['type' => 'assign', 'value' => '{{resource.billing-user}}']]])]);
        $plan = $this->plan($pack, [$a], $admin);
        $this->assertContains((int) $homonym->id, $plan['resources']['billing-user']['suggested']);
        $this->assertSame('unmapped', $plan['resources']['billing-user']['status'], 'same name: suggestion only');
        $this->assertSame('blocked', $plan['rules']['r1']['items'][$a->id]['status']);
    }

    public function testForgedOrForbiddenResourceIdsAreRefused()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $outsider = $this->user([$b]);
        $pack = $this->v2(['resources' => [['key' => 'u', 'type' => 'user']]], [$this->rule('r1', ['actions' => [['type' => 'assign', 'value' => '{{resource.u}}']]])]);
        foreach ([(string) $outsider->id, '999999', 'abc'] as $forged) {
            $plan = (new \Modules\WorkflowPacks\Services\Plan\Planner())->plan($this->validated($pack), [$a->id], [], $agent, ['u' => $forged]);
            $this->assertSame(['WP-RES-09'], array_column($plan['problems']->all(), 'code'), $forged);
            $this->assertFalse((new Importer())->apply($plan, [], ['ack_blocked' => true], $agent)['ok']);
        }
        $this->assertCount(0, $this->workflows($a));
        $this->assertSame(0, Resource::where('pack_key', 'test-pack')->count());
    }

    public function testTagResourceWithoutTheTagsModuleBlocksWithAnExplicitMessage()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        \App\Module::setActive('tags', false);
        \App\Module::clearModulesCache();
        $pack = $this->v2(['resources' => [['key' => 'security-tag', 'type' => 'tag', 'name' => 'Sécurité', 'on_missing' => 'create']]],
            [$this->rule('r1', ['actions' => [['type' => 'note', 'value' => ['body' => '{{resource.security-tag}}']]]])]);
        $plan = $this->plan($pack, [$a], $admin);
        $this->assertSame('unavailable', $plan['resources']['security-tag']['status']);
        $this->assertSame('blocked', $plan['rules']['r1']['items'][$a->id]['status']);
        $this->assertStringContainsString('tags', implode(' ', $plan['rules']['r1']['items'][$a->id]['reasons']));
    }

    public function testResourceMappingsAreRolledBackWithTheImport()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $billing = $this->user([$a]);
        $pack = $this->v2(['resources' => [['key' => 'billing-user', 'type' => 'user']]],
            [$this->rule('r1', ['actions' => [['type' => 'assign', 'value' => '{{resource.billing-user}}']]]), $this->rule('r2')]);
        Workflow::saving(function () {
            static $n = 0;
            if (++$n === 2) {
                throw new \RuntimeException('panne simulée');
            }
        });
        $plan = (new \Modules\WorkflowPacks\Services\Plan\Planner())->plan($this->validated($pack), [$a->id], [], $admin, ['billing-user' => (string) $billing->id]);
        $result = (new Importer())->apply($plan, [], [], $admin);
        Workflow::flushEventListeners();
        $this->assertFalse($result['ok']);
        $this->assertCount(0, $this->workflows($a));
        $this->assertSame(0, Resource::where('pack_key', 'test-pack')->count(), 'no mapping kept');
    }

    public function testExportKeepsThePortableIntentionAndTurnsALocalIdIntoAResource()
    {
        $u = self::uid();
        $a = $this->mailboxWith('contact-'.$u.'@example.com', 'Contact');
        $admin = $this->user([], true);
        $billing = $this->user([$a]);
        $selector = ['select' => 'match', 'combine' => 'any', 'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '@example.com']]];
        $pack = $this->v2(['mailboxes' => $selector, 'resources' => [['key' => 'billing-user', 'type' => 'user', 'name' => 'Comptabilité']]],
            [$this->rule('r1', ['actions' => [['type' => 'assign', 'value' => '{{resource.billing-user}}']], 'x-visual' => ['nodes' => ['c1' => ['x' => 10]]]])]);
        $plan = (new \Modules\WorkflowPacks\Services\Plan\Planner())->plan($this->validated($pack), [$a->id], [], $admin, ['billing-user' => (string) $billing->id]);
        $this->assertTrue((new Importer())->apply($plan, [], [], $admin)['ok']);

        // rules from the pack: selector, resources and x-visual kept (never a frozen list of addresses)
        $export = (new Exporter())->exportWorkflows($this->workflows($a), ['key' => 'copie', 'name' => 'Copie'], []);
        $this->assertSame(2, $export['pack']['version']);
        $this->assertSame($selector, $export['pack']['workflows'][0]['mailboxes']);
        $this->assertSame('{{resource.billing-user}}', $export['pack']['workflows'][0]['actions'][0]['value']);
        $this->assertSame(['nodes' => ['c1' => ['x' => 10]]], $export['pack']['workflows'][0]['x-visual']);
        $this->assertSame(['billing-user'], array_column($export['pack']['resources'], 'key'));
        $this->validated($export['pack']);

        // a plain rule: the user id becomes a logical resource only when asked
        $wf = new Workflow();
        $wf->mailbox_id = $a->id;
        $wf->name = 'Locale '.$u;
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]];
        $wf->actions = [[['type' => 'assign', 'value' => $billing->id]]];
        $wf->save();
        $plain = (new Exporter())->exportWorkflows(collect([$wf]), ['key' => 'k', 'name' => 'N'], []);
        $this->assertSame(1, $plain['pack']['version']);
        $this->assertSame($billing->id, $plain['pack']['workflows'][0]['actions'][0]['value']);
        $asked = (new Exporter())->exportWorkflows(collect([$wf]), ['key' => 'k', 'name' => 'N'], [
            ['text' => (string) $billing->id, 'variable' => 'resource.billing-user', 'type' => 'user', 'label' => 'billing-user'],
        ]);
        $this->assertSame(2, $asked['pack']['version']);
        $this->assertSame('{{resource.billing-user}}', $asked['pack']['workflows'][0]['actions'][0]['value']);
        $this->validated($asked['pack']);
    }

    public function testThePreviewShowsResourcesAndRisks()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $billing = $this->user([$a]);
        $json = json_encode($this->v2(['resources' => [['key' => 'billing-user', 'type' => 'user']]],
            [$this->rule('r1', ['actions' => [['type' => 'assign', 'value' => '{{resource.billing-user}}'], ['type' => 'delete']]])]));
        $page = $this->actingAs($admin)->post(route('workflowpacks.import.preview'), [
            'pack_b64' => base64_encode($json), 'mailboxes' => [$a->id], 'resources' => ['billing-user' => (string) $billing->id],
        ]);
        $page->assertStatus(200);
        $this->seeIn($page, 'wp-resources');
        $this->seeIn($page, 'wp-risk-danger');
        $this->seeIn($page, 'name="resources[billing-user]" value="'.$billing->id.'"');
    }
}
