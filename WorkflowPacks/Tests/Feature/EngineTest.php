<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowEvent;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Services\Engine\BlockRegistry;
use Modules\WorkflowPacks\Services\Engine\Definition;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Listener;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\Engine\Scheduler;
use Modules\WorkflowPacks\Tests\TestCase;

/** Internal flow engine (3.0): blocks, publishing checks, a ticket through a flow with a wait, simulation. */
class EngineTest extends TestCase
{
    const LANGS = ['fr', 'en', 'de', 'es', 'it', 'nl', 'pt-BR'];

    protected function conversation($mailbox, $subject = 'PR #42 corrigée')
    {
        $conversation = new \App\Conversation();
        $conversation->type = \App\Conversation::TYPE_EMAIL;
        $conversation->number = random_int(100000, 999999);
        $conversation->folder_id = (int) optional($mailbox->folders()->first())->id;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->subject = $subject;
        $conversation->customer_email = 'client@example.test';
        $conversation->source_via = \App\Conversation::PERSON_CUSTOMER;
        $conversation->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $conversation->status = \App\Conversation::STATUS_ACTIVE;
        $conversation->state = \App\Conversation::STATE_PUBLISHED;
        $conversation->save();

        return $conversation;
    }

    /** New e-mail -> if subject contains "PR" -> note -> wait 2 h -> closed. */
    protected function flowData()
    {
        return ['blocks' => [
            ['id' => 't', 'type' => 'trigger.new_mail'],
            ['id' => 'if', 'type' => 'condition.ticket', 'params' => ['var' => 'ticket.subject', 'operator' => 'contains', 'value' => 'PR']],
            ['id' => 'n', 'type' => 'action.note', 'params' => ['text' => 'Vu : {{ticket.subject}}']],
            ['id' => 'w', 'type' => 'wait.duration', 'params' => ['delay' => ['number' => 2, 'metric' => 'h']]],
            ['id' => 's', 'type' => 'action.status', 'params' => ['status' => 'closed']],
        ], 'links' => [
            ['from' => 't', 'port' => 'next', 'to' => 'if'],
            ['from' => 'if', 'port' => 'yes', 'to' => 'n'],
            ['from' => 'n', 'port' => 'next', 'to' => 'w'],
            ['from' => 'w', 'port' => 'next', 'to' => 's'],
        ]];
    }

    protected function published(array $data, $mailbox, $admin)
    {
        $flow = Flow::create(['name' => 'PR', 'mailbox_ids' => [$mailbox->id], 'active' => true, 'draft' => $data, 'created_by' => $admin->id]);
        $result = (new Publisher())->publish($flow, $admin);
        $this->assertTrue($result['ok'], json_encode($result['problems']));

        return $flow->fresh();
    }

    public function testEveryBlockDescribesItselfInTheSevenLanguages()
    {
        $registry = new BlockRegistry();
        $this->assertGreaterThanOrEqual(7, count($registry->types()));
        foreach ($registry->types() as $type => $class) {
            $this->assertSame($type, $class::type());
            $this->assertContains($class::family(), BlockRegistry::FAMILIES, $type);
            $this->assertNotEmpty($class::outputs(), $type);
            foreach (self::LANGS as $lang) {
                foreach (['name', 'title', 'help'] as $what) {
                    $key = 'workflowpacks::blocks.'.$type.'.'.$what;
                    $this->assertNotSame($key, trans($key, [], $lang), $type.' '.$what.' '.$lang);
                }
                foreach ($class::outputs() as $output) {
                    $key = 'workflowpacks::blocks.outputs.'.$output;
                    $this->assertNotSame($key, trans($key, [], $lang), $output.' '.$lang);
                }
            }
        }
        // a class that is not a block is ignored (another module's filter)
        $this->assertFalse((new BlockRegistry([\stdClass::class]))->has('stdClass'));
    }

    public function testATicketGoesThroughTheFlowWaitsAndIsClosed()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(Engine::OPTION, true);
        $flow = $this->published($this->flowData(), $a, $admin);
        $conversation = $this->conversation($a);

        $this->assertSame(1, Listener::fire('new_mail', $conversation));
        $run = FlowRun::where('flow_id', $flow->id)->first();
        $this->assertSame(FlowRun::WAITING, $run->state);
        $this->assertSame('w', $run->block);
        $this->assertEqualsWithDelta(7200, $run->wake_at->diffInSeconds(\Carbon\Carbon::now()), 5);
        $this->assertTrue($conversation->threads()->where('type', \App\Thread::TYPE_NOTE)->where('body', 'like', '%PR #42%')->exists(), 'the note, with the subject');
        $this->assertSame((int) \App\Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status, 'not closed yet');
        // once per ticket
        $this->assertSame(0, Listener::fire('new_mail', $conversation));

        // 2 hours later: woken, closed
        $run->update(['wake_at' => \Carbon\Carbon::now()->subMinute()]);
        $this->assertSame(1, (new Scheduler())->tick());
        $this->assertSame(FlowRun::DONE, $run->fresh()->state);
        $this->assertSame((int) \App\Conversation::STATUS_CLOSED, (int) $conversation->fresh()->status);
        $this->assertSame(0, (new Scheduler())->tick(), 'never twice');
        $events = FlowEvent::where('run_id', $run->id)->orderBy('id')->get();
        $this->assertSame(['t', 'if', 'n', 'w', 's'], $events->pluck('block')->all());
        $this->assertSame('yes', $events[1]->output);
        $this->assertSame([['status', 'active', 'closed']], $events[4]->changes);
    }

    public function testTheOtherBranchAndTheMailboxesOfTheFlow()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $flow = $this->published($this->flowData(), $a, $admin);
        $this->assertSame(1, Listener::fire('new_mail', $this->conversation($a, 'Bonjour')));
        $run = FlowRun::where('flow_id', $flow->id)->first();
        $this->assertSame(FlowRun::DONE, $run->state, '"no" leads nowhere: the end');
        $this->assertSame(0, Listener::fire('new_mail', $this->conversation($b)), 'mailbox B is not in the flow');
        $flow->update(['active' => false]);
        $this->assertSame(0, Listener::fire('new_mail', $this->conversation($a)), 'switched off');
    }

    public function testAnErrorFollowsTheErrorOutputOrStopsTheTicket()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(\Modules\WorkflowPacks\Services\Imap\ImapFolders::OPTION_ENABLED, false);
        $data = ['blocks' => [
            ['id' => 't', 'type' => 'trigger.new_mail'],
            ['id' => 'f', 'type' => 'action.imap_folder', 'params' => ['path' => 'Factures']],
        ], 'links' => [['from' => 't', 'to' => 'f']]];
        $flow = $this->published($data, $a, $admin);
        Listener::fire('new_mail', $this->conversation($a));
        $run = FlowRun::where('flow_id', $flow->id)->first();
        $this->assertSame(FlowRun::FAILED, $run->state, 'option off, no "error" link');
        $this->assertSame(3, (int) FlowEvent::where('run_id', $run->id)->where('block', 'f')->value('level'));

        $data['blocks'][] = ['id' => 'n', 'type' => 'action.note', 'params' => ['text' => 'Classement impossible']];
        $data['links'][] = ['from' => 'f', 'port' => 'error', 'to' => 'n'];
        $flow->update(['draft' => $data]);
        (new Publisher())->publish($flow, $admin);
        $conversation = $this->conversation($a);
        Listener::fire('new_mail', $conversation);
        $this->assertSame(FlowRun::DONE, FlowRun::where('conversation_id', $conversation->id)->value('state'));
        $this->assertTrue($conversation->threads()->where('type', \App\Thread::TYPE_NOTE)->exists(), 'the other way');
    }

    public function testPublishingRefusesAnIncompleteFlow()
    {
        $registry = new BlockRegistry();
        $problems = function (array $data) use ($registry) {
            return array_column(Definition::from($data)->validate($registry), 'field', 'block');
        };
        // missing value, unknown type, unreached block, variable not given before, no trigger
        $bad = ['blocks' => [
            ['id' => 't', 'type' => 'trigger.new_mail'],
            ['id' => 'if', 'type' => 'condition.ticket', 'params' => ['var' => 'scan.verdict', 'operator' => 'is', 'value' => '']],
            ['id' => 'x', 'type' => 'action.unknown'],
            ['id' => 'lost', 'type' => 'action.status', 'params' => ['status' => 'closed']],
        ], 'links' => [['from' => 't', 'to' => 'if'], ['from' => 'if', 'port' => 'yes', 'to' => 'x']]];
        $found = Definition::from($bad)->validate($registry);
        $fields = array_map(function ($p) {
            return $p['block'].':'.$p['field'];
        }, $found);
        $this->assertContains('if:value', $fields);
        $this->assertContains('if:var', $fields);
        $this->assertContains('x:type', $fields);
        $this->assertContains('lost:', $fields);
        $this->assertNotEmpty($problems(['blocks' => [['id' => 's', 'type' => 'action.status', 'params' => ['status' => 'closed']]]]), 'no trigger');
        // a loop without a wait is refused, with a wait it is fine
        $loop = ['blocks' => [
            ['id' => 't', 'type' => 'trigger.new_mail'],
            ['id' => 'a', 'type' => 'action.status', 'params' => ['status' => 'pending']],
            ['id' => 'b', 'type' => 'action.status', 'params' => ['status' => 'active']],
        ], 'links' => [['from' => 't', 'to' => 'a'], ['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'a']]];
        $this->assertContains('a', array_column(Definition::from($loop)->validate($registry), 'block'));
        $loop['blocks'][] = ['id' => 'w', 'type' => 'wait.duration', 'params' => ['delay' => ['number' => 1, 'metric' => 'd']]];
        $loop['links'][2] = ['from' => 'b', 'to' => 'w'];
        $loop['links'][] = ['from' => 'w', 'to' => 'a'];
        $this->assertSame([], Definition::from($loop)->validate($registry));
        // the variables offered after the trigger, typed; a closed list with its values
        $vars = Definition::from($this->flowData())->variablesBefore('if', $registry);
        $this->assertSame('choice', $vars['ticket.status']['type']);
        $this->assertSame(['active', 'pending', 'closed', 'spam'], $vars['ticket.status']['values']);
        $this->assertFalse($vars['ticket.subject']['partial']);
    }

    public function testTheSimulationShowsEachStepAndWritesNothing()
    {
        $count = FlowRun::count();
        $trace = (new Engine())->simulate(Definition::from($this->flowData()),
            ['subject' => 'PR #7', 'from' => 'x@example.test', 'status' => 'active', 'user_id' => 0, 'body' => '', 'replied' => false, 'folder' => '']);
        $this->assertSame(['t', 'if', 'n', 'w', 's'], array_column($trace['events'], 'block'));
        $this->assertSame(7200, end($trace['events'])['at'], 'virtual clock: 2 hours later');
        $this->assertSame([['status', 'active', 'closed']], end($trace['events'])['changes']);
        $this->assertSame('closed', end($trace['events'])['ticket']['status']);
        $this->assertSame(FlowRun::DONE, $trace['end']);
        $this->assertSame($count, FlowRun::count(), 'nothing written');
    }

    public function testAClaimedRunIsNeverTakenTwiceAndAStaleOneComesBack()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $flow = $this->published($this->flowData(), $a, $admin);
        Listener::fire('new_mail', $this->conversation($a));
        $run = FlowRun::where('flow_id', $flow->id)->first();
        // another scheduler holds it: skipped
        $run->update(['wake_at' => \Carbon\Carbon::now()->subMinute(), 'state' => FlowRun::RUNNING, 'claimed_at' => \Carbon\Carbon::now()]);
        $this->assertSame(0, (new Scheduler())->tick());
        // that scheduler died 15 minutes ago: put back, then woken
        $run->update(['claimed_at' => \Carbon\Carbon::now()->subMinutes(15)]);
        $this->assertSame(1, (new Scheduler())->tick());
        $this->assertSame(FlowRun::DONE, $run->fresh()->state);
    }
}
