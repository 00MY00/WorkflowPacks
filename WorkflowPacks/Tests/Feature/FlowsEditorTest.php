<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Tests\TestCase;

/** Editor of the flows (FlowsController): rights, draft, publishing, switch, simulation, deletion. */
class FlowsEditorTest extends TestCase
{

    /** Flows already on the instance (rolled back after the test) do not take part. */
    protected function setUp(): void
    {
        parent::setUp();
        Flow::query()->update(['active' => false]);
        \Modules\WorkflowPacks\Services\Engine\Listener::forget();
    }
    protected function draft()
    {
        return ['blocks' => [
            ['id' => 'b1', 'type' => 'trigger.new_mail', 'x' => 40, 'y' => 120],
            ['id' => 'b2', 'type' => 'condition.ticket', 'params' => ['var' => 'ticket.subject', 'operator' => 'contains', 'value' => 'PR'], 'x' => 330, 'y' => 120],
            ['id' => 'b3', 'type' => 'wait.duration', 'params' => ['delay' => ['number' => 2, 'metric' => 'h']], 'x' => 620, 'y' => 100],
            ['id' => 'b4', 'type' => 'action.status', 'params' => ['status' => 'closed'], 'x' => 910, 'y' => 100],
        ], 'links' => [
            ['from' => 'b1', 'port' => 'next', 'to' => 'b2'],
            ['from' => 'b2', 'port' => 'yes', 'to' => 'b3'],
            ['from' => 'b3', 'port' => 'next', 'to' => 'b4'],
        ]];
    }

    public function testOnlyAdministratorsAndOnlyWhenTheEngineIsOn()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(Engine::OPTION, false);
        $this->actingAs($admin)->get(route('workflowpacks.flows'))->assertStatus(403);
        \Option::set(Engine::OPTION, true);
        $this->seeIn($this->actingAs($admin)->get(route('workflowpacks.flows'))->assertStatus(200), 'wpf-app');
        $agent = $this->user([$a], false, true);
        $this->actingAs($agent)->get(route('workflowpacks.flows'))->assertStatus(403);
        $this->actingAs($agent)->postJson(route('workflowpacks.flows.store'), ['name' => 'x'])->assertStatus(403);
    }

    public function testADraftIsSavedPublishedSwitchedOnSimulatedAndDeleted()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(Engine::OPTION, true);
        $created = $this->actingAs($admin)->postJson(route('workflowpacks.flows.store'), ['name' => 'Pull requests'])->json();
        $id = $created['flow']['id'];
        $this->assertSame('trigger.new_mail', $created['flow']['draft']['blocks'][0]['type'], 'starts with its trigger');

        // not published yet: cannot be switched on
        $this->actingAs($admin)->postJson(route('workflowpacks.flows.toggle', ['id' => $id]), ['active' => true])->assertStatus(422);

        $saved = $this->actingAs($admin)->postJson(route('workflowpacks.flows.save', ['id' => $id]),
            ['name' => 'PR', 'mailbox_ids' => [$a->id, 999999], 'once' => true, 'draft' => $this->draft()])->json();
        $this->assertTrue($saved['ok']);
        $this->assertSame([], $saved['problems']);
        $this->assertStringContainsString('PR', $saved['titles']['b2'], 'title composed on the server');
        $this->assertSame([$a->id], Flow::find($id)->mailbox_ids, 'unknown mailbox dropped');

        // simulation of the draft: the steps, nothing written
        $sim = $this->actingAs($admin)->postJson(route('workflowpacks.flows.simulate', ['id' => $id]),
            ['draft' => $this->draft(), 'mail' => ['subject' => 'PR #9', 'from' => 'x@example.test']])->json();
        $this->assertSame(['b1', 'b2', 'b3', 'b4'], array_column($sim['events'], 'block'));
        $this->assertSame('done', $sim['end']);

        $pub = $this->actingAs($admin)->postJson(route('workflowpacks.flows.publish', ['id' => $id]))->json();
        $this->assertTrue($pub['ok']);
        $this->assertSame(1, $pub['version']);
        $this->actingAs($admin)->postJson(route('workflowpacks.flows.toggle', ['id' => $id]), ['active' => true])->assertStatus(200);
        $this->assertTrue(Flow::find($id)->active);
        // a ticket through it: its path (blocks, decisions, waiting)
        $conversation = new \App\Conversation();
        $conversation->type = \App\Conversation::TYPE_EMAIL;
        $conversation->number = random_int(100000, 999999);
        $conversation->mailbox_id = $a->id;
        $conversation->subject = 'PR #5';
        $conversation->source_via = \App\Conversation::PERSON_CUSTOMER;
        $conversation->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $conversation->status = \App\Conversation::STATUS_ACTIVE;
        $conversation->state = \App\Conversation::STATE_PUBLISHED;
        $conversation->save();
        $this->assertSame(1, \Modules\WorkflowPacks\Services\Engine\Listener::fire('new_mail', $conversation));
        $path = $this->actingAs($admin)->getJson(route('workflowpacks.flows.path', ['id' => $id, 'ticket' => $conversation->fresh()->number]))->json();
        $this->assertTrue($path['ok']);
        $this->assertSame('waiting', $path['state']);
        $this->assertSame(['b1', 'b2', 'b3'], array_column($path['events'], 'block'));
        $this->assertNotNull($path['wake_at']);
        $this->assertFalse($this->actingAs($admin)->getJson(route('workflowpacks.flows.path', ['id' => $id, 'ticket' => 999999999]))->json()['ok']);
        $list = $this->actingAs($admin)->getJson(route('workflowpacks.flows.data'))->json();
        $this->assertSame(1, collect($list['flows'])->firstWhere('id', $id)['version']);

        // an incomplete draft: problems, and publishing refused
        $bad = $this->draft();
        $bad['blocks'][1]['params']['value'] = '';
        $saved = $this->actingAs($admin)->postJson(route('workflowpacks.flows.save', ['id' => $id]), ['name' => 'PR', 'draft' => $bad])->json();
        $this->assertNotEmpty($saved['problems']);
        $this->actingAs($admin)->postJson(route('workflowpacks.flows.publish', ['id' => $id]))->assertStatus(422);
        $this->assertSame(1, (int) \Modules\WorkflowPacks\Entities\FlowVersion::where('flow_id', $id)->max('number'), 'the published version keeps running');

        $this->actingAs($admin)->postJson(route('workflowpacks.flows.destroy', ['id' => $id]))->assertStatus(200);
        $this->assertNull(Flow::find($id));
    }
}
