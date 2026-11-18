<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Tests\TestCase;

/** Connector of Claude (MCP): token in the address, the tools of the agent, tickets only when allowed, drafts only. */
class McpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
    }

    protected function agent($admin, array $form)
    {
        $page = $this->actingAs($admin)->post(route('workflowpacks.ai.agents.create'), $form + ['name' => 'Claude', 'mode' => 'continuous'])->assertStatus(200);

        return [$page->original->getData()['newToken'], $page];
    }

    protected function rpc($token, $method, array $params = [], $id = 1)
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params] + ($id === null ? [] : ['id' => $id]);

        return $this->call('POST', route('workflowpacks.mcp', ['token' => $token]), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], json_encode($message));
    }

    protected function tool($token, $name, array $arguments = [])
    {
        $result = json_decode($this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments])->getContent(), true)['result'];

        return [json_decode($result['content'][0]['text'], true), $result['isError']];
    }

    public function testClaudeSeesTheFlowsAndProposesADraftWithoutTickets()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $existing = Flow::create(['name' => 'Déjà là', 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail']], 'links' => []]]);
        list($token, $page) = $this->agent($admin, []);
        // the address of the connector is shown once, with the token
        $this->seeIn($page, route('workflowpacks.mcp', ['token' => $token]));

        $init = json_decode($this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'claude', 'version' => '1']])->assertStatus(200)->getContent(), true);
        $this->assertSame('2025-06-18', $init['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $init['result']['capabilities']);
        $this->rpc($token, 'notifications/initialized', [], null)->assertStatus(202);

        $names = array_column(json_decode($this->rpc($token, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertSame(['get_catalog', 'list_flows', 'propose_flow', 'update_flow', 'test_flow'], $names, 'no mailbox: no ticket; not allowed to publish');

        list($catalog) = $this->tool($token, 'get_catalog');
        $this->assertContains('condition.similar', array_column($catalog['blocks'], 'type'));
        list($flows) = $this->tool($token, 'list_flows');
        $this->assertContains('Déjà là', array_column($flows['flows'], 'name'), 'sees the rules there');
        list($denied, $isError) = $this->tool($token, 'list_tickets');
        $this->assertTrue($isError);
        $this->assertSame('unknown_tool', $denied['error']);

        $flow = ['name' => 'Proposé par Claude', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'action.status', 'params' => ['status' => 'pending']]],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]];
        list($made, $isError) = $this->tool($token, 'propose_flow', ['flow' => $flow]);
        $this->assertFalse($isError);
        $created = Flow::find($made['id']);
        $this->assertSame('Proposé par Claude', $created->name);
        $this->assertFalse($created->active);
        $this->assertNull($created->version_id, 'a draft, not published');
        // only its own flows can be changed
        list($refused, $isError) = $this->tool($token, 'update_flow', ['id' => $existing->id, 'flow' => $flow]);
        $this->assertTrue($isError);
        $this->assertSame('not_found', $refused['error']);
        list($invalid, $isError) = $this->tool($token, 'propose_flow', ['flow' => ['blocks' => [['id' => 'x', 'type' => 'action.nope']]]]);
        $this->assertTrue($isError);
        $this->assertSame('invalid_flow', $invalid['error']);

        $unknown = json_decode($this->rpc($token, 'resources/list')->getContent(), true);
        $this->assertSame(-32601, $unknown['error']['code']);
        $this->rpc('wpa_00', 'tools/list')->assertStatus(401);
        $this->call('GET', route('workflowpacks.mcp', ['token' => $token]))->assertStatus(405);
    }

    public function testWithMailboxesClaudeReadsAnonymisedTicketsAndPublishesWhenAllowed()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        list($token) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_publish' => 1]);
        $names = array_column(json_decode($this->rpc($token, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertContains('list_tickets', $names);
        $this->assertContains('publish_flow', $names);
        list($tickets, $isError) = $this->tool($token, 'list_tickets', ['days' => 30]);
        $this->assertFalse($isError);
        $this->assertTrue($tickets['anonymised']);

        list($made) = $this->tool($token, 'propose_flow', ['flow' => ['name' => 'À publier', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'],
            ['id' => 'b2', 'type' => 'action.status', 'params' => ['status' => 'pending']]], 'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]);
        list($published, $isError) = $this->tool($token, 'publish_flow', ['id' => $made['id'], 'activate' => true]);
        $this->assertFalse($isError);
        $this->assertTrue(Flow::find($made['id'])->active);
        // disconnected: the address no longer works
        \Modules\WorkflowPacks\Entities\Agent::orderBy('id', 'desc')->first()->revoke();
        $this->rpc($token, 'tools/list')->assertStatus(401);
    }

    public function testRightsOfTheAgentAndSecurityOfTheConnection()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        // read only: the flows can be read, nothing proposed or changed
        list($reader) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'read_only' => 1, 'can_publish' => 1]);
        $names = array_column(json_decode($this->rpc($reader, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertSame(['get_catalog', 'list_flows', 'list_tickets'], $names, 'read only wins over "can publish"');
        $api = $this->call('POST', route('workflowpacks.api.propose'), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$reader, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail']], 'links' => []]));
        $api->assertStatus(403);
        $this->assertSame('read_only', json_decode($api->getContent(), true)['error']);
        // a ticket read is written in History; answers are never cached
        $res = $this->rpc($reader, 'tools/call', ['name' => 'list_tickets', 'arguments' => []]);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertTrue(\Modules\WorkflowPacks\Entities\Activity::where('kind', 'agent')->where('message', 'like', 'read %')->exists());

        // allowed addresses: another address is refused (and written in History), the right one works
        list($fenced) = $this->agent($admin, ['allowed_ips' => '203.0.113.0/24']);
        $this->assertSame('address_refused', json_decode($this->rpc($fenced, 'tools/list')->assertStatus(403)->getContent(), true)['error']['message']);
        list($local) = $this->agent($admin, ['allowed_ips' => '127.0.0.1, ::1']);
        $this->rpc($local, 'tools/list')->assertStatus(200);
        // an invalid address: nothing created
        $count = \Modules\WorkflowPacks\Entities\Agent::count();
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.create'), ['name' => 'X', 'allowed_ips' => '300.1.2.3'])->assertSessionHas('flash_error_floating');
        $this->assertSame($count, \Modules\WorkflowPacks\Entities\Agent::count());

        // length of access: 7 days, or until disconnected
        $this->agent($admin, ['days' => 7]);
        $this->assertSame(7, (int) round(\Carbon\Carbon::now()->diffInHours(\Modules\WorkflowPacks\Entities\Agent::orderBy('id', 'desc')->first()->expires_at) / 24));
        $this->agent($admin, ['days' => 0]);
        $this->assertNull(\Modules\WorkflowPacks\Entities\Agent::orderBy('id', 'desc')->first()->expires_at);

        // wrong tokens from one address: refused for the minute, even with a right token
        \Cache::flush();
        for ($i = 0; $i < 20; $i++) {
            $r = $this->rpc('wpa_'.str_repeat('0', 48), 'tools/list');
            $this->assertSame(401, $r->getStatusCode(), $r->getContent());
        }
        $this->rpc($local, 'tools/list')->assertStatus(429);
        \Cache::flush();

        // plain http (not a local address): refused, the token never travels in clear
        $elsewhere = function ($scheme) use ($local) {
            return preg_replace('#^https?://[^/]+#', $scheme.'://helpdesk.example.test', route('workflowpacks.mcp', ['token' => $local]));
        };
        $http = $this->call('POST', $elsewhere('http'), [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        $http->assertStatus(403);
        $this->assertSame('https_required', json_decode($http->getContent(), true)['error']['message']);
        $https = $this->call('POST', $elsewhere('https'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        $this->assertNotSame('https_required', json_decode($https->getContent(), true)['error']['message'] ?? null, 'over https it goes on');
    }

    public function testAddressRanges()
    {
        $range = \Modules\WorkflowPacks\Entities\Agent::class;
        $this->assertTrue($range::inRange('203.0.113.7', '203.0.113.0/24'));
        $this->assertFalse($range::inRange('203.0.114.7', '203.0.113.0/24'));
        $this->assertTrue($range::inRange('10.1.2.3', '10.0.0.0/8'));
        $this->assertTrue($range::inRange('192.168.1.130', '192.168.1.128/25'));
        $this->assertFalse($range::inRange('192.168.1.127', '192.168.1.128/25'));
        $this->assertTrue($range::inRange('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse($range::inRange('2001:db9::1', '2001:db8::/32'));
        $this->assertFalse($range::inRange('203.0.113.7', '2001:db8::/32'), 'IPv4 never in an IPv6 range');
        $this->assertTrue($range::inRange('127.0.0.1', '127.0.0.1'));
        $this->assertSame('203.0.113.0/24', $range::range(' 203.0.113.0/24 '));
        $this->assertNull($range::range('300.1.2.3'));
        $this->assertNull($range::range('10.0.0.0/33'));
        $this->assertNull($range::range('host.example.test'));
    }
}
