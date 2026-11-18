<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Agent;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowReapply;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Listener;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\Engine\Reapply;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Connected agents applying a flow to the tickets already there: the right ("apply to existing tickets"), the limits
 * given by the administrator (statuses, period, tags, assigned users, number of tickets, blocks that delete or
 * notify), always within the mailboxes of the agent; an estimate first, started only with confirm: true.
 */
class AgentReapplyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
        Flow::query()->update(['active' => false]);
        Listener::forget();
        \Cache::flush();
    }

    protected function tearDown(): void
    {
        \App\Module::setActive('refreshglobal', false);
        \App\Module::clearModulesCache();
        parent::tearDown();
    }

    protected function ticket($mailbox, $status = \App\Conversation::STATUS_ACTIVE, $userId = null)
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
        $c->user_id = $userId;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();

        return $c;
    }

    protected function flow($mailbox, $admin, $last = ['type' => 'action.status', 'params' => ['status' => 'pending']], $publish = true)
    {
        $flow = Flow::create(['name' => 'Ancien flux', 'mailbox_ids' => [$mailbox->id], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2'] + $last], 'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]);
        if ($publish) {
            $this->assertTrue((new Publisher())->publish($flow, $admin)['ok']);
        }

        return $flow->fresh();
    }

    protected function agent($admin, array $mailboxes, array $rights, array $limits = [])
    {
        $page = $this->actingAs($admin)->post(route('workflowpacks.ai.agents.create'), ['name' => 'Claude', 'mode' => 'continuous',
            'mailbox_ids' => array_map(function ($m) {
                return $m->id;
            }, $mailboxes), 'permissions_sent' => 1, 'permissions' => $rights] + ($limits ? ['reapply_sent' => 1, 'reapply' => $limits] : []))->assertStatus(200);

        return $page->original->getData()['newToken'];
    }

    protected function api($token, $id, array $body = [], $control = false)
    {
        $res = $this->call('POST', route($control ? 'workflowpacks.api.reapply.control' : 'workflowpacks.api.reapply', ['id' => $id]), [], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body));

        return [$res->getStatusCode(), json_decode($res->getContent(), true)];
    }

    public function testEstimatedThenStartedWithinTheLimitsAndTheMailboxesOfTheAgent()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $tech = $this->user([$a]);
        $mine = $this->ticket($a, \App\Conversation::STATUS_ACTIVE, $tech->id);
        $nobody = $this->ticket($a, \App\Conversation::STATUS_PENDING);
        $closed = $this->ticket($a, \App\Conversation::STATUS_CLOSED, $tech->id);
        $elsewhere = $this->ticket($b);
        $flow = $this->flow($a, $admin, ['type' => 'action.status', 'params' => ['status' => 'closed']]);
        $token = $this->agent($admin, [$a], ['others.see', 'others.reapply']);

        // the estimate (default limits: active and pending, 30 days): nothing done
        list($status, $out) = $this->api($token, $flow->id);
        $this->assertSame(200, $status, json_encode($out));
        $this->assertFalse($out['started']);
        $this->assertSame(2, $out['total']);
        $this->assertSame(['active', 'pending'], $out['filters']['statuses']);
        $this->assertNotEmpty($out['sample']['tickets']);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $mine->fresh()->status);

        // beyond its limits: refused, with what is allowed
        list($status, $out) = $this->api($token, $flow->id, ['statuses' => ['closed']]);
        $this->assertSame([403, 'limit_statuses', ['active', 'pending']], [$status, $out['error'], $out['problems']['allowed']]);
        list($status, $out) = $this->api($token, $flow->id, ['period' => 0]);
        $this->assertSame([403, 'limit_period'], [$status, $out['error']]);
        list($status, $out) = $this->api($token, $flow->id, ['period' => 365]);
        $this->assertSame('limit_period', $out['error']);
        list($status, $out) = $this->api($token, $flow->id, ['tags' => ['facture']]);
        $this->assertSame('tags_unavailable', $out['error'], 'no source of tags here');

        // narrower than its limits: nobody assigned only
        list($status, $out) = $this->api($token, $flow->id, ['assignees' => ['unassigned']]);
        $this->assertSame(1, $out['total']);

        // started: only its tickets, batch by batch, "once per ticket"
        list($status, $out) = $this->api($token, $flow->id, ['confirm' => true]);
        $this->assertSame(200, $status, json_encode($out));
        $this->assertTrue($out['started']);
        $this->assertTrue($out['job']['by_agent']);
        $job = FlowReapply::where('flow_id', $flow->id)->latest('id')->first();
        $this->assertSame(Agent::orderBy('id', 'desc')->first()->id, (int) $job->agent_id);
        $this->assertSame([$a->id], $job->options['mailboxes']);
        (new Reapply())->tick();
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $mine->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $nobody->fresh()->status);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $elsewhere->fresh()->status, 'another mailbox: never');
        $this->assertTrue(\Modules\WorkflowPacks\Entities\Activity::where('kind', 'agent')->where('message', 'like', 'applied to the existing tickets%')->exists());
        list($status, $out) = $this->api($token, $flow->id, ['action' => 'status'], true);
        $this->assertSame([FlowReapply::DONE, 2], [$out['job']['state'], $out['job']['done']]);
        list($status, $out) = $this->api($token, $flow->id, ['statuses' => ['active', 'pending'], 'confirm' => true]);
        $this->assertSame([422, 'nothing_to_do'], [$status, $out['error']], 'once per ticket: done already');
        list($status, $out) = $this->api($token, $flow->id, ['action' => 'stop'], true);
        $this->assertSame([409, 'nothing_running'], [$status, $out['error']]);
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $closed->fresh()->status);

        // a flow of another mailbox, a draft never published, a flow without the right
        list($status, $out) = $this->api($token, $this->flow($b, $admin)->id);
        $this->assertSame([403, 'mailbox_denied'], [$status, $out['error']]);
        list($status, $out) = $this->api($token, $this->flow($a, $admin, ['type' => 'action.status', 'params' => ['status' => 'pending']], false)->id);
        $this->assertSame([409, 'not_published'], [$status, $out['error']]);
        $plain = $this->agent($admin, [$a], ['others.see']);
        list($status, $out) = $this->api($plain, $flow->id);
        $this->assertSame([404, 'not_found'], [$status, $out['error']], 'the right not given: the flow is not revealed');
    }

    public function testLimitsByStatusAssignedUserTagsNumberAndRiskyBlocks()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $tech = $this->user([$a]);
        $other = $this->user([$a]);
        $closedMine = $this->ticket($a, \App\Conversation::STATUS_CLOSED, $tech->id);
        $this->ticket($a, \App\Conversation::STATUS_CLOSED, $other->id);
        $this->ticket($a, \App\Conversation::STATUS_ACTIVE, $tech->id);
        $flow = $this->flow($a, $admin);

        // closed tickets of one technician only; an unknown user id is dropped from the limits
        $token = $this->agent($admin, [$a], ['others.reapply'], ['statuses' => ['closed'], 'period_max' => 7, 'assignees' => [$tech->id, 999999],
            'max_tickets' => 100]);
        $limits = Agent::orderBy('id', 'desc')->first()->reapplyLimits();
        $this->assertSame([['closed'], 7, [$tech->id], 100, false], [$limits['statuses'], $limits['period_max'], $limits['assignees'], $limits['max_tickets'], $limits['risky']]);
        list($status, $out) = $this->api($token, $flow->id);
        $this->assertSame(200, $status, json_encode($out));
        $this->assertSame(1, $out['total']);
        list($status, $out) = $this->api($token, $flow->id, ['assignees' => [$other->id]]);
        $this->assertSame([403, 'limit_assignees'], [$status, $out['error']]);
        list($status, $out) = $this->api($token, $flow->id, ['statuses' => ['active']]);
        $this->assertSame('limit_statuses', $out['error']);

        // more tickets than allowed: refused, with the count, nothing started
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = ['type' => 1, 'number' => 500000 + $i, 'folder_id' => $closedMine->folder_id, 'mailbox_id' => $a->id, 'subject' => 'Lot',
                'customer_email' => 'client@example.test', 'source_via' => 1, 'source_type' => 1, 'status' => \App\Conversation::STATUS_CLOSED,
                'state' => \App\Conversation::STATE_PUBLISHED, 'user_id' => $tech->id, 'created_at' => now(), 'updated_at' => now()];
        }
        \DB::table('conversations')->insert($rows);
        list($status, $out) = $this->api($token, $flow->id, ['confirm' => true]);
        $this->assertSame([422, 'too_many_tickets', 102, 100], [$status, $out['error'], $out['problems']['total'], $out['problems']['max']]);
        $this->assertNull(Reapply::current($flow));

        // tags (e-mail tags of RefreshGlobal here): only the tickets having one
        \App\Module::setActive('refreshglobal', true);
        \App\Module::clearModulesCache();
        \Eventy::addFilter('refreshglobal.mail_tags', function ($default, $ids = []) use ($closedMine) {
            $out = (array) $default;
            foreach ((array) $ids as $id) {
                $out[(int) $id] = (int) $id === (int) $closedMine->id ? ['Facture'] : ['inbox'];
            }

            return $out;
        }, 20, 2);
        $tagged = $this->agent($admin, [$a], ['others.reapply'], ['statuses' => ['closed'], 'tags' => 'facture, devis']);
        $this->assertSame(['facture', 'devis'], Agent::orderBy('id', 'desc')->first()->reapplyLimits()['tags']);
        list($status, $out) = $this->api($tagged, $flow->id);
        $this->assertSame([200, 1], [$status, $out['total'] ?? null], json_encode($out));
        list($status, $out) = $this->api($tagged, $flow->id, ['tags' => ['autre']]);
        $this->assertSame('limit_tags', $out['error']);
        list($status, $out) = $this->api($tagged, $flow->id, ['confirm' => true]);
        (new Reapply())->tick();
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $closedMine->fresh()->status);
        $this->assertSame(1, \App\Conversation::where('mailbox_id', $a->id)->where('status', \App\Conversation::STATUS_PENDING)->count(), 'only the tagged one');

        // a flow that deletes: refused unless allowed, then confirmed separately
        $deleting = $this->flow($a, $admin, ['type' => 'action.delete']);
        list($status, $out) = $this->api($tagged, $deleting->id);
        $this->assertSame([403, 'risky_denied', ['action.delete']], [$status, $out['error'], $out['problems']['blocks']]);
        $bold = $this->agent($admin, [$a], ['others.reapply'], ['statuses' => ['closed'], 'tags' => 'facture', 'risky' => 1]);
        list($status, $out) = $this->api($bold, $deleting->id, ['confirm' => true]);
        $this->assertSame([409, 'confirm_risky_required'], [$status, $out['error']]);
        $this->assertNull(Reapply::current($deleting));
    }

    public function testTheConnectorToolsAndTheFormOfTheAgent()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $this->ticket($a);
        $token = $this->agent($admin, [$a], ['own.propose', 'own.reapply']);
        $rpc = function ($method, array $params) use ($token) {
            return json_decode($this->call('POST', route('workflowpacks.mcp', ['token' => $token]), [], [], [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]))->getContent(), true);
        };
        $names = array_column($rpc('tools/list', [])['result']['tools'], 'name');
        $this->assertContains('reapply_flow', $names);
        $this->assertContains('reapply_control', $names);
        $me = json_decode($rpc('tools/call', ['name' => 'get_me', 'arguments' => []])['result']['content'][0]['text'], true);
        $this->assertTrue($me['can_reapply']);
        $this->assertSame(500, $me['reapply_limits']['max_tickets']);

        // its own flow: published by an administrator, then estimated by the agent
        $agent = Agent::orderBy('id', 'desc')->first();
        $flow = $this->flow($a, $admin);
        $flow->update(['agent_id' => $agent->id]);
        $out = json_decode($rpc('tools/call', ['name' => 'reapply_flow', 'arguments' => ['id' => $flow->id]])['result']['content'][0]['text'], true);
        $this->assertSame([false, 1], [$out['started'], $out['total']], json_encode($out));
        $denied = json_decode($rpc('tools/call', ['name' => 'reapply_flow', 'arguments' => ['id' => $flow->id, 'statuses' => ['spam']]])['result']['content'][0]['text'], true);
        $this->assertSame('limit_statuses', $denied['error']);

        // the page: the row of the right, the limits with their bubbles; changed from the tile
        $page = $this->actingAs($admin)->get(route('workflowpacks.ai'))->getContent();
        $this->assertStringContainsString('value="others.reapply"', $page);
        $this->assertStringContainsString('name="reapply[max_tickets]"', $page);
        $this->assertStringContainsString(e(__('workflowpacks::ai.limit_risky_tip')), $page);
        $this->assertStringNotContainsString('workflowpacks::', strip_tags($page), 'every text exists');
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.update', ['id' => $agent->id]), ['name' => 'Claude', 'mailbox_ids' => [$a->id],
            'permissions_sent' => 1, 'permissions' => ['own.reapply'], 'reapply_sent' => 1, 'reapply' => ['period_max' => 90, 'max_tickets' => 1000]]);
        $limits = $agent->fresh()->reapplyLimits();
        $this->assertSame([[], 90, 1000], [$limits['statuses'], $limits['period_max'], $limits['max_tickets']], 'no status ticked: none');
        $out = json_decode($rpc('tools/call', ['name' => 'reapply_flow', 'arguments' => ['id' => $flow->id]])['result']['content'][0]['text'], true);
        $this->assertSame('limit_statuses', $out['error']);
    }
}
