<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Http\Controllers\FlowPackController;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\FlowPack;
use Modules\WorkflowPacks\Tests\TestCase;

/** Packs of flows of the internal engine: the shipped pack, import as drafts, new import, export, rights, engine on by default. */
class FlowPackTest extends TestCase
{
    protected function shipped()
    {
        return file_get_contents(FlowPackController::bundled()['tri-emails-multilingue-flux.json']);
    }

    public function testTheEngineIsOnByDefault()
    {
        \DB::table('options')->where('name', Engine::OPTION)->delete();
        \Cache::flush();
        $this->assertTrue(Engine::enabled());
    }

    public function testTheShippedPackIsReadWithoutProblemOnceItsParameterIsGiven()
    {
        $read = (new FlowPack())->read($this->shipped(), ['newsletter_sender' => 'news@example.com']);
        $this->assertTrue($read['ok'], implode(' ', $read['problems']));
        $this->assertCount(8, $read['flows']);
        foreach ($read['flows'] as $f) {
            $this->assertSame([], $f['problems'], $f['key']);
        }
        $news = collect($read['flows'])->firstWhere('key', 'newsletters');
        $this->assertStringContainsString('news@example.com', json_encode($news['draft']));
        $this->assertStringNotContainsString('{{param.', json_encode($news['draft']));
    }

    public function testImportCreatesDraftsSwitchedOffThenANewImportUpdatesThem()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(Engine::OPTION, true);
        $this->actingAs($admin)->get(route('workflowpacks.flowpack'))->assertStatus(200);
        $preview = $this->actingAs($admin)->post(route('workflowpacks.flowpack.preview'), ['bundled' => 'tri-emails-multilingue-flux.json']);
        $preview->assertStatus(200);
        $this->seeIn($preview, 'newsletter_sender');

        $post = ['pack_carry' => $this->shipped(), 'params' => ['newsletter_sender' => 'news@example.com'], 'mailbox_ids' => [$a->id, 999999]];
        $this->actingAs($admin)->post(route('workflowpacks.flowpack.confirm'), $post)->assertRedirect(route('workflowpacks.flows'));
        $flows = Flow::where('pack_key', 'tri-emails-multilingue-flux')->get();
        $this->assertCount(8, $flows);
        $this->assertSame([false], $flows->pluck('active')->unique()->values()->all());
        $this->assertSame([$a->id], $flows->first()->mailbox_ids, 'unknown mailboxes are dropped');
        $this->assertNull($flows->first()->version_id, 'nothing published');

        $this->actingAs($admin)->post(route('workflowpacks.flowpack.confirm'), $post)->assertRedirect(route('workflowpacks.flows'));
        $this->assertSame(8, Flow::where('pack_key', 'tri-emails-multilingue-flux')->count(), 'not doubled');
    }

    public function testAfterTheImportFlowsPublishedSwitchedOnAndAppliedToTheTicketsThere()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(Engine::OPTION, true);
        // a ticket already there (the runs on the tickets there start only when there is something to go through)
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->number = random_int(100000, 999999);
        $c->folder_id = (int) optional($a->folders()->first())->id;
        $c->mailbox_id = $a->id;
        $c->subject = 'Ancien ticket';
        $c->customer_email = 'client@example.test';
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = \App\Conversation::STATUS_ACTIVE;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();
        $post = ['pack_carry' => $this->shipped(), 'params' => ['newsletter_sender' => 'news@example.com'], 'mailbox_ids' => [$a->id],
            'after_activate' => 1, 'after_apply' => 1, 'after_period' => 90, 'after_statuses' => ['active']];
        $this->actingAs($admin)->post(route('workflowpacks.flowpack.confirm'), $post)->assertRedirect(route('workflowpacks.flows'));
        $flows = Flow::where('pack_key', 'tri-emails-multilingue-flux')->get();
        $this->assertCount(8, $flows);
        $this->assertSame([], $flows->whereStrict('version_id', null)->pluck('name')->all(), 'switching on publishes');
        $this->assertSame([true], $flows->pluck('active')->unique()->values()->all());
        $jobs = \Modules\WorkflowPacks\Entities\FlowReapply::whereIn('flow_id', $flows->pluck('id'))->get();
        $this->assertCount(8, $jobs, 'one run on the tickets there per flow');
        $this->assertSame([90], $jobs->pluck('options')->pluck('period')->unique()->values()->all());
        $this->assertSame([['active']], $jobs->pluck('options')->pluck('statuses')->unique()->values()->all());

        // a pack that deletes tickets: applied to the tickets there only once confirmed
        $pack = json_encode(['format' => 'workflowpacks-flows', 'version' => 1, 'key' => 'menage', 'name' => 'Ménage', 'flows' => [[
            'key' => 'supprimer', 'name' => 'Supprimer', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'action.delete']],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]]);
        $page = $this->actingAs($admin)->post(route('workflowpacks.flowpack.confirm'), ['pack_carry' => $pack, 'after_apply' => 1]);
        $page->assertStatus(200);
        $this->seeIn($page, e(__('workflowpacks::reapply.warn_delete')));
        $this->assertSame(0, Flow::where('pack_key', 'menage')->count(), 'nothing done without the confirmation');
        $this->actingAs($admin)->post(route('workflowpacks.flowpack.confirm'), ['pack_carry' => $pack, 'after_apply' => 1, 'confirm_risky' => 1])
            ->assertRedirect(route('workflowpacks.flows'));
        $flow = Flow::where('pack_key', 'menage')->first();
        $this->assertNotNull($flow->version_id);
        $this->assertFalse((bool) $flow->active, 'applied, not switched on');
        $this->assertSame(1, \Modules\WorkflowPacks\Entities\FlowReapply::where('flow_id', $flow->id)->count());
    }

    public function testAPackLoadedAgainCreatesOnlyTheMissingFlowsWithoutDuplicates()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        \Option::set(Engine::OPTION, true);
        $pack = new FlowPack();
        $values = ['newsletter_sender' => 'news@example.com'];
        $this->assertSame(['created' => 8, 'updated' => 0, 'same' => 0], $pack->import($pack->read($this->shipped(), $values), [$a->id], $admin));

        // the same pack again: everything already here, identical, nothing touched
        $read = $pack->read($this->shipped(), $values);
        $this->assertSame([true], array_values(array_unique(array_column($read['flows'], 'same'))));
        $this->assertSame(['created' => 0, 'updated' => 0, 'same' => 8], $pack->import($read, [$b->id], $admin));
        $this->assertSame(8, Flow::count());

        // one flow deleted, one changed here (its mailboxes too): the missing one comes back, the changed one gets
        // the draft of the pack again but keeps its mailboxes
        $flows = Flow::orderBy('id')->get();
        $flows[0]->delete();
        $changed = $flows[1];
        $draft = $changed->draft;
        $draft['blocks'][] = ['id' => 'extra', 'type' => 'action.status', 'params' => ['status' => 'closed'], 'x' => 0, 'y' => 0];
        $changed->update(['draft' => $draft, 'mailbox_ids' => [$b->id]]);
        $this->assertSame(['created' => 1, 'updated' => 1, 'same' => 6], $pack->import($pack->read($this->shipped(), $values), [$a->id], $admin));
        $this->assertSame(8, Flow::count());
        $changed = $changed->fresh();
        $this->assertSame([$b->id], $changed->mailbox_ids, 'mailboxes kept');
        $this->assertNotContains('extra', array_column($changed->draft['blocks'], 'id'));

        // the flows exported under another name, then loaded again: found by their name, no copy
        $export = FlowPack::export(Flow::orderBy('id')->get(), 'Autre nom');
        $this->assertSame(['created' => 0, 'updated' => 0, 'same' => 8], $pack->import($pack->read(json_encode($export)), [], $admin));
        $this->assertSame(8, Flow::count());

        // a flow made here (no pack), then the same flow pasted (the AI assistant): found by its name, and from
        // then on it belongs to that pack
        $mine = Flow::create(['name' => 'Mon flux', 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail', 'x' => 40, 'y' => 40]], 'links' => []]]);
        $one = json_encode(['name' => 'mon flux ', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail']], 'links' => []]);
        $this->assertSame(['created' => 0, 'updated' => 0, 'same' => 1], $pack->import($pack->read($one), [], $admin));
        $this->assertSame('mon-flux', $mine->fresh()->pack_key);
        $this->assertSame(9, Flow::count());

        // the home page lists the flows by the pack they came from, each one opening in the diagram
        $home = $this->actingAs($admin)->get(route('workflowpacks.index'))->assertStatus(200);
        $this->assertStringNotContainsString(e(__('workflowpacks::messages.no_packs')), $home->getContent());
        $this->seeIn($home, 'tri-emails-multilingue-flux');
        $this->seeIn($home, e($read['pack']['name']));
        $this->seeIn($home, route('workflowpacks.flows', ['flow' => $mine->id]));
        $this->seeIn($home, e(__('workflowpacks::flowpack.home_export')));
    }

    public function testExportedFlowsAreImportedAgain()
    {
        $admin = $this->user([], true);
        \Option::set(Engine::OPTION, true);
        $pack = new FlowPack();
        $pack->import($pack->read($this->shipped()), [], $admin);
        // the page first: the flows to include, all ticked; no file yet
        $page = $this->actingAs($admin)->get(route('workflowpacks.flowpack.export'))->assertStatus(200);
        $this->assertNull($page->headers->get('Content-Disposition'));
        $first = \Modules\WorkflowPacks\Entities\Flow::orderBy('id')->first();
        $this->assertSame(1, preg_match('#name="ids\[\]" value="'.$first->id.'"\s+checked#', $page->getContent()));
        // only the flows ticked
        $one = $pack->read($this->actingAs($admin)->get(route('workflowpacks.flowpack.export', ['download' => 1, 'ids' => [$first->id]]))->getContent());
        $this->assertCount(1, $one['flows']);
        $response = $this->actingAs($admin)->get(route('workflowpacks.flowpack.export', ['name' => 'Copie', 'download' => 1]));
        $response->assertStatus(200);
        $this->assertStringContainsString('attachment; filename="copie.json"', $response->headers->get('Content-Disposition'));
        $read = $pack->read($response->getContent());
        $this->assertTrue($read['ok'], implode(' ', $read['problems']));
        $this->assertSame('copie', $read['pack']['key']);
        $this->assertGreaterThanOrEqual(8, count($read['flows']));
        $this->assertStringNotContainsString('mailbox_ids', $response->getContent());
    }

    public function testNoLimitOnTheNumberOfFlowsAndKeysAreRepaired()
    {
        $flows = [];
        for ($i = 1; $i <= 120; $i++) {
            $flows[] = ['key' => $i % 40 === 0 ? 'Même Clé' : 'flux-'.$i, 'name' => 'Flux '.$i, 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'],
                ['id' => 'b2', 'type' => 'action.status', 'params' => ['status' => 'closed']]], 'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]];
        }
        $flows[] = ['name' => 'Sans clé', 'blocks' => $flows[0]['blocks'], 'links' => $flows[0]['links']];
        $read = (new FlowPack())->read(json_encode(['format' => 'workflowpacks-flows', 'version' => 1, 'key' => 'Mon Gros Pack', 'name' => 'Mon gros pack', 'flows' => $flows]));
        $this->assertTrue($read['ok'], implode(' ', $read['problems']));
        $this->assertSame('mon-gros-pack', $read['pack']['key']);
        $this->assertCount(121, $read['flows']);
        $keys = array_column($read['flows'], 'key');
        $this->assertSame(['meme-cle', 'meme-cle-2', 'meme-cle-3'], array_values(array_filter($keys, function ($k) {
            return strpos($k, 'meme-cle') === 0;
        })));
        $this->assertSame('sans-cle', end($keys));
        $this->assertCount(121, array_unique($keys));

        $empty = (new FlowPack())->read(json_encode(['format' => 'workflowpacks-flows', 'version' => 1, 'key' => 'x', 'flows' => []]));
        $this->assertSame([__('workflowpacks::flowpack.no_flows')], $empty['problems']);
    }

    public function testOnlyAdministratorsAndBadPacksAreRefused()
    {
        $a = $this->mailbox('A');
        \Option::set(Engine::OPTION, true);
        $this->actingAs($this->user([$a], false, true))->get(route('workflowpacks.flowpack'))->assertStatus(403);
        $this->actingAs($this->user([$a], false, true))->get(route('workflowpacks.flowpack.export'))->assertStatus(403);
        $admin = $this->user([$a], true);
        $bad = $this->actingAs($admin)->post(route('workflowpacks.flowpack.preview'), ['pack_json' => '{"format":"autre"}']);
        $this->seeIn($bad, e(__('workflowpacks::flowpack.bad_format')));
        $bad = $this->actingAs($admin)->post(route('workflowpacks.flowpack.preview'), ['bundled' => '../../module.json']);
        $this->seeIn($bad, e(__('workflowpacks::flowpack.not_json')));
        \Option::set(Engine::OPTION, false);
        $this->actingAs($admin)->get(route('workflowpacks.flowpack'))->assertStatus(403);
    }
}
