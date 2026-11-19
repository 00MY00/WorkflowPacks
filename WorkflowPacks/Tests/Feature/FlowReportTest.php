<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Listener;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * What the flows do, told to the other modules (docs/api-flux.md): the action "workflowpacks.ticket_changed" for each
 * change of a real run (never a dry run, never a text), and the reads ticket_changes, flow_stats, untreated, info.
 */
class FlowReportTest extends TestCase
{
    /** @var array changes received by the action */
    protected $told = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
        Flow::query()->update(['active' => false]);
        Listener::forget();
        \Cache::flush();
        $this->told = [];
        \Eventy::addAction('workflowpacks.ticket_changed', function ($id, $change) {
            $this->told[] = [$id, $change];
        }, 20, 2);
    }

    protected function ticket($mailbox, $status = \App\Conversation::STATUS_ACTIVE)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->number = random_int(100000, 999999);
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = 'Commande client';
        $c->customer_email = 'client@example.test';
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = $status;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();

        return $c;
    }

    /** Trigger, then the blocks one after the other. */
    protected function flow($admin, array $blocks, $name = 'Clore les commandes')
    {
        $list = [['id' => 'b1', 'type' => 'trigger.new_mail', 'params' => []]];
        $links = [];
        foreach ($blocks as $i => $b) {
            $list[] = ['id' => 'b'.($i + 2)] + $b;
            $links[] = ['from' => 'b'.($i + 1), 'port' => 'next', 'to' => 'b'.($i + 2)];
        }
        $flow = Flow::create(['name' => $name, 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => $list, 'links' => $links]]);
        $this->assertTrue((new Publisher())->publish($flow, $admin)['ok']);

        return $flow->fresh(['version']);
    }

    /** The lines of one flow in the statistics (other flows may exist on the instance). */
    protected static function of(array $stats, $flowId)
    {
        return array_values(array_filter($stats['flows'], function ($f) use ($flowId) {
            return $f['flow_id'] === (int) $flowId;
        }));
    }

    public function testEachChangeOfARealRunIsToldWithoutTextAndNeverForADryRun()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $tech = $this->user([$a]);
        $ticket = $this->ticket($a);
        $flow = $this->flow($admin, [
            ['type' => 'action.status', 'params' => ['status' => 'closed']],
            ['type' => 'action.assign', 'params' => ['user' => (string) $tech->id]],
            ['type' => 'action.note', 'params' => ['text' => 'Texte confidentiel du client']],
        ]);

        // a dry run (the sample of "Tickets existants", the simulation): nothing told, nothing changed
        (new \Modules\WorkflowPacks\Services\Ai\Answer())->test((array) $flow->version->data, [$ticket->id]);
        $this->assertSame([], $this->told);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $ticket->fresh()->status);

        $run = (new Engine())->start($flow, $ticket);
        $this->assertSame(FlowRun::DONE, $run->fresh()->state);
        $this->assertSame([FlowRun::ORIGIN_NEW_MAIL, 'Clore les commandes'], [$run->fresh()->origin, $run->fresh()->flow_name]);
        $this->assertCount(3, $this->told);
        list($id, $status) = $this->told[0];
        $this->assertSame((int) $ticket->id, $id);
        $this->assertSame(['v' => 1, 'conversation_id' => (int) $ticket->id, 'mailbox_id' => (int) $a->id, 'flow_id' => (int) $flow->id,
            'flow_name' => 'Clore les commandes', 'version' => 1, 'origin' => 'new_mail', 'temporary' => false,
            'field' => 'status', 'before' => 'active', 'after' => 'closed'],
            array_intersect_key($status, array_flip(['v', 'conversation_id', 'mailbox_id', 'flow_id', 'flow_name', 'version', 'origin', 'temporary', 'field', 'before', 'after'])));
        $this->assertNotEmpty($status['at']);
        // the assigned user as an id (not a name), the note without its text
        $this->assertSame(['assignee', null, (int) $tech->id], [$this->told[1][1]['field'], $this->told[1][1]['before'], $this->told[1][1]['after']]);
        $this->assertSame(['note', null, true], [$this->told[2][1]['field'], $this->told[2][1]['before'], $this->told[2][1]['after']]);
        $this->assertStringNotContainsString('confidentiel', json_encode($this->told));
        $this->assertStringNotContainsString($tech->last_name, json_encode($this->told));
    }

    public function testAModuleFailingWhenToldNeverStopsTheFlow()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $ticket = $this->ticket($a);
        \Eventy::addAction('workflowpacks.ticket_changed', function () {
            throw new \RuntimeException('module en panne');
        }, 10, 2);
        $flow = $this->flow($admin, [['type' => 'action.status', 'params' => ['status' => 'pending']], ['type' => 'action.note', 'params' => ['text' => 'vu']]]);

        $run = (new Engine())->start($flow, $ticket);
        $this->assertSame(FlowRun::DONE, $run->fresh()->state);
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $ticket->fresh()->status);
        $this->assertSame(1, \App\Thread::where('conversation_id', $ticket->id)->where('type', \App\Thread::TYPE_NOTE)->count(), 'the next block ran');
    }

    public function testTheReadsGiveTheChangesTheStatisticsAndTheUntreatedTickets()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $closed = $this->ticket($a);
        $untouched = $this->ticket($a);
        $elsewhere = $this->ticket($b);
        $flow = $this->flow($admin, [['type' => 'action.status', 'params' => ['status' => 'closed']], ['type' => 'action.note', 'params' => ['text' => 'clos']]]);
        (new Engine())->start($flow, $closed);

        $this->assertSame(['api' => 1, 'engine' => true], array_intersect_key(\Eventy::filter('workflowpacks.info', []), array_flip(['api', 'engine'])));

        // changes of some tickets (others: no key), one field only on demand
        $changes = \Eventy::filter('workflowpacks.ticket_changes', [], [$closed->id, $untouched->id, 'x', -3]);
        $this->assertSame([(int) $closed->id], array_keys($changes));
        $this->assertSame(['status', 'note'], array_column($changes[$closed->id], 'field'));
        $this->assertCount(1, \Eventy::filter('workflowpacks.ticket_changes', [], [$closed->id], ['field' => 'status'])[$closed->id]);

        // statistics: tickets closed by each flow, by mailbox
        $stats = \Eventy::filter('workflowpacks.flow_stats', [], ['field' => 'status', 'after' => 'closed']);
        $this->assertGreaterThanOrEqual(1, $stats['scanned']);
        $this->assertSame([['flow_id' => (int) $flow->id, 'flow_name' => 'Clore les commandes', 'temporary' => false, 'tickets' => 1, 'changes' => 1, 'exists' => true]],
            self::of($stats, $flow->id));
        $this->assertSame([], self::of(\Eventy::filter('workflowpacks.flow_stats', [], ['mailboxes' => [$b->id]]), $flow->id));
        $this->assertSame([], self::of(\Eventy::filter('workflowpacks.flow_stats', [], ['field' => 'status', 'after' => 'pending']), $flow->id));
        $this->assertSame([], self::of(\Eventy::filter('workflowpacks.flow_stats', [], ['from' => '2000-01-01', 'to' => '2000-12-31']), $flow->id));

        // untreated: no change by any flow
        $untreated = \Eventy::filter('workflowpacks.untreated', [], ['mailboxes' => [$a->id]]);
        $this->assertContains((int) $untouched->id, $untreated['ids']);
        $this->assertNotContains((int) $closed->id, $untreated['ids']);
        $this->assertNotContains((int) $elsewhere->id, $untreated['ids']);
        $this->assertSame([], \Eventy::filter('workflowpacks.untreated', [], ['mailboxes' => [$a->id], 'statuses' => ['closed']])['ids']);

        // the flow deleted: still named in the statistics (kept with each ticket), "exists" false
        $flow->erase();
        $mine = self::of(\Eventy::filter('workflowpacks.flow_stats', [], ['field' => 'status']), $flow->id);
        $this->assertSame(['Clore les commandes', false], [$mine[0]['flow_name'], $mine[0]['exists']]);
        $this->assertSame('Clore les commandes', \Eventy::filter('workflowpacks.ticket_changes', [], [$closed->id])[$closed->id][0]['flow_name']);
    }
}
