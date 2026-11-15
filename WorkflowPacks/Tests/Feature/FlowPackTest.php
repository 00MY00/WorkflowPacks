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
        $this->assertSame(8, Flow::where('pack_key', 'tri-emails-multilingue-flux')->count(), 'updated, not doubled');
    }

    public function testExportedFlowsAreImportedAgain()
    {
        $admin = $this->user([], true);
        \Option::set(Engine::OPTION, true);
        $pack = new FlowPack();
        $pack->import($pack->read($this->shipped()), [], $admin);
        $response = $this->actingAs($admin)->get(route('workflowpacks.flowpack.export', ['name' => 'Copie']));
        $response->assertStatus(200);
        $this->assertStringContainsString('attachment; filename="copie.json"', $response->headers->get('Content-Disposition'));
        $read = $pack->read($response->getContent());
        $this->assertTrue($read['ok'], implode(' ', $read['problems']));
        $this->assertSame('copie', $read['pack']['key']);
        $this->assertGreaterThanOrEqual(8, count($read['flows']));
        $this->assertStringNotContainsString('mailbox_ids', $response->getContent());
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
