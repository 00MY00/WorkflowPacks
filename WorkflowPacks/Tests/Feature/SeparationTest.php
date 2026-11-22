<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Backup;
use Modules\WorkflowPacks\Http\Controllers\SeparationController;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Separation;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/**
 * Separation from Workflows (4.0): nothing deleted by the update; a page says what a confirmation deletes, gives the
 * backup, and asks to confirm, to stay on the previous version or to decide later. On confirmation: the backup first,
 * then only the rules the packs created (in their mailbox), then the old tables.
 *
 * Dropping a table ends the transaction of the test (MySQL): the old tables are created again before each test.
 */
class SeparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
        foreach (['2026_10_10_000000_create_workflowpacks_tables' => 'CreateWorkflowpacksTables',
            '2026_10_11_000000_create_workflowpacks_resources_table' => 'CreateWorkflowpacksResourcesTable',
            '2026_10_12_000000_create_workflowpacks_layouts_table' => 'CreateWorkflowpacksLayoutsTable',
            '2026_10_15_000000_add_sizes_to_workflowpacks_layouts_table' => 'AddSizesToWorkflowpacksLayoutsTable'] as $file => $class) {
            require_once __DIR__.'/../../Database/Migrations/'.$file.'.php';
            (new $class())->up();
        }
        foreach (Separation::LEGACY_TABLES as $table) {
            \DB::table($table)->delete();
        }
        Backup::query()->delete();
    }

    protected function tearDown(): void
    {
        // dropping a table committed the transaction of the test (MySQL): one is opened again for its rollback
        if (!\DB::getPdo()->inTransaction()) {
            \DB::getPdo()->beginTransaction();
        }
        parent::tearDown();
    }

    protected function rule($mailbox, $name)
    {
        $wf = new Workflow();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = $name.' '.self::uid();
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'facture']]];
        $wf->actions = [[['type' => 'status', 'value' => '3']]];
        $wf->active = true;
        $wf->save();

        return $wf;
    }

    /** A pack of rules imported in mailbox A: its rule, a link to a rule deleted since, a link pointing to another mailbox. */
    protected function legacy($a, $b, $admin)
    {
        $mine = $this->rule($a, 'Factures');
        $byHand = $this->rule($a, 'Faite à la main');
        $elsewhere = $this->rule($b, 'Autre boîte');
        \DB::table('workflowpacks_packs')->insert(['pack_key' => 'tri-factures', 'name' => 'Tri des factures', 'pack_version' => '1.2.0',
            'source' => json_encode(['format' => 'freescout-workflow-pack', 'key' => 'tri-factures']), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$mine->id, $a->id, 'factures'], [999999999, $a->id, 'gone'], [$elsewhere->id, $a->id, 'wrong-mailbox']] as list($id, $mailboxId, $key)) {
            \DB::table('workflowpacks_links')->insert(['pack_key' => 'tri-factures', 'workflow_key' => $key, 'mailbox_id' => $mailboxId,
                'workflow_id' => $id, 'template' => '{}', 'fingerprint' => str_repeat('a', 64), 'imported_by' => $admin->id,
                'created_at' => now(), 'updated_at' => now()]);
        }
        \DB::table('workflowpacks_imports')->insert(['pack_key' => 'tri-factures', 'summary' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $c = \App\Conversation::first();
        if ($c && \Schema::hasTable('conversation_workflow')) {
            \DB::table('conversation_workflow')->insert(['conversation_id' => $c->id, 'workflow_id' => $mine->id]);
        }
        Backup::create(['pack_key' => 'ancien-pack', 'user_id' => $admin->id, 'content' => json_encode(['pack' => ['key' => 'ancien-pack']])]);
        Backup::create(['pack_key' => 'flow-3-mon-flux', 'user_id' => $admin->id, 'content' => json_encode(['pack' => ['flows' => []]])]);

        return [$mine, $byHand, $elsewhere];
    }

    public function testNothingIsDeletedBeforeAnAdministratorDecides()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $this->assertFalse(Separation::pending(true), 'old tables empty: nothing to decide');
        list($mine) = $this->legacy($a, $b, $admin);
        $this->assertTrue(Separation::pending(true));

        // the home page opens the separation; every other page says it waits for a decision
        $this->actingAs($admin)->get(route('workflowpacks.index'))->assertRedirect(route('workflowpacks.separation'));
        $flows = $this->actingAs($admin)->get(route('workflowpacks.flows'))->assertStatus(200)->getContent();
        $this->assertStringContainsString(e(__('workflowpacks::separation.notice')), $flows);

        // the page: what will be deleted (only the rule of the pack, in its mailbox), the backup, the three choices
        $page = $this->actingAs($admin)->get(route('workflowpacks.separation'))->assertStatus(200)->getContent();
        $this->assertStringContainsString(e($mine->name), $page);
        $this->assertStringNotContainsString('Faite à la main', $page, 'a rule made by hand is never listed');
        $this->assertStringNotContainsString('Autre boîte', $page, 'a rule of another mailbox is never listed');
        $this->assertStringContainsString(e(__('workflowpacks::separation.what_gone', ['count' => 2])), $page);
        $this->assertStringContainsString('sudo bash install.sh --version '.Separation::PREVIOUS, $page);
        $this->assertStringContainsString(e(Separation::zipUrl()), $page);
        $this->assertStringContainsString('name="understood"', $page);
        $this->assertStringNotContainsString('workflowpacks::', strip_tags($page));

        // the backup to keep before deciding: the packs, links, rules as they are; nothing changed
        $json = json_decode($this->actingAs($admin)->get(route('workflowpacks.separation.backup'))->assertStatus(200)->getContent(), true);
        $this->assertSame('workflowpacks-separation', $json['format']);
        $this->assertSame([(int) $mine->id], array_map('intval', array_column($json['workflows_rules'], 'id')));
        $this->assertSame('tri-factures', $json['packs'][0]['source']['key']);
        $this->assertCount(3, $json['links']);
        $this->assertSame(['ancien-pack'], array_column($json['older_backups'], 'pack_key'));

        // confirming without the box ticked: refused, nothing deleted
        $this->actingAs($admin)->post(route('workflowpacks.separation.confirm'), ['choice' => 'confirm'])->assertRedirect(route('workflowpacks.separation'));
        $this->assertNotNull(Workflow::find($mine->id));
        $this->assertTrue(\Schema::hasTable('workflowpacks_links'));

        // later: the flows open for this session, the notice stays, nothing deleted
        $this->actingAs($admin)->post(route('workflowpacks.separation.confirm'), ['choice' => 'later'])->assertRedirect(route('workflowpacks.flows'));
        $this->assertTrue((bool) session(SeparationController::LATER));
        $this->actingAs($admin)->get(route('workflowpacks.index'))->assertRedirect(route('workflowpacks.flows'));
        $this->assertNotNull(Workflow::find($mine->id));
        $this->assertTrue(Separation::pending(true));
    }

    public function testConfirmedTheBackupFirstThenOnlyTheRulesOfThePacksThenTheOldTables()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        list($mine, $byHand, $elsewhere) = $this->legacy($a, $b, $admin);

        $res = $this->actingAs($admin)->post(route('workflowpacks.separation.confirm'), ['choice' => 'confirm', 'understood' => 1]);
        $res->assertRedirect(route('workflowpacks.flows'));

        // only the rule of the pack, in its mailbox
        $this->assertNull(Workflow::find($mine->id));
        $this->assertNotNull(Workflow::find($byHand->id), 'made by hand: kept');
        $this->assertNotNull(Workflow::find($elsewhere->id), 'linked to another mailbox: kept');
        if (\Schema::hasTable('conversation_workflow')) {
            $this->assertSame(0, \DB::table('conversation_workflow')->where('workflow_id', $mine->id)->count());
        }
        // the old tables dropped, the older backups of packs replaced by the new one, the backups of flows kept
        foreach (Separation::LEGACY_TABLES as $table) {
            $this->assertFalse(\Schema::hasTable($table), $table);
        }
        $this->assertSame(['flow-3-mon-flux', Separation::BACKUP_KEY], Backup::orderBy('id')->pluck('pack_key')->all());
        $backup = Backup::where('pack_key', Separation::BACKUP_KEY)->first();
        $content = json_decode($backup->content, true);
        $this->assertSame((int) $mine->id, (int) $content['workflows_rules'][0]['id']);
        $this->assertSame((int) $admin->id, (int) $backup->user_id);
        // downloadable, written in History, done
        $file = json_decode($this->actingAs($admin)->get(route('workflowpacks.backup', ['id' => $backup->id]))->assertStatus(200)->getContent(), true);
        $this->assertSame('workflowpacks-separation', $file['format']);
        $this->assertTrue(\Modules\WorkflowPacks\Entities\Activity::where('kind', 'separation')->exists());
        $this->assertNotNull(\App\Option::get(Separation::OPTION_DONE));
        $this->assertFalse(Separation::pending(true));
        $this->actingAs($admin)->get(route('workflowpacks.separation'))->assertRedirect(route('workflowpacks.index'));
        $this->actingAs($admin)->get(route('workflowpacks.index'))->assertRedirect(route('workflowpacks.flows'));
        $this->assertStringNotContainsString(e(__('workflowpacks::separation.notice')), $this->actingAs($admin)->get(route('workflowpacks.flows'))->getContent());
        // nothing left: confirming again does nothing
        $this->actingAs($admin)->post(route('workflowpacks.separation.confirm'), ['choice' => 'confirm', 'understood' => 1])->assertRedirect(route('workflowpacks.index'));
    }
}
