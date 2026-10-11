<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Layout;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Tests\TestCase;

/** Diagram: data limited to the allowed mailboxes, live layout per user, rules created / toggled / applied from it. */
class GraphTest extends TestCase
{
    protected function local($mailbox, $name, array $actions, $active = true)
    {
        $class = \Modules\WorkflowPacks\Services\Workflows\Bridge::modelClass();
        $wf = new $class();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = $name;
        $wf->type = 1;
        $wf->apply_to_prev = false;
        $wf->max_executions = 1;
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'devis']]];
        $wf->actions = [$actions];
        $wf->active = $active;
        $wf->save();

        return $wf;
    }

    protected function keys(array $data, $kind = null)
    {
        $out = [];
        foreach ($data['nodes'] as $node) {
            if ($kind === null || $node['kind'] === $kind) {
                $out[] = $node['key'];
            }
        }
        sort($out);

        return $out;
    }

    public function testPageAndDataShowOnlyAllowedMailboxesWithTheirRulesAndDestinations()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $mine = $this->local($a, 'Devis', [['type' => 'status', 'value' => '3'], ['type' => 'assign', 'value' => '-1']]);
        $this->local($b, 'Secret', [['type' => 'delete', 'value' => '']]);

        $this->seeIn($this->actingAs($agent)->get(route('workflowpacks.graph')), 'wpg-config')->assertStatus(200);
        $data = $this->actingAs($agent)->getJson(route('workflowpacks.graph.data'))->assertStatus(200)->json();

        $this->assertSame(['mb:'.$a->id], $this->keys($data, 'mailbox'), 'other mailbox hidden');
        $this->assertSame(['wf:'.$mine->id], $this->keys($data, 'rule'));
        $this->assertSame(['dest:assign:-1', 'dest:status:closed'], $this->keys($data, 'dest'), 'closed folder + unassigned');
        $this->assertStringNotContainsString('Secret', json_encode($data));
        $applies = array_values(array_filter($data['edges'], function ($e) {
            return $e['kind'] === 'applies';
        }));
        $this->assertSame([['wf:'.$mine->id, 'mb:'.$a->id, 'local', true]], array_map(function ($e) {
            return [$e['from'], $e['to'], $e['state'], $e['active']];
        }, $applies));
        $this->assertSame('columns', $data['layout']['settings']['layout'], 'default layout');
    }

    public function testPackRuleIsOneNodeLinkedToEveryMailboxAndShowsLocalChanges()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        list(, $result) = $this->import($this->pack([$this->rule('r1')]), [$a, $b], $admin);
        $this->assertTrue($result['ok'], $result['error']);
        $wfA = $this->workflows($a)->first();
        $wfA->name = 'Changée à la main';
        $wfA->save();

        $data = $this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json();
        $this->assertContains('tpl:test-pack/r1', $this->keys($data, 'rule'));
        $this->assertContains('pack:test-pack', $this->keys($data, 'pack'));
        $this->assertContains('dest:status:pending', $this->keys($data, 'dest'));
        $states = [];
        foreach ($data['edges'] as $e) {
            if ($e['kind'] === 'applies' && $e['from'] === 'tpl:test-pack/r1') {
                $states[$e['to']] = $e['state'];
            }
        }
        $this->assertEquals(['mb:'.$a->id => 'modified', 'mb:'.$b->id => 'synced'], $states);
    }

    public function testEmailFoldersAreAFlowOfFolderBlocksWithTheirRules()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        \App\Option::set(\Modules\WorkflowPacks\Services\Imap\ImapFolders::OPTION_ENABLED, true);
        $one = $this->local($a, 'Factures fournisseurs', [['type' => 'wp_imap_folder', 'value' => 'Factures/Fournisseurs'], ['type' => 'status', 'value' => '3']]);
        $two = $this->local($a, 'Factures clients', [['type' => 'wp_imap_folder', 'value' => 'Factures/Clients']]);

        $data = $this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json();
        $folders = [];
        foreach ($data['nodes'] as $node) {
            if (($node['dest'] ?? '') === 'imap') {
                $folders[$node['label']] = $node;
            }
        }
        $this->assertEqualsCanonicalizing(['[WP] Factures', 'Fournisseurs', 'Clients'], array_keys($folders), 'one block per level, the top one shared');
        $top = $folders['[WP] Factures'];
        $this->assertSame(0, $top['depth']);
        $this->assertSame(1, $folders['Fournisseurs']['depth']);
        $this->assertSame('[WP] Factures › Fournisseurs', $folders['Fournisseurs']['sub']);
        $this->assertEqualsCanonicalizing(['wf:'.$one->id, 'wf:'.$two->id], $top['filed_by']);
        $this->assertSame(['wf:'.$one->id], $folders['Fournisseurs']['filed_by']);
        $edges = array_map(function ($e) {
            return $e['kind'].' '.$e['from'].' > '.$e['to'];
        }, array_filter($data['edges'], function ($e) {
            return in_array($e['kind'], ['sends', 'folder'], true);
        }));
        $this->assertContains('sends wf:'.$one->id.' > '.$top['key'], $edges, 'rule -> top folder');
        $this->assertContains('folder '.$top['key'].' > '.$folders['Fournisseurs']['key'], $edges, 'folder -> sub-folder');
        $this->assertSame('', $top['warn']);

        // option off, or a name the action refuses: still shown, with a warning
        \App\Option::set(\Modules\WorkflowPacks\Services\Imap\ImapFolders::OPTION_ENABLED, false);
        $this->local($a, 'Mauvais nom', [['type' => 'wp_imap_folder', 'value' => 'Fact*']]);
        $data = $this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json();
        $warns = [];
        foreach ($data['nodes'] as $node) {
            if (($node['dest'] ?? '') === 'imap') {
                $warns[$node['label']] = $node['warn'];
            }
        }
        $this->assertStringContainsString('Workflow Packs', $warns['[WP] Factures'], 'option off: said on the block');
        $this->assertNotSame('', $warns['Fact*'], 'invalid name: said on the block');
    }

    public function testSizesOfEnlargedBlocksAreSavedValidatedAndKept()
    {
        $a = $this->mailbox('A');
        $user = $this->user([$a], false, true);
        $url = route('workflowpacks.graph.layout');
        $this->actingAs($user)->postJson($url, ['positions' => ['mb:'.$a->id => [0, 0]], 'sizes' => ['mb:'.$a->id => [420, 120]]])->assertStatus(200);
        $layout = $this->actingAs($user)->getJson(route('workflowpacks.graph.data'))->json()['layout'];
        $this->assertSame(['mb:'.$a->id => [420, 120]], $layout['sizes']);

        // rearranging (replace) keeps the sizes; out of bounds or bad keys refused; null = automatic size again
        $this->actingAs($user)->postJson($url, ['positions' => ['mb:'.$a->id => [50, 50]], 'replace' => true])->assertStatus(200);
        $this->assertSame([420, 120], $this->actingAs($user)->getJson(route('workflowpacks.graph.data'))->json()['layout']['sizes']['mb:'.$a->id]);
        $this->actingAs($user)->postJson($url, ['sizes' => ['mb:'.$a->id => [5000, 120]]])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['sizes' => ['mb:'.$a->id => [420]]])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['sizes' => ['<b>' => [420, 120]]])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['sizes' => ['mb:'.$a->id => null]])->assertStatus(200);
        $this->assertSame([], $this->actingAs($user)->getJson(route('workflowpacks.graph.data'))->json()['layout']['sizes']);
    }

    public function testLayoutIsSavedLiveMergedValidatedAndPerUser()
    {
        $a = $this->mailbox('A');
        $one = $this->user([$a], false, true);
        $two = $this->user([$a], false, true);
        $url = route('workflowpacks.graph.layout');

        $this->actingAs($one)->postJson($url, ['positions' => ['mb:'.$a->id => [10.04, -20]], 'settings' => ['layout' => 'free']])->assertStatus(200);
        $this->actingAs($one)->postJson($url, ['positions' => ['wf:7' => [5, 6]], 'settings' => ['edges' => 'curved', 'dests' => false]])->assertStatus(200);
        $layout = $this->actingAs($one)->getJson(route('workflowpacks.graph.data'))->json()['layout'];
        $this->assertEquals(['mb:'.$a->id => [10, -20], 'wf:7' => [5, 6]], $layout['positions'], 'partial updates merged');
        $this->assertSame(['layout' => 'free', 'edges' => 'curved', 'packs' => true, 'dests' => false], $layout['settings']);

        // refused: unknown key format, bad coordinates, unknown choice (nothing written)
        $this->actingAs($one)->postJson($url, ['positions' => ['<script>' => [1, 2]]])->assertStatus(422);
        $this->actingAs($one)->postJson($url, ['positions' => ['wf:8' => ['x', 2]]])->assertStatus(422);
        $this->actingAs($one)->postJson($url, ['positions' => ['wf:8' => [1e9, 2]]])->assertStatus(422);
        $this->actingAs($one)->postJson($url, ['settings' => ['layout' => 'spiral']])->assertStatus(422);
        $this->assertArrayNotHasKey('wf:8', $this->actingAs($one)->getJson(route('workflowpacks.graph.data'))->json()['layout']['positions']);

        // another user: own layout
        $this->assertSame([], $this->actingAs($two)->getJson(route('workflowpacks.graph.data'))->json()['layout']['positions']);
        $this->assertSame(1, Layout::where('user_id', $one->id)->count());

        // "rearrange": replace removes the old positions
        $this->actingAs($one)->postJson($url, ['positions' => ['wf:9' => [0, 0]], 'replace' => true])->assertStatus(200);
        $this->assertEquals(['wf:9' => [0, 0]], $this->actingAs($one)->getJson(route('workflowpacks.graph.data'))->json()['layout']['positions']);
    }

    public function testRuleCreatedFromTheEditorIsCheckedAndInactive()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $rule = [
            'name'       => 'Devis {{mailbox.name}}',
            'conditions' => ['all' => [['type' => 'subject', 'operator' => 'contains', 'value' => 'devis']]],
            'actions'    => [['type' => 'status', 'value' => '3']],
        ];
        $save = route('workflowpacks.graph.save');

        $ok = $this->actingAs($agent)->postJson($save, ['mailbox_id' => $a->id, 'rule' => $rule])->assertStatus(200)->json();
        $wf = $this->workflows($a)->first();
        $this->assertSame($ok['workflow_id'], (int) $wf->id);
        $this->assertSame('Devis '.$a->name, $wf->name, 'variables resolved for the mailbox');
        $this->assertFalse((bool) $wf->active, 'created inactive');
        $this->assertFalse((bool) $wf->apply_to_prev);

        // same name again, unknown type, forged mailbox: refused, nothing written
        $this->actingAs($agent)->postJson($save, ['mailbox_id' => $a->id, 'rule' => $rule])->assertStatus(422);
        $bad = $rule;
        $bad['name'] = 'Autre';
        $bad['actions'] = [['type' => 'run_shell', 'value' => 'x']];
        $this->assertNotEmpty($this->actingAs($agent)->postJson($save, ['mailbox_id' => $a->id, 'rule' => $bad])->assertStatus(422)->json()['errors']);
        $this->actingAs($agent)->postJson($save, ['mailbox_id' => $b->id, 'rule' => $rule])->assertStatus(422);
        $this->actingAs($agent)->postJson($save, ['mailbox_id' => $b->id, 'workflow_id' => $wf->id, 'rule' => $rule])->assertStatus(422);
        $this->assertCount(1, $this->workflows($a));
        $this->assertCount(0, $this->workflows($b));

        // change: name kept unique, state kept (still inactive)
        $rule['name'] = 'Devis modifié';
        $this->actingAs($agent)->postJson($save, ['mailbox_id' => $a->id, 'workflow_id' => $wf->id, 'rule' => $rule])->assertStatus(200);
        $this->assertSame('Devis modifié', $wf->fresh()->name);
        $this->assertFalse((bool) $wf->fresh()->active);
    }

    public function testToggleAndDetailNeedThePermissionOfTheMailbox()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $mine = $this->local($a, 'Mine', [['type' => 'status', 'value' => '3']], false);
        $other = $this->local($b, 'Other', [['type' => 'status', 'value' => '3']], true);

        $this->actingAs($agent)->postJson(route('workflowpacks.graph.toggle', ['id' => $mine->id]), ['active' => true])->assertStatus(200);
        $this->assertTrue((bool) $mine->fresh()->active);
        $this->actingAs($agent)->postJson(route('workflowpacks.graph.toggle', ['id' => $other->id]), ['active' => false])->assertStatus(422);
        $this->assertTrue((bool) $other->fresh()->active, 'not allowed: unchanged');

        $detail = $this->actingAs($agent)->getJson(route('workflowpacks.graph.rule', ['id' => $mine->id]))->assertStatus(200)->json();
        $this->assertSame('Mine', $detail['rule']['name']);
        $this->actingAs($agent)->getJson(route('workflowpacks.graph.rule', ['id' => $other->id]))->assertStatus(403);
        $this->actingAs($agent)->getJson(route('workflowpacks.graph.catalog', ['mailbox_id' => $b->id]))->assertStatus(403);
        $this->assertArrayHasKey('catalog', $this->actingAs($agent)->getJson(route('workflowpacks.graph.catalog', ['mailbox_id' => $a->id]))->json());
    }

    public function testLinkDrawnToAnotherMailboxCopiesALocalRuleInactive()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $c = $this->mailbox('C');
        $agent = $this->user([$a, $b], false, true);
        $source = $this->local($a, 'Devis', [['type' => 'status', 'value' => '3']]);
        $this->local($b, 'Devis', [['type' => 'status', 'value' => '2']], false);
        $apply = route('workflowpacks.graph.apply', ['id' => $source->id]);

        $this->actingAs($agent)->postJson($apply, ['mailbox_id' => $b->id])->assertStatus(200);
        $copy = $this->workflows($b)->last();
        $this->assertSame('Devis (2)', $copy->name, 'free name in the target mailbox');
        $this->assertFalse((bool) $copy->active);
        $this->assertSame($source->conditions, $copy->conditions);

        $this->actingAs($agent)->postJson($apply, ['mailbox_id' => $a->id])->assertStatus(422);
        $this->actingAs($agent)->postJson($apply, ['mailbox_id' => $c->id])->assertStatus(422);
        $this->assertCount(0, $this->workflows($c));
    }

    public function testLinkDrawnFromAPackRuleImportsOnlyThatRuleWithItsLink()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        list(, $result) = $this->import($this->pack([$this->rule('r1'), $this->rule('r2')]), [$a], $admin);
        $this->assertTrue($result['ok'], $result['error']);
        $r1 = $this->workflows($a)->first();

        $this->actingAs($admin)->postJson(route('workflowpacks.graph.apply', ['id' => $r1->id]), ['mailbox_id' => $b->id])->assertStatus(200);
        $created = $this->workflows($b);
        $this->assertCount(1, $created, 'only the dragged rule');
        $this->assertSame('Règle r1', $created->first()->name);
        $this->assertFalse((bool) $created->first()->active);
        $this->assertTrue(Link::where('mailbox_id', $b->id)->where('workflow_key', 'r1')->where('workflow_id', $created->first()->id)->exists());
        $this->assertStringContainsString($b->name, json_encode($created->first()->actions), '{{mailbox.name}} of the target');
    }
}
