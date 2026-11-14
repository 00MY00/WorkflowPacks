<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Activity;
use Modules\WorkflowPacks\Entities\Agent;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Tests\TestCase;

/** API of the connected AI agents: token, expiry, mailboxes, drafts, tests, publishing only when allowed, history. */
class AgentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
    }

    protected function api($method, $route, $token, array $params = [], $body = null)
    {
        $server = ['HTTP_ACCEPT' => 'application/json'] + ($token ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token] : []);
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        return $this->call($method, $route, $body === null ? $params : [], [], [], $server, $body === null ? null : json_encode($body));
    }

    protected function agent($admin, array $form)
    {
        $page = $this->actingAs($admin)->post(route('workflowpacks.ai.agents.create'), $form)->assertStatus(200);

        return $page->original->getData()['newToken'];
    }

    protected function flow($name = 'Agent flow')
    {
        return ['format' => 'workflowpacks-flow', 'version' => 1, 'name' => $name, 'blocks' => [
            ['id' => 'b1', 'type' => 'trigger.new_mail'],
            ['id' => 'b2', 'type' => 'condition.ticket', 'params' => ['var' => 'ticket.subject', 'operator' => 'contains', 'value' => 'promo']],
            ['id' => 'b3', 'type' => 'action.status', 'params' => ['status' => 'closed']],
        ], 'links' => [['from' => 'b1', 'to' => 'b2'], ['from' => 'b2', 'port' => 'yes', 'to' => 'b3']]];
    }

    public function testATokenForOneUseClosesAfterItsProposal()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $token = $this->agent($admin, ['name' => 'Claude', 'mode' => 'once', 'mailbox_ids' => [$a->id]]);
        $this->assertStringStartsWith('wpa_', $token);
        $this->assertNull(Agent::where('token_hash', $token)->first(), 'never kept in clear');

        $this->api('GET', route('workflowpacks.api.me'), null)->assertStatus(401);
        $this->api('GET', route('workflowpacks.api.me'), 'wpa_wrong')->assertStatus(401);
        $me = $this->api('GET', route('workflowpacks.api.me'), $token)->assertStatus(200)->json();
        $this->assertSame('once', $me['mode']);
        $this->assertTrue($me['anonymise']);
        $this->assertNotNull($me['expires_at'], '24 h at most');
        $this->assertNotEmpty($this->api('GET', route('workflowpacks.api.catalog'), $token)->json()['blocks']);
        $this->api('GET', route('workflowpacks.api.tickets'), $token, ['mailbox_id' => $b->id])->assertStatus(403);
        $this->assertTrue($this->api('GET', route('workflowpacks.api.tickets'), $token)->json()['anonymised']);

        $bad = $this->api('POST', route('workflowpacks.api.propose'), $token, [], ['blocks' => [['id' => 'x', 'type' => 'action.nope']]]);
        $bad->assertStatus(422);
        $this->assertNotEmpty($bad->json()['problems']);
        $ok = $this->api('POST', route('workflowpacks.api.propose'), $token, [], $this->flow())->assertStatus(200)->json();
        $flow = Flow::find($ok['id']);
        $this->assertFalse($flow->active);
        $this->assertNull($flow->version_id, 'a draft');
        $this->api('GET', route('workflowpacks.api.me'), $token)->assertStatus(401);
        $this->assertTrue(Activity::where('kind', 'agent')->where('message', 'like', 'proposed%')->exists());
    }

    public function testAContinuousAgentChangesItsFlowsAndPublishesOnlyWhenAllowed()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $token = $this->agent($admin, ['name' => 'Bot', 'mode' => 'continuous', 'mailbox_ids' => [$a->id]]);
        $id = $this->api('POST', route('workflowpacks.api.propose'), $token, [], $this->flow())->json()['id'];
        $this->api('PUT', route('workflowpacks.api.update', ['id' => $id]), $token, [], $this->flow('Renamed'))->assertStatus(200);
        $this->assertSame('Renamed', Flow::find($id)->name);
        $test = $this->api('POST', route('workflowpacks.api.test', ['id' => $id]), $token)->assertStatus(200)->json();
        $this->assertArrayHasKey('groups', $test);
        $this->api('POST', route('workflowpacks.api.publish', ['id' => $id]), $token)->assertStatus(403);
        $this->assertSame(1, count($this->api('GET', route('workflowpacks.api.flows'), $token)->json()['flows']));

        // another agent, allowed to publish: only its own flows
        $publisher = $this->agent($admin, ['name' => 'Publisher', 'mode' => 'continuous', 'mailbox_ids' => [$a->id], 'can_publish' => 1]);
        $this->api('POST', route('workflowpacks.api.publish', ['id' => $id]), $publisher)->assertStatus(404);
        $mine = $this->api('POST', route('workflowpacks.api.propose'), $publisher, [], $this->flow('Published'))->json()['id'];
        $pub = $this->api('POST', route('workflowpacks.api.publish', ['id' => $mine]), $publisher, ['activate' => 1])->assertStatus(200)->json();
        $this->assertSame(1, $pub['version']);
        $this->assertTrue(Flow::find($mine)->active);

        // too many requests in a minute, then disconnected
        $agent = Agent::where('name', 'Bot')->first();
        \Cache::put('workflowpacks.agent.rate.'.$agent->id.'.'.date('YmdHi'), 60, 2);
        $this->api('GET', route('workflowpacks.api.me'), $token)->assertStatus(429);
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.revoke', ['id' => $agent->id]))->assertRedirect();
        $this->api('GET', route('workflowpacks.api.flows'), $token)->assertStatus(401);
        // the engine off: the API is closed
        \Option::set(Engine::OPTION, false);
        $this->api('GET', route('workflowpacks.api.me'), $publisher)->assertStatus(404);
    }
}
