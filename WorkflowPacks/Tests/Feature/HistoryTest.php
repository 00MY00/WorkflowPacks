<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Activity;
use Modules\WorkflowPacks\Services\History;
use Modules\WorkflowPacks\Tests\TestCase;

/** Workflow Packs › History: what is written, who sees what, clean-up of old entries. */
class HistoryTest extends TestCase
{
    public function testImportsAndRuleChangesAreWrittenAndShown()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        list(, $result) = $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        $this->assertTrue($result['ok'], $result['error']);
        $wf = $this->workflows($a)->first();
        $this->actingAs($admin)->postJson(route('workflowpacks.graph.toggle', ['id' => $wf->id]), ['active' => true])->assertStatus(200);
        $this->actingAs($admin)->postJson(route('workflowpacks.graph.previous_many'), ['ids' => [$wf->id], 'on' => true])->assertStatus(200);

        $this->assertSame(['previous_on', 'enabled', 'ok'], Activity::orderBy('id', 'desc')->limit(3)->pluck('status')->all());
        $import = Activity::where('kind', 'import')->first();
        $this->assertSame('Pack de test', $import->subject);
        $this->assertSame(1, json_decode($import->message, true)['counts']['created']);

        $page = $this->actingAs($admin)->get(route('workflowpacks.history'))->assertStatus(200);
        $this->seeIn($page, e(__('workflowpacks::messages.history_kind_import')));
        $this->seeIn($page, e(__('workflowpacks::messages.history_status_previous_on')));
        $this->seeIn($page, '1 × '.e(__('workflowpacks::messages.history_count_created')));
        $this->seeIn($page, e(__('workflowpacks::messages.history_diag_title')), 'diagnostic for administrators');

        // filters
        $rules = $this->actingAs($admin)->get(route('workflowpacks.history', ['kind' => 'rules']))->getContent();
        $this->assertStringNotContainsString('1 × '.e(__('workflowpacks::messages.history_count_created')), $rules);
    }

    public function testUsersSeeTheirMailboxesOnlyAndNoDiagnostic()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        History::add('imap_file', 'moved', ['mailbox_id' => $a->id, 'subject' => 'Visible '.$a->id, 'user_id' => 0]);
        History::add('imap_file', 'moved', ['mailbox_id' => $b->id, 'subject' => 'Hidden '.$b->id, 'user_id' => 0]);

        $page = $this->actingAs($agent)->get(route('workflowpacks.history'))->assertStatus(200);
        $this->seeIn($page, 'Visible '.$a->id);
        $this->assertStringNotContainsString('Hidden '.$b->id, $page->getContent());
        $this->assertStringNotContainsString(e(__('workflowpacks::messages.history_diag_title')), $page->getContent());
        $this->actingAs($agent)->get(route('workflowpacks.history.inspect', ['imap_mailbox' => $a->id]))->assertStatus(403);
    }

    public function testOldEntriesAreRemoved()
    {
        $old = History::add('rules', 'enabled', ['subject' => 'old']);
        Activity::where('id', $old)->update(['created_at' => date('Y-m-d H:i:s', time() - 100 * 86400)]);
        $recent = History::add('rules', 'enabled', ['subject' => 'recent']);
        History::prune();
        $this->assertNull(Activity::find($old));
        $this->assertNotNull(Activity::find($recent));
    }
}
