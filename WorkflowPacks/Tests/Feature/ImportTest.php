<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/** Variables, defaults, idempotence, updates, manual changes, foreign rules, deleted rules. */
class ImportTest extends TestCase
{
    public function testCreatesInactiveRulesWithTheVariablesOfEachMailbox()
    {
        $a = $this->mailbox('Ventes');
        $b = $this->mailbox('Support');
        $admin = $this->user([], true);
        $pack = $this->pack([$this->rule('r1', ['actions' => [
            ['type' => 'note', 'value' => ['body' => 'Boîte {{mailbox.name}}']],
            ['type' => 'move', 'value' => '{{mailbox.id}}'],
        ]])]);

        list($plan, $result) = $this->import($pack, [$a, $b], $admin);
        $this->assertSame(['r1' => [$a->id => 'create', $b->id => 'create']], $this->statuses($plan));
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(2, $result['counts']['created']);

        foreach ([$a, $b] as $mailbox) {
            $wf = $this->workflows($mailbox)->first();
            $this->assertSame('Règle r1', $wf->name);
            $this->assertFalse((bool) $wf->active, 'new rules are inactive');
            $this->assertFalse((bool) $wf->apply_to_prev, '"apply to previous" off by default, even if the pack says true');
            $this->assertSame([[['type' => 'new_or_reply', 'operator' => 'new']], [
                ['type' => 'subject', 'operator' => 'contains', 'value' => 'facture'],
                ['type' => 'to', 'operator' => 'regex', 'value' => '/^'.preg_quote($mailbox->email, '/').'$/i'],
            ]], $wf->conditions);
            $this->assertSame(['body' => 'Boîte '.e($mailbox->name)], json_decode($wf->actions[0][0]['value'], true));
            $this->assertSame($mailbox->id, $wf->actions[0][1]['value'], 'numeric identifier kept as an integer');
            $this->assertNotNull(Link::where('pack_key', 'test-pack')->where('workflow_key', 'r1')->where('mailbox_id', $mailbox->id)->first());
        }
    }

    public function testOptionsCanActivateAndKeepApplyToPrevious()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        list(, $result) = $this->import($this->pack([$this->rule('r1')]), [$a], $admin, [], ['activate_new' => true, 'allow_apply_to_prev' => true]);
        $this->assertTrue($result['ok']);
        $wf = $this->workflows($a)->first();
        $this->assertTrue((bool) $wf->active);
        $this->assertTrue((bool) $wf->apply_to_prev);
    }

    public function testSecondIdenticalImportCreatesNoDuplicate()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $pack = $this->pack([$this->rule('r1'), $this->rule('r2')]);
        $this->import($pack, [$a], $admin);
        list($plan, $result) = $this->import($pack, [$a], $admin);

        $this->assertSame(['r1' => [$a->id => 'unchanged'], 'r2' => [$a->id => 'unchanged']], $this->statuses($plan));
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['counts']['created']);
        $this->assertCount(2, $this->workflows($a));
        $this->assertSame(2, Link::where('pack_key', 'test-pack')->where('mailbox_id', $a->id)->count());
    }

    public function testUpdateShowsDifferencesAndKeepsTheActiveState()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $wf = $this->workflows($a)->first();
        $wf->active = true;
        $wf->save();

        $changed = $this->rule('r1', ['actions' => [['type' => 'status', 'value' => '3']]]);
        list($plan, $result) = $this->import($this->pack([$changed], ['pack_version' => '1.1.0']), [$a], $admin);
        $item = $plan['rules']['r1']['items'][$a->id];
        $this->assertSame('update', $item['status']);
        $this->assertSame(['actions'], array_keys($item['diff']));
        $this->assertTrue($result['ok']);
        $wf = $wf->fresh();
        $this->assertSame([[['type' => 'status', 'value' => '3']]], $wf->actions);
        $this->assertTrue((bool) $wf->active, 'active state kept without explicit choice');
        $this->assertSame('1.1.0', Link::where('workflow_id', $wf->id)->value('pack_version'));
    }

    public function testExplicitDeactivationOfUpdatedRules()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin, [], ['activate_new' => true]);
        $changed = $this->rule('r1', ['name' => 'Règle renommée']);
        list(, $result) = $this->import($this->pack([$changed]), [$a], $admin, [], ['active_mode' => 'deactivate']);
        $this->assertTrue($result['ok']);
        $wf = $this->workflows($a)->first();
        $this->assertSame('Règle renommée', $wf->name);
        $this->assertFalse((bool) $wf->active);
    }

    public function testManualChangeIsDetectedAndEachChoiceIsRespected()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $wf = $this->workflows($a)->first();
        $local = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'modifié à la main']]];
        $wf->conditions = $local;
        $wf->save();

        $changed = $this->pack([$this->rule('r1', ['actions' => [['type' => 'status', 'value' => '3']]])]);

        // default choice: keep the local version
        list($plan, $result) = $this->import($changed, [$a], $admin);
        $item = $plan['rules']['r1']['items'][$a->id];
        $this->assertSame('conflict', $item['status']);
        $this->assertSame(['keep_local', 'replace', 'skip'], $item['choices']);
        $this->assertSame(['conditions', 'actions'], array_keys($item['diff']));
        $this->assertTrue($result['ok']);
        $this->assertSame($local, $wf->fresh()->conditions);

        // the local version is now the reference: same pack again = update proposed, shown with its differences
        $plan = $this->plan($changed, [$a], $admin);
        $this->assertSame('update', $plan['rules']['r1']['items'][$a->id]['status']);

        // skip: nothing written, the question comes back next time
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'encore']]];
        $wf->save();
        list(, $result) = $this->import($changed, [$a], $admin, ['r1' => [$a->id => 'skip']]);
        $this->assertSame(1, $result['counts']['skipped']);
        $this->assertSame('conflict', $this->plan($changed, [$a], $admin)['rules']['r1']['items'][$a->id]['status']);

        // replace
        list(, $result) = $this->import($changed, [$a], $admin, ['r1' => [$a->id => 'replace']]);
        $this->assertTrue($result['ok']);
        $this->assertSame([[['type' => 'status', 'value' => '3']]], $wf->fresh()->actions);
        $this->assertSame('unchanged', $this->plan($changed, [$a], $admin)['rules']['r1']['items'][$a->id]['status']);
    }

    public function testForeignRuleWithTheSameNameIsNeverModified()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $foreign = new Workflow();
        $foreign->mailbox_id = $a->id;
        $foreign->name = 'Règle r1';
        $foreign->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'local']]];
        $foreign->actions = [[['type' => 'status', 'value' => '1']]];
        $foreign->active = true;
        $foreign->save();
        $before = $foreign->fresh()->toArray();

        list($plan, $result) = $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $this->assertSame('conflict_name', $plan['rules']['r1']['items'][$a->id]['status']);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->workflows($a), 'default: skipped');

        list(, $result) = $this->import($this->pack([$this->rule('r1')]), [$a], $admin, ['r1' => [$a->id => 'create_renamed']]);
        $this->assertTrue($result['ok']);
        $this->assertSame(['Règle r1', 'Règle r1 (pack)'], $this->workflows($a)->pluck('name')->all());
        $this->assertSame($before, $foreign->fresh()->toArray(), 'foreign rule untouched');

        // a choice that is not offered is refused (forged form)
        list(, $result) = $this->import($this->pack([$this->rule('r2', ['name' => 'Règle r1'])]), [$a], $admin, ['r2' => [$a->id => 'replace']]);
        $this->assertFalse($result['ok']);
        $this->assertSame($before, $foreign->fresh()->toArray());
    }

    public function testRuleAbsentFromTheNewFileIsNotDeletedAndDeletedRuleCanBeRecreated()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1'), $this->rule('r2')]), [$a], $admin);
        list($plan, $result) = $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $this->assertTrue($result['ok']);
        $this->assertCount(2, $this->workflows($a), 'r2 is not deleted');

        $this->workflows($a)->first()->delete();
        $plan = $this->plan($this->pack([$this->rule('r1')]), [$a], $admin);
        $item = $plan['rules']['r1']['items'][$a->id];
        $this->assertSame('missing', $item['status']);
        $this->assertSame('skip', $item['default']);
        list(, $result) = $this->import($this->pack([$this->rule('r1')]), [$a], $admin, ['r1' => [$a->id => 'recreate']]);
        $this->assertTrue($result['ok']);
        $this->assertSame(['Règle r2', 'Règle r1'], $this->workflows($a)->pluck('name')->all());
    }

    public function testRequiredParameterAndInvalidValuesBlockTheImport()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $pack = $this->pack([$this->rule('r1', ['actions' => [['type' => 'forward', 'value' => ['to' => '{{param.notify}}', 'body' => '']]]])],
            ['params' => [['key' => 'notify', 'type' => 'email', 'required' => true]]]);

        $plan = $this->plan($pack, [$a], $admin);
        $this->assertSame(['WP-PARAM-10'], array_column($plan['problems']->all(), 'code'));
        $this->assertFalse((new \Modules\WorkflowPacks\Services\Plan\Importer())->apply($plan, [], ['ack_blocked' => true], $admin)['ok']);

        $plan = $this->plan($pack, [$a], $admin, ['notify' => 'pas-une-adresse']);
        $this->assertSame(['WP-PARAM-11'], array_column($plan['problems']->all(), 'code'));

        list($plan, $result) = $this->import($pack, [$a], $admin, [], [], ['notify' => 'alerte@example.test']);
        $this->assertTrue($result['ok']);
        $this->assertSame(['forward'], $plan['rules']['r1']['items'][$a->id]['sensitive']);
        $this->assertSame('alerte@example.test', json_decode($this->workflows($a)->first()->actions[0][0]['value'], true)['to']);

        // unknown user id: refused before anything is written (local reference)
        $bad = $this->pack([$this->rule('r9', ['actions' => [['type' => 'assign', 'value' => 987654]]])], ['key' => 'other']);
        $plan = $this->plan($bad, [$a], $admin);
        $this->assertSame('blocked', $plan['rules']['r9']['items'][$a->id]['status']);
    }
}
