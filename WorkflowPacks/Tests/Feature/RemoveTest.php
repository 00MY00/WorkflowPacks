<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Backup;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Tests\TestCase;

/** "Remove the pack": preview, confirmation, guards (manual rules, permissions, changes), backup, import again. */
class RemoveTest extends TestCase
{
    protected function installed(array $mailboxes, $admin)
    {
        list(, $result) = $this->import($this->pack([$this->rule('r1'), $this->rule('r2')]), $mailboxes, $admin);
        $this->assertTrue($result['ok'], $result['error']);
    }

    protected function manual($mailbox, $name)
    {
        $class = \Modules\WorkflowPacks\Services\Workflows\Bridge::modelClass();
        $wf = new $class();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = $name;
        $wf->type = 1;
        $wf->apply_to_prev = false;
        $wf->max_executions = 1;
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]];
        $wf->actions = [[['type' => 'status', 'value' => '3']]];
        $wf->active = true;
        $wf->save();

        return $wf;
    }

    protected function confirm($admin, array $extra = [])
    {
        $page = $this->actingAs($admin)->get(route('workflowpacks.remove', ['key' => 'test-pack']));
        preg_match('/name="token" value="([a-f0-9]+)"/', $page->getContent(), $m);

        return $this->actingAs($admin)->post(route('workflowpacks.remove.confirm', ['key' => 'test-pack']), $extra + ['token' => $m[1] ?? '']);
    }

    public function testUnchangedRulesAreDeletedModifiedOnesKeptAndManualRulesNeverTouched()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $this->installed([$a, $b], $admin);
        $mine = $this->manual($a, 'Ma règle à moi');
        $changed = $this->workflows($a)->where('name', 'Règle r1')->first();
        $changed->name = 'Règle r1 modifiée';
        $changed->save();
        $gone = $this->workflows($b)->where('name', 'Règle r2')->first();
        $gone->delete();

        $preview = $this->actingAs($admin)->get(route('workflowpacks.remove', ['key' => 'test-pack']))->assertStatus(200);
        $this->seeIn($preview, e(__('workflowpacks::messages.rm_modified')));
        $this->seeIn($preview, e(__('workflowpacks::messages.rm_missing')));
        $this->assertStringNotContainsString('Ma règle à moi', $preview->getContent());

        $result = $this->confirm($admin)->assertStatus(200);
        $this->seeIn($result, e(__('workflowpacks::messages.remove_result', ['deleted' => 2, 'kept' => 1, 'cleaned' => 1])));
        $this->assertSame(['Règle r1 modifiée', 'Ma règle à moi'], $this->workflows($a)->pluck('name')->all(), 'only the unchanged rules deleted');
        $this->assertCount(0, $this->workflows($b));
        $this->assertSame(1, Link::where('pack_key', 'test-pack')->count(), 'the kept rule stays linked to the pack');
        $this->assertNotNull(Pack::where('pack_key', 'test-pack')->first(), 'pack still listed (one rule kept)');

        // second pass, modified ones too: the pack disappears
        $this->confirm($admin, ['delete_modified' => '1'])->assertStatus(200);
        $this->assertSame(['Ma règle à moi'], $this->workflows($a)->pluck('name')->all());
        $this->assertNull(Pack::where('pack_key', 'test-pack')->first());
        $this->assertSame(0, Link::where('pack_key', 'test-pack')->count());
    }

    public function testMailboxesOfOthersAndUntickedMailboxesAreLeftAlone()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $this->installed([$a, $b], $admin);
        $agent = $this->user([$a], false, true);

        $preview = $this->actingAs($agent)->get(route('workflowpacks.remove', ['key' => 'test-pack']))->assertStatus(200);
        $this->assertStringNotContainsString($b->name, $preview->getContent(), 'mailbox of others not even named');
        $this->confirm($agent)->assertStatus(200);
        $this->assertCount(0, $this->workflows($a));
        $this->assertCount(2, $this->workflows($b), 'not allowed: untouched');

        // nothing ticked means nothing (never "everything")
        $page = $this->actingAs($admin)->get(route('workflowpacks.remove', ['key' => 'test-pack']).'?mailboxes_sent=1');
        preg_match('/name="token" value="([a-f0-9]+)"/', $page->getContent(), $m);
        $this->actingAs($admin)->post(route('workflowpacks.remove.confirm', ['key' => 'test-pack']), ['token' => $m[1], 'mailboxes_sent' => '1'])->assertStatus(200);
        $this->assertCount(2, $this->workflows($b));
    }

    public function testAChangeAfterThePreviewCancelsTheRemoval()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->installed([$a], $admin);
        $page = $this->actingAs($admin)->get(route('workflowpacks.remove', ['key' => 'test-pack']));
        preg_match('/name="token" value="([a-f0-9]+)"/', $page->getContent(), $m);
        $wf = $this->workflows($a)->first();
        $wf->name = 'Changée entre-temps';
        $wf->save();

        $again = $this->actingAs($admin)->post(route('workflowpacks.remove.confirm', ['key' => 'test-pack']), ['token' => $m[1]]);
        $this->seeIn($again, e(__('workflowpacks::messages.plan_changed')));
        $this->assertCount(2, $this->workflows($a), 'nothing deleted');
        $this->assertSame(0, Backup::where('pack_key', 'test-pack')->count());
        $this->actingAs($admin)->post(route('workflowpacks.remove.confirm', ['key' => 'test-pack']), ['token' => 'forged'])->assertStatus(200);
        $this->assertCount(2, $this->workflows($a));
    }

    public function testTheBackupGivesBackThePackAndTheDeletedRules()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->installed([$a], $admin);
        $this->confirm($admin)->assertStatus(200);
        $backup = Backup::where('pack_key', 'test-pack')->latest('id')->first();
        $this->assertNotNull($backup);

        $pack = $this->actingAs($admin)->get(route('workflowpacks.backup', ['id' => $backup->id, 'part' => 'pack']))->assertStatus(200);
        $this->assertStringContainsString('attachment', $pack->headers->get('Content-Disposition'));
        $json = json_decode($pack->getContent(), true);
        $this->assertSame('test-pack', $json['key']);
        $full = json_decode($this->actingAs($admin)->get(route('workflowpacks.backup', ['id' => $backup->id]))->getContent(), true);
        $this->assertSame(['r1', 'r2'], array_column($full['removed'], 'rule'));
        $this->assertSame('Règle r1', $full['removed'][0]['workflow']['name']);

        // only its author or an administrator
        $other = $this->user([$a], false, true);
        $this->actingAs($other)->get(route('workflowpacks.backup', ['id' => $backup->id]))->assertStatus(404);

        // the original pack imports again: rules created again
        list($plan, $result) = $this->import($json, [$a], $admin);
        $this->assertSame(['r1' => [$a->id => 'create'], 'r2' => [$a->id => 'create']], $this->statuses($plan));
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertCount(2, $this->workflows($a));
    }
}
