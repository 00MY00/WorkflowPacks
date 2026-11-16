<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowReapply;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Listener;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\Engine\Reapply;
use Modules\WorkflowPacks\Tests\TestCase;

/** A flow applied to the tickets already there: existing tickets in batches, tickets in progress, safeguards. */
class FlowReapplyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
        Flow::query()->update(['active' => false]);
        Listener::forget();
    }

    protected function ticket($mailbox, $status = \App\Conversation::STATUS_ACTIVE)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->number = random_int(100000, 999999);
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = 'Ancien ticket';
        $c->customer_email = 'client@example.test';
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = $status;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();

        return $c;
    }

    protected function flow($mailbox, $admin, array $blocks, array $links, $once = true)
    {
        $flow = Flow::create(['name' => 'Réappliquer', 'mailbox_ids' => [$mailbox->id], 'active' => false, 'once' => $once,
            'created_by' => $admin->id, 'draft' => ['blocks' => $blocks, 'links' => $links]]);
        $this->assertTrue((new Publisher())->publish($flow, $admin)['ok']);

        return Flow::with('version')->find($flow->id);
    }

    protected function closing($mailbox, $admin)
    {
        return $this->flow($mailbox, $admin, [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'action.status', 'params' => ['status' => 'pending']]],
            [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]);
    }

    public function testExistingTicketsOfThePeriodAndStatusesGoThroughTheFlowOnce()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $t1 = $this->ticket($a);
        $t2 = $this->ticket($a);
        $closed = $this->ticket($a, \App\Conversation::STATUS_CLOSED);
        $other = $this->ticket($this->mailbox('B'));
        $old = $this->ticket($a);
        \App\Conversation::where('id', $old->id)->update(['created_at' => \Carbon\Carbon::now()->subDays(40)]);
        $flow = $this->closing($a, $admin);
        $o = Reapply::options(['scope' => 'existing', 'period' => 30, 'statuses' => ['active']]);

        $estimate = (new Reapply())->estimate($flow, $o);
        $this->assertSame(2, $estimate['total'], 'mailbox, period and status');
        $this->assertSame([], $estimate['risky']);
        $this->assertCount(2, $estimate['sample']['tickets']);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $t1->fresh()->status, 'the dry run changes nothing');

        $job = (new Reapply())->start($flow, $o, $admin);
        $this->assertNull((new Reapply())->start($flow, $o, $admin), 'one job at a time per flow');
        (new Reapply())->batch($job);
        $job = $job->fresh();
        $this->assertSame(FlowReapply::DONE, $job->state);
        $this->assertSame(2, (int) $job->started);
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $t1->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $t2->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $closed->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $other->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $old->fresh()->status);
        // "once per ticket": nothing left for a new job
        $this->assertSame(0, (new Reapply())->estimate($flow, Reapply::options(['scope' => 'existing', 'period' => 30, 'statuses' => ['active', 'pending']]), false)['total']);
    }

    public function testBatchesPauseResumeAndTheScheduler()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        for ($i = 0; $i < 23; $i++) {
            $this->ticket($a);
        }
        $flow = $this->closing($a, $admin);
        $job = (new Reapply())->start($flow, Reapply::options(['scope' => 'existing', 'batch' => 10, 'every' => 5]), $admin);
        $this->assertSame(23, (int) $job->total);

        $this->assertSame(10, (new Reapply())->tick(), 'one batch per tick');
        $this->assertSame(0, (new Reapply())->tick(), 'next batch only after the interval');
        $this->assertSame(10, (int) $job->fresh()->done);

        Reapply::control($job->fresh(), 'pause');
        FlowReapply::where('id', $job->id)->update(['next_at' => \Carbon\Carbon::now()->subMinute()]);
        $this->assertSame(0, (new Reapply())->tick(), 'paused');
        Reapply::control($job->fresh(), 'resume');
        $this->assertSame(10, (new Reapply())->tick());
        FlowReapply::where('id', $job->id)->update(['next_at' => \Carbon\Carbon::now()->subMinute()]);
        $this->assertSame(3, (new Reapply())->tick());
        $job = $job->fresh();
        $this->assertSame(FlowReapply::DONE, $job->state);
        $this->assertSame(23, (int) $job->done);
        $this->assertSame(23, (int) $job->started);
    }

    public function testTicketsInProgressMoveToTheNewVersionAtTheSameBlock()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $blocks = [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'wait.duration', 'params' => ['delay' => ['number' => 2, 'metric' => 'h']]],
            ['id' => 'b3', 'type' => 'action.status', 'params' => ['status' => 'closed']]];
        $links = [['from' => 'b1', 'port' => 'next', 'to' => 'b2'], ['from' => 'b2', 'port' => 'next', 'to' => 'b3']];
        $flow = $this->flow($a, $admin, $blocks, $links, false);
        $kept = (new Engine())->start($flow, $this->ticket($a));
        $gone = (new Engine())->start($flow, $this->ticket($a));
        $this->assertSame(FlowRun::WAITING, $kept->fresh()->state);
        FlowRun::where('id', $gone->id)->update(['block' => 'b9']);   // a block the new version no longer has
        $v1 = (int) $flow->version_id;

        $blocks[2]['params']['status'] = 'pending';
        $flow->update(['draft' => ['blocks' => $blocks, 'links' => $links]]);
        (new Publisher())->publish($flow, $admin);
        $flow = Flow::with('version')->find($flow->id);
        $this->assertNotSame($v1, (int) $flow->version_id);
        $this->assertSame(2, Reapply::runs($flow)->count());

        $job = (new Reapply())->start($flow, Reapply::options(['scope' => 'running']), $admin);
        (new Reapply())->batch($job);
        $this->assertSame((int) $flow->version_id, (int) $kept->fresh()->version_id);
        $this->assertSame(FlowRun::WAITING, $kept->fresh()->state);
        $this->assertSame(FlowRun::CANCELLED, $gone->fresh()->state);
        $this->assertSame(FlowReapply::DONE, $job->fresh()->state);
    }

    public function testPageRightsAndTheConfirmationOfRiskyBlocks()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $this->ticket($a);
        $flow = $this->flow($a, $admin, [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'action.delete']],
            [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]);

        $this->actingAs($this->user([$a], false, true))->get(route('workflowpacks.reapply', ['id' => $flow->id]))->assertStatus(403);
        $page = $this->actingAs($admin)->get(route('workflowpacks.reapply', ['id' => $flow->id]));
        $page->assertStatus(200);
        $this->seeIn($page, e(__('workflowpacks::reapply.scope_existing')));
        $estimate = $this->actingAs($admin)->post(route('workflowpacks.reapply.estimate', ['id' => $flow->id]), ['scope' => 'existing']);
        $this->seeIn($estimate, e(__('workflowpacks::reapply.warn_delete')));

        $this->actingAs($admin)->post(route('workflowpacks.reapply.start', ['id' => $flow->id]), ['scope' => 'existing'])->assertStatus(200);
        $this->assertSame(0, FlowReapply::where('flow_id', $flow->id)->count(), 'not started without the confirmation');
        $this->actingAs($admin)->post(route('workflowpacks.reapply.start', ['id' => $flow->id]), ['scope' => 'existing', 'confirm_risky' => 1])
            ->assertRedirect(route('workflowpacks.reapply', ['id' => $flow->id]));
        $this->assertSame(1, FlowReapply::where('flow_id', $flow->id)->count());
        $this->actingAs($admin)->post(route('workflowpacks.reapply.control', ['id' => $flow->id]), ['action' => 'stop']);
        $this->assertSame(FlowReapply::STOPPED, FlowReapply::where('flow_id', $flow->id)->first()->state);
    }

    public function testAFlowNotPublishedCanBePublishedFromThePage()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $flow = Flow::create(['name' => 'Brouillon', 'mailbox_ids' => [$a->id], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'action.status', 'params' => ['status' => 'pending']]],
                'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]);
        $page = $this->actingAs($admin)->get(route('workflowpacks.reapply', ['id' => $flow->id]))->assertStatus(200);
        $this->seeIn($page, e(__('workflowpacks::reapply.not_published')));
        $this->seeIn($page, route('workflowpacks.reapply.publish', ['id' => $flow->id]));
        $this->actingAs($admin)->post(route('workflowpacks.reapply.publish', ['id' => $flow->id]))->assertRedirect(route('workflowpacks.reapply', ['id' => $flow->id]));
        $this->assertNotNull($flow->fresh()->version_id);
        $this->seeIn($this->actingAs($admin)->get(route('workflowpacks.reapply', ['id' => $flow->id])), e(__('workflowpacks::reapply.scope_existing')));

        // a flow with problems: not published, its problems said
        $broken = Flow::create(['name' => 'Cassé', 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'condition.ticket', 'params' => []]],
                'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]);
        $this->actingAs($admin)->post(route('workflowpacks.reapply.publish', ['id' => $broken->id]))->assertSessionHas('flash_error_floating');
        $this->assertNull($broken->fresh()->version_id);
        $this->actingAs($this->user([$a], false, true))->post(route('workflowpacks.reapply.publish', ['id' => $flow->id]))->assertStatus(403);
    }
}
