<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Activity;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Export\Exporter;
use Modules\WorkflowPacks\Services\Export\Grouper;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Workflows\Bridge;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/** "Group into a pack": rules linked to a new or existing pack where they are, exported with the changes made. */
class GroupPackTest extends TestCase
{
    protected function fp($workflow)
    {
        return Fingerprint::of(Bridge::attributesOf($workflow->fresh()), (array) Bridge::config('json_value_actions'));
    }

    protected function localRule($mailbox, $name, $subject = 'commande')
    {
        $wf = new Workflow();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = $name;
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => $subject]]];
        $wf->actions = [[['type' => 'status', 'value' => '3']]];
        $wf->save();

        return $wf;
    }

    protected function group($workflows, array $target, $user, array $replacements = [])
    {
        $composed = (new Grouper())->compose(collect($workflows), $target, $replacements, $user);
        $this->assertSame([], $composed['errors']);
        $result = (new Grouper())->apply($composed, $user);
        $this->assertTrue($result['ok'], $result['error']);

        return [$composed, $result];
    }

    protected function source($key)
    {
        return json_decode(Pack::where('pack_key', $key)->first()->source, true);
    }

    public function testExportOfAChangedPackRuleGivesItsCurrentVersion()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $wf = $this->workflows($a)->first();
        $wf->name = 'Règle r1 modifiée';
        $wf->save();
        $result = (new Exporter())->exportWorkflows(collect([$wf]), ['key' => 'k', 'name' => 'N'], [['text' => $a->email, 'variable' => 'mailbox.email']]);
        $rule = $result['pack']['workflows'][0];
        $this->assertSame('Règle r1 modifiée', $rule['name']);
        $this->assertSame('r1', $rule['key'], 'same key as in its pack');
        $this->assertStringContainsString('{{mailbox.email}}', json_encode($rule, JSON_UNESCAPED_SLASHES));
    }

    public function testNewPackLinksTheRulesWhereTheyAreAndAnImportChangesNothing()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1'), $this->rule('r2')]), [$a], $admin);
        $changed = $this->workflows($a)->first();
        $changed->name = 'Règle r1 modifiée';
        $changed->save();
        $this->localRule($a, 'Locale');
        $all = $this->workflows($a);
        $before = $all->mapWithKeys(function ($wf) {
            return [$wf->id => $this->fp($wf)];
        })->all();

        list($composed, $result) = $this->group($all, ['mode' => 'new', 'key' => 'mon-pack', 'name' => 'Mon pack', 'pack_version' => '1.0.0', 'description' => '', 'requires' => []],
            $admin, [['text' => $a->email, 'variable' => 'mailbox.email']]);
        $this->assertSame([], $composed['warnings']);
        $this->assertSame(3, $result['counts']['linked']);
        $this->assertSame(2, $result['counts']['moved_pack']);
        $this->assertSame(['test-pack'], $result['removed_packs'], 'the old pack has no rule left');

        // the rules themselves are untouched, and all linked to the new pack
        $this->assertCount(3, $this->workflows($a));
        foreach ($this->workflows($a) as $wf) {
            $this->assertSame($before[$wf->id], $this->fp($wf));
        }
        $this->assertSame(3, Link::where('pack_key', 'mon-pack')->count());
        $this->assertSame(0, Link::where('pack_key', 'test-pack')->count());
        $this->assertNull(Pack::where('pack_key', 'test-pack')->first());

        // the pack holds the current versions; importing it again here: nothing to do
        $source = $this->source('mon-pack');
        $this->assertContains('Règle r1 modifiée', array_column($source['workflows'], 'name'));
        $this->assertContains('Locale', array_column($source['workflows'], 'name'));
        $plan = $this->plan($source, [$a], $admin);
        foreach ($this->statuses($plan) as $key => $byMailbox) {
            $this->assertSame('unchanged', $byMailbox[$a->id], $key);
        }
        $this->assertSame(1, Activity::where('kind', 'group')->count());
    }

    public function testAddingToAnExistingPackKeepsItsRulesAndRaisesItsVersion()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1'), $this->rule('r2')]), [$a, $b], $admin);
        $r1a = $this->workflows($a)->first();
        $r1a->name = 'Règle r1 modifiée';
        $r1a->save();
        $local = $this->localRule($a, 'Locale');

        list($composed) = $this->group([$r1a, $local], ['mode' => 'existing', 'pack_key' => 'test-pack', 'pack_version' => ''],
            $admin, [['text' => $a->email, 'variable' => 'mailbox.email']]);
        $states = array_column($composed['assign'], 'state', 'name');
        $this->assertSame(['Règle r1 modifiée' => 'updated', 'Locale' => 'new'], $states);

        $pack = Pack::where('pack_key', 'test-pack')->first();
        $this->assertSame('1.0.1', $pack->pack_version);
        $source = $this->source('test-pack');
        $this->assertSame(['r1', 'r2', 'locale'], array_column($source['workflows'], 'key'));
        $this->assertSame('Règle r1 modifiée', $source['workflows'][0]['name']);

        // A: nothing to do; B (rule not changed there): the pack now gives the changed version, offered as an update
        $statuses = $this->statuses($this->plan($source, [$a, $b], $admin));
        $this->assertSame('unchanged', $statuses['r1'][$a->id]);
        $this->assertSame('unchanged', $statuses['r2'][$a->id]);
        $this->assertSame('unchanged', $statuses['locale'][$a->id]);
        $this->assertSame('update', $statuses['r1'][$b->id]);
        $this->assertSame('unchanged', $statuses['r2'][$b->id]);
    }

    public function testTheSameRuleInTwoMailboxesIsOneRuleOfThePack()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $one = $this->localRule($a, 'Commandes');
        $two = $this->localRule($b, 'Commandes');
        $other = $this->localRule($b, 'Autre', 'retour');

        list($composed) = $this->group([$one, $two, $other], ['mode' => 'new', 'key' => 'commun', 'name' => 'Commun', 'pack_version' => '1.0.0', 'description' => '', 'requires' => []], $admin);
        $this->assertSame(['new', 'joined', 'new'], array_values(array_column($composed['assign'], 'state')));
        $this->assertCount(2, $this->source('commun')['workflows']);
        $this->assertSame(2, Link::where('pack_key', 'commun')->where('workflow_key', 'commandes')->count());
    }

    public function testAnAgentCannotChangeAPackUsedInAMailboxTheyDoNotManage()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a, $b], $admin);
        $agent = $this->user([$a], false, true);
        $this->assertFalse(Grouper::canModify($agent, Pack::where('pack_key', 'test-pack')->first()));
        $composed = (new Grouper())->compose(collect([$this->localRule($a, 'Locale')]), ['mode' => 'existing', 'pack_key' => 'test-pack', 'pack_version' => ''], [], $agent);
        $this->assertNotEmpty($composed['errors']);
    }

    public function testGroupingThroughThePages()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $wf = $this->localRule($a, 'Locale');
        $input = ['mode' => 'workflows', 'mailbox_id' => 0, 'workflows' => [$wf->id], 'target' => 'new', 'name' => 'Pack des pages', 'key' => ''];

        $form = $this->actingAs($admin)->get(route('workflowpacks.export', ['mailbox_id' => 0, 'all' => 1]));
        $form->assertStatus(200);
        $this->seeIn($form, e(__('workflowpacks::messages.target_new')));
        $this->assertSame(1, preg_match('/name="workflows\[\]" value="'.$wf->id.'"\s+checked/', $form->getContent()), 'rule ticked');

        $preview = $this->actingAs($admin)->post(route('workflowpacks.export.preview'), $input);
        $preview->assertStatus(200);
        $this->seeIn($preview, 'pack-des-pages');
        $this->seeIn($preview, e(__('workflowpacks::messages.group_state_new')));
        $this->seeIn($preview, route('workflowpacks.export.group'));

        $done = $this->actingAs($admin)->post(route('workflowpacks.export.group'), $input);
        $done->assertRedirect(route('workflowpacks.export', ['pack_key' => 'pack-des-pages']));
        $this->assertSame(1, Link::where('pack_key', 'pack-des-pages')->where('workflow_id', $wf->id)->count());

        // the stored pack is downloaded as it is
        $file = $this->actingAs($admin)->post(route('workflowpacks.export.download'), ['mode' => 'pack', 'pack_key' => 'pack-des-pages']);
        $file->assertStatus(200);
        $this->seeIn($file, '"Locale"');
        $this->seeIn($this->actingAs($admin)->get(route('workflowpacks.history', ['kind' => 'group'])), 'Pack des pages');
    }
}
