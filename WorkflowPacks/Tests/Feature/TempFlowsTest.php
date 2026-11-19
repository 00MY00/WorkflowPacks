<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowReapply;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Listener;
use Modules\WorkflowPacks\Services\Engine\Reapply;
use Modules\WorkflowPacks\Services\Engine\TempFlows;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Temporary flows: made for a list of tickets (from the page "Tickets no flow acted on" or by another module), launched
 * once on that list only, then deleted by themselves. Never switched on, never shown to agents, never exported.
 */
class TempFlowsTest extends TestCase
{
    protected $told = [];
    protected $finished = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
        Flow::query()->update(['active' => false]);
        Listener::forget();
        \Cache::flush();
        \Eventy::addAction('workflowpacks.ticket_changed', function ($id, $change) {
            $this->told[] = $change;
        }, 20, 2);
        \Eventy::addAction('workflowpacks.temp_flow_finished', function ($id, $summary) {
            $this->finished[] = $summary;
        }, 20, 2);
    }

    protected function ticket($mailbox, $status = \App\Conversation::STATUS_ACTIVE)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->number = random_int(100000, 999999);
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = 'Mail non traité';
        $c->customer_email = 'client@example.test';
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = $status;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();

        return $c;
    }

    /** The page shows each text of $yes and none of $no (PHPUnit 11: assertSee of Laravel 5.5 cannot be used). */
    protected function sees($response, array $yes, array $no = [])
    {
        $html = $response->getContent();
        foreach ($yes as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach ($no as $text) {
            $this->assertStringNotContainsString($text, $html);
        }

        return $response;
    }

    protected static function draft($type = 'action.status', array $params = ['status' => 'closed'])
    {
        return ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail', 'params' => []], ['id' => 'b2', 'type' => $type, 'params' => $params]],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]];
    }

    public function testATemporaryFlowHandlesItsListOnlyThenDeletesItself()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $one = $this->ticket($a);
        $two = $this->ticket($a, \App\Conversation::STATUS_CLOSED);
        $other = $this->ticket($a);
        $this->actingAs($admin);

        $out = \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$one->id, $two->id, 999999999, 'x', $one->id],
            'name' => 'Mails non traités', 'draft' => self::draft('action.status', ['status' => 'pending']), 'origin' => 'refreshglobal', 'launch' => true]);
        $this->assertTrue($out['ok'], json_encode($out));
        $this->assertSame(['running', 2, 2], [$out['state'], $out['tickets'], $out['job']['total']]);
        $flow = Flow::find($out['flow_id']);
        $this->assertTrue($flow->temporary);
        $this->assertFalse($flow->active, 'never switched on');
        $this->assertSame('refreshglobal', $flow->origin);
        $this->assertTrue($flow->expires_at->gt(\Carbon\Carbon::now()->addDays(29)), 'launched: lives 30 days at most');

        // its list only, whatever the status (a closed ticket too); the other ticket untouched
        (new Reapply())->tick();
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $one->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $two->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $other->fresh()->status);
        $this->assertSame(['temporary', 'temporary'], array_column($this->told, 'origin'));
        $this->assertSame([true, true], array_column($this->told, 'temporary'));
        $this->assertSame('done', TempFlows::status($flow->id)['state']);

        // finished: deleted by the scheduler, told to the other modules, written in History
        $this->assertSame(1, TempFlows::cleanup());
        $this->assertNull(Flow::find($flow->id));
        $this->assertSame([['flow_id' => (int) $flow->id, 'flow_name' => 'Mails non traités', 'origin' => 'refreshglobal', 'tickets' => 2, 'handled' => 2, 'reason' => 'done']], $this->finished);
        $this->assertSame('deleted', TempFlows::status($flow->id)['state']);
        $this->assertTrue(\Modules\WorkflowPacks\Entities\Activity::where('subject', 'Mails non traités')->where('message', 'like', '%2%')->exists());
        // its work stays in the statistics, under its name
        $stats = \Eventy::filter('workflowpacks.flow_stats', [], ['field' => 'status']);
        $mine = array_values(array_filter($stats['flows'], function ($f) use ($flow) {
            return $f['flow_id'] === (int) $flow->id;
        }));
        $this->assertSame([2, true, false, 'Mails non traités'], [$mine[0]['tickets'], $mine[0]['temporary'], $mine[0]['exists'], $mine[0]['flow_name']]);
    }

    public function testOnlyAnAdministratorAndOnlyAValidList()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $user = $this->user([$a]);
        $ticket = $this->ticket($a);

        // nobody logged in (scheduler): an active administrator named; anyone else refused
        $this->assertSame('forbidden', \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id]])['error']);
        $this->assertSame('forbidden', \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id], 'user_id' => $user->id])['error']);
        $this->assertTrue(\Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id], 'user_id' => $admin->id])['ok']);
        // logged in: that user, whatever user_id says
        $this->actingAs($user);
        $this->assertSame('forbidden', \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id], 'user_id' => $admin->id])['error']);

        $this->actingAs($admin);
        $this->assertSame('no_tickets', \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => ['x', 0, 999999999]])['error']);
        $this->assertSame(['too_many_tickets', 5000], array_values(array_intersect_key(
            \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => range(1, 5001)]), array_flip(['error', 'max']))));
        $this->assertSame('flow_not_found', \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id], 'from_flow' => 999999999])['error']);
        // a flow that cannot be published: kept as a draft, to finish in the editor
        $bad = \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id], 'draft' => self::draft('action.status', ['status' => '']), 'launch' => true]);
        $this->assertSame(['invalid_flow', 'draft'], [$bad['error'], $bad['state']]);
        $this->assertNotEmpty($bad['problems']);
        $this->assertNotEmpty($bad['edit_url']);
        // deletes tickets: launched only when confirmed apart
        $risky = \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id], 'draft' => self::draft('action.delete', []), 'launch' => true]);
        $this->assertSame('confirm_risky_required', $risky['error']);
        $this->assertSame(\App\Conversation::STATE_PUBLISHED, (int) $ticket->fresh()->state);
        // engine off: nothing
        \Option::set(Engine::OPTION, false);
        $this->assertSame('engine_off', \Eventy::filter('workflowpacks.temp_flow', [], ['ticket_ids' => [$ticket->id]])['error']);
    }

    public function testNeverSwitchedOnNeverShownToAgentsNeverExportedAndDeletedWhenItExpires()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $ticket = $this->ticket($a);
        $this->actingAs($admin);
        $flow = TempFlows::create([$ticket->id], $admin, ['name' => 'Liste temporaire secrète', 'draft' => self::draft()]);
        $this->assertTrue((new \Modules\WorkflowPacks\Services\Engine\Publisher())->publish($flow, $admin)['ok']);

        $this->postJson(route('workflowpacks.flows.toggle', ['id' => $flow->id]), ['active' => 1])->assertStatus(422);
        $bulk = $this->postJson(route('workflowpacks.flows.bulk'), ['action' => 'activate', 'ids' => [$flow->id]])->json();
        $this->assertSame([0, ['Liste temporaire secrète']], [$bulk['done'], $bulk['refused']]);
        $flow->update(['active' => true]);
        $this->assertFalse($flow->fresh()->active, 'whatever asks for it');
        $this->assertSame([], Listener::index(), 'never started by a new e-mail');

        // an agent seeing every flow: not this one, and "not found" when named
        $page = $this->post(route('workflowpacks.ai.agents.create'), ['name' => 'Claude', 'mode' => 'continuous', 'mailbox_ids' => [$a->id],
            'permissions_sent' => 1, 'permissions' => ['others.see', 'others.rename', 'others.reapply']])->assertStatus(200);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$page->original->getData()['newToken'], 'HTTP_ACCEPT' => 'application/json'];
        $list = json_decode($this->call('GET', route('workflowpacks.api.flows'), ['all' => 1], [], [], $headers)->getContent(), true);
        $this->assertNotContains((int) $flow->id, array_column($list['flows'] ?? [], 'id'));
        $this->assertSame(404, $this->call('POST', route('workflowpacks.api.rename', ['id' => $flow->id]), ['name' => 'x'], [], [], $headers)->getStatusCode());
        $this->assertSame(404, $this->call('POST', route('workflowpacks.api.reapply', ['id' => $flow->id]), [], [], [], $headers)->getStatusCode());

        // never offered for export
        $this->sees($this->get(route('workflowpacks.flowpack.export')), [], ['Liste temporaire secrète']);

        // expired (never launched, or tickets still waiting): deleted, the waiting tickets leave it
        $run = FlowRun::create(['flow_id' => $flow->id, 'version_id' => $flow->fresh()->version_id, 'conversation_id' => $ticket->id, 'block' => 'b2',
            'state' => FlowRun::WAITING, 'vars' => []]);
        $this->assertSame(0, TempFlows::cleanup(), 'not yet');
        $flow->update(['expires_at' => \Carbon\Carbon::now()->subMinute()]);
        $this->assertSame(1, TempFlows::cleanup());
        $this->assertNull(Flow::find($flow->id));
        $this->assertSame(FlowRun::CANCELLED, $run->fresh()->state);
        $this->assertSame('expired', $this->finished[0]['reason']);
    }

    public function testThePageOfTheUntreatedTicketsMakesATemporaryFlowLaunchedFromTheEditor()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $one = $this->ticket($a);
        $two = $this->ticket($a, \App\Conversation::STATUS_PENDING);
        $elsewhere = $this->ticket($b);
        $this->actingAs($admin);

        $page = $this->get(route('workflowpacks.untreated', ['sent' => 1, 'mailboxes' => [$a->id], 'statuses' => ['active', 'pending'], 'period' => 45]))->assertStatus(200);
        $this->sees($page, ['>'.$one->number.'<', '>'.$two->number.'<', 'name="period"', 'value="45"'], ['>'.$elsewhere->number.'<', 'workflowpacks::']);
        // no status ticked: none
        $this->sees($this->get(route('workflowpacks.untreated', ['sent' => 1, 'mailboxes' => [$a->id]])), [e(__('workflowpacks::temp.none'))]);

        $res = $this->post(route('workflowpacks.untreated.create'), ['sent' => 1, 'mailboxes' => [$a->id], 'statuses' => ['active', 'pending'], 'period' => 45, 'name' => '']);
        $flow = Flow::where('temporary', true)->latest('id')->first();
        $res->assertRedirect(route('workflowpacks.flows', ['flow' => $flow->id]));
        $this->assertSame([(int) $one->id, (int) $two->id], $flow->tickets());
        $this->assertSame('untreated', $flow->origin);
        $this->assertFalse((bool) $flow->version_id, 'opened as a draft, nothing done');

        // in the editor: marked temporary, with its list
        $data = collect($this->get(route('workflowpacks.flows.data'))->json()['flows'])->first(function ($f) use ($flow) {
            return $f['id'] === (int) $flow->id;
        });
        $this->assertSame([true, 2], [$data['temporary'], $data['tickets']]);
        $this->sees($this->get(route('workflowpacks.flows')), ['wpf-temp', route('workflowpacks.untreated')]);

        // built, then launched from "Tickets existants": its list only, no period or status to choose
        $flow->update(['draft' => self::draft()]);
        $this->sees($this->get(route('workflowpacks.reapply', ['id' => $flow->id]))->assertStatus(200),
            [e(__('workflowpacks::temp.pill', ['count' => 2]))], ['name="scope"', 'name="statuses[]"']);
        $estimate = $this->post(route('workflowpacks.reapply.estimate', ['id' => $flow->id]), ['period' => 7, 'statuses' => ['closed']]);
        $this->assertSame(2, $estimate->original->getData()['estimate']['total'], 'the list, whatever is sent');
        $this->post(route('workflowpacks.reapply.start', ['id' => $flow->id]), ['batch' => 50, 'every' => 1])->assertRedirect();
        $job = FlowReapply::where('flow_id', $flow->id)->first();
        $this->assertSame([2, [(int) $one->id, (int) $two->id]], [(int) $job->total, $job->options['ids']]);
        $this->assertTrue($flow->fresh()->expires_at->gt(\Carbon\Carbon::now()->addDays(29)));
        (new Reapply())->tick();
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $one->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $elsewhere->fresh()->status);
        $this->assertSame(1, TempFlows::cleanup());

        // a user who is not an administrator: no page
        $this->actingAs($this->user([$a]));
        $this->get(route('workflowpacks.untreated'))->assertStatus(403);
    }
}
