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
        // counters of the connector (wrong keys per address, rate) start again for each test
        \Cache::flush();
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
        $raw = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments])->getContent();
        $result = json_decode($raw, true)['result'] ?? null;
        $this->assertNotNull($result, $name.': '.substr((string) $raw, 0, 400));

        return [json_decode($result['content'][0]['text'], true), $result['isError']];
    }

    public function testClaudeSeesTheFlowsAndProposesADraftWithoutTickets()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $existing = Flow::create(['name' => 'Déjà là', 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail']], 'links' => []]]);
        list($token, $page) = $this->agent($admin, ['can_see_all' => 1]);
        // the address of the connector is shown once, with the token
        $this->seeIn($page, route('workflowpacks.mcp', ['token' => $token]));

        $init = json_decode($this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'claude', 'version' => '1']])->assertStatus(200)->getContent(), true);
        $this->assertSame('2025-06-18', $init['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $init['result']['capabilities']);
        $this->rpc($token, 'notifications/initialized', [], null)->assertStatus(202);

        $names = array_column(json_decode($this->rpc($token, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertSame(['get_catalog', 'list_flows', 'simulate_flow', 'propose_flow', 'update_flow', 'test_flow', 'rename_flow'], $names, 'no mailbox: no ticket; not allowed to publish or delete');

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
        // an unknown address: 404 (a 401 would make Claude expect a sign-in), with the reason
        $gone = $this->rpc('wpa_00', 'tools/list');
        $gone->assertStatus(404);
        $this->assertStringContainsString('unknown_address', $gone->getContent());
        $this->call('GET', route('workflowpacks.mcp', ['token' => $token]), [], [], [], ['HTTP_ACCEPT' => 'application/json, text/event-stream'])->assertStatus(405);
        // opened in a browser: a page saying what it is (nothing about the agent); an MCP client asking for a stream: 405
        $page = $this->call('GET', route('workflowpacks.mcp', ['token' => $token]), [], [], [], ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']);
        $page->assertStatus(200);
        $this->seeIn($page, e(__('workflowpacks::ai.mcp_browser_ok')));
        $this->assertStringNotContainsString('Claude test', $page->getContent());
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->call('GET', route('workflowpacks.mcp', ['token' => $token]), [], [], [], ['HTTP_ACCEPT' => 'text/event-stream'])->assertStatus(405);
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
        $this->rpc($token, 'tools/list')->assertStatus(404);
    }

    public function testRightsOfTheAgentAndSecurityOfTheConnection()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        // read only: the flows can be read, nothing proposed or changed
        list($reader) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'read_only' => 1, 'can_publish' => 1]);
        $names = array_column(json_decode($this->rpc($reader, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertSame(['get_catalog', 'list_flows', 'list_tickets', 'simulate_flow'], $names, 'read only wins over "can publish"');
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
            $this->assertSame(404, $r->getStatusCode(), $r->getContent());
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

    public function testAnAgentFlowStaysWithinTheMailboxesGivenAndRequestsAreBounded()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $chain = function (array $block) {
            return ['name' => 'Borne', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2'] + $block],
                'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]];
        };
        // allowed to publish but given no mailbox: a published flow would run on every mailbox, refused
        list($wide) = $this->agent($admin, ['can_publish' => 1]);
        list($made) = $this->tool($wide, 'propose_flow', ['flow' => $chain(['type' => 'action.status', 'params' => ['status' => 'pending']])]);
        list($refused, $isError) = $this->tool($wide, 'publish_flow', ['id' => $made['id']]);
        $this->assertTrue($isError);
        $this->assertSame('mailbox_denied', $refused['error']);
        $this->assertNull(Flow::find($made['id'])->version_id);

        // given mailbox A: a block naming mailbox B, or examples taken from B, are refused
        list($agent) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_publish' => 1]);
        list($out, $isError) = $this->tool($agent, 'propose_flow', ['flow' => $chain(['type' => 'action.imap_folder', 'params' => ['path' => 'X', 'mailbox' => $b->id]])]);
        $this->assertTrue($isError);
        $this->assertSame('mailbox_denied', $out['error']);
        $other = new \App\Conversation();
        $other->type = \App\Conversation::TYPE_EMAIL;
        $other->mailbox_id = $b->id;
        $other->subject = 'Ailleurs';
        $other->state = \App\Conversation::STATE_PUBLISHED;
        $other->status = \App\Conversation::STATUS_ACTIVE;
        $other->source_via = \App\Conversation::PERSON_CUSTOMER;
        $other->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $other->save();
        list($out, $isError) = $this->tool($agent, 'propose_flow', ['flow' => $chain(['type' => 'condition.similar', 'params' => ['examples' => [$other->id], 'threshold' => 50]])]);
        $this->assertTrue($isError);
        $this->assertSame('examples_denied', $out['error']);
        // within its mailbox: proposed and published
        list($made, $isError) = $this->tool($agent, 'propose_flow', ['flow' => $chain(['type' => 'action.status', 'params' => ['status' => 'pending']])]);
        $this->assertFalse($isError);
        list($published, $isError) = $this->tool($agent, 'publish_flow', ['id' => $made['id']]);
        $this->assertFalse($isError, json_encode($published));
        // its flow given every mailbox afterwards: it can no longer publish it
        Flow::where('id', $made['id'])->update(['mailbox_ids' => json_encode([])]);
        list($out, $isError) = $this->tool($agent, 'publish_flow', ['id' => $made['id']]);
        $this->assertSame('mailbox_denied', $out['error']);

        // bounded requests: a batch of more than 20 messages, a body over 1 MB
        $batch = array_map(function ($i) {
            return ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping'];
        }, range(1, 21));
        $this->call('POST', route('workflowpacks.mcp', ['token' => $agent]), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($batch))->assertStatus(413);
        $this->call('POST', route('workflowpacks.api.propose'), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$agent, 'CONTENT_TYPE' => 'application/json'],
            str_repeat(' ', 1048577))->assertStatus(413);
    }

    public function testNamesInMessagesAreEscaped()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        // a pack (from anywhere) with a flow named with markup, that cannot be published: its name in the message, escaped
        $pack = json_encode(['format' => 'workflowpacks-flows', 'version' => 1, 'key' => 'piege', 'name' => 'Piège', 'flows' => [[
            'key' => 'x', 'name' => '<img src=x onerror=alert(1)>', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'condition.ticket', 'params' => []]],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]]);
        $res = $this->actingAs($admin)->post(route('workflowpacks.flowpack.confirm'), ['pack_carry' => $pack, 'after_publish' => 1]);
        $message = (string) $res->getSession()->get('flash_error_floating');
        $this->assertStringContainsString('&lt;img', $message);
        $this->assertStringNotContainsString('<img', $message);
    }

    public function testAnAgentSwitchesOnItsFlowOnlyAfterAPassedTestOfThatVeryDraft()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $ticket = new \App\Conversation();
        $ticket->type = \App\Conversation::TYPE_EMAIL;
        $ticket->mailbox_id = $a->id;
        $ticket->subject = 'Promo de la semaine';
        $ticket->customer_email = 'client@example.test';
        $ticket->state = \App\Conversation::STATE_PUBLISHED;
        $ticket->status = \App\Conversation::STATUS_ACTIVE;
        $ticket->source_via = \App\Conversation::PERSON_CUSTOMER;
        $ticket->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $ticket->save();
        list($token) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_activate' => 1]);
        $names = array_column(json_decode($this->rpc($token, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertContains('activate_flow', $names);
        $this->assertNotContains('publish_flow', $names, 'switching on after a test only, not publishing at will');

        $flow = ['name' => 'Promos', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'action.status', 'params' => ['status' => 'pending']]],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]];
        list($made) = $this->tool($token, 'propose_flow', ['flow' => $flow]);
        // not tested yet: refused
        list($out, $isError) = $this->tool($token, 'activate_flow', ['id' => $made['id']]);
        $this->assertTrue($isError);
        $this->assertSame('test_required', $out['error']);
        // tested and passed: switched on
        list($test) = $this->tool($token, 'test_flow', ['id' => $made['id'], 'ticket_ids' => [$ticket->id]]);
        $this->assertTrue($test['verdict']['passed']);
        $this->assertTrue($test['verdict']['can_activate']);
        // changed after the test: the test no longer counts
        $flow['blocks'][1]['params']['status'] = 'closed';
        $this->tool($token, 'update_flow', ['id' => $made['id'], 'flow' => $flow]);
        list($out) = $this->tool($token, 'activate_flow', ['id' => $made['id']]);
        $this->assertSame('test_required', $out['error']);
        $this->tool($token, 'test_flow', ['id' => $made['id'], 'ticket_ids' => [$ticket->id]]);
        list($on, $isError) = $this->tool($token, 'activate_flow', ['id' => $made['id']]);
        $this->assertFalse($isError, json_encode($on));
        $this->assertTrue($on['tested']);
        $saved = Flow::find($made['id']);
        $this->assertTrue($saved->active);
        $this->assertNotNull($saved->version_id);
        $this->assertSame((int) \App\Conversation::STATUS_ACTIVE, (int) $ticket->fresh()->status, 'the test wrote nothing');
        // a test with no ticket at all does not pass
        list($none) = $this->tool($token, 'test_flow', ['id' => $made['id'], 'ticket_ids' => [999999]]);
        $this->assertFalse($none['verdict']['passed']);

        // through the API too
        list($made2) = $this->tool($token, 'propose_flow', ['flow' => ['name' => 'API'] + $flow]);
        $this->call('POST', route('workflowpacks.api.activate', ['id' => $made2['id']]), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token])->assertStatus(409);
        // read only, or no such right: never
        list($reader) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_activate' => 1, 'read_only' => 1]);
        $this->assertNotContains('activate_flow', array_column(json_decode($this->rpc($reader, 'tools/list')->getContent(), true)['result']['tools'], 'name'));
    }

    public function testTheLengthOfAccessOfAnAgentIsChangedAfterwards()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        list($token) = $this->agent($admin, ['days' => 7]);
        $agent = \Modules\WorkflowPacks\Entities\Agent::orderBy('id', 'desc')->first();
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.expiry', ['id' => $agent->id]), ['days' => 90])->assertSessionHas('flash_success_floating');
        $this->assertSame(90, (int) round(\Carbon\Carbon::now()->diffInHours($agent->fresh()->expires_at) / 24));
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.expiry', ['id' => $agent->id]), ['days' => 0]);
        $this->assertNull($agent->fresh()->expires_at, 'until disconnected');
        // expired: given time again works; disconnected: never brought back
        $agent->update(['expires_at' => \Carbon\Carbon::now()->subDay()]);
        $this->rpc($token, 'tools/list')->assertStatus(404);
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.expiry', ['id' => $agent->id]), ['days' => 30]);
        $this->rpc($token, 'tools/list')->assertStatus(200);
        $agent->fresh()->revoke();
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.expiry', ['id' => $agent->id]), ['days' => 30])->assertSessionHas('flash_error_floating');
        $this->rpc($token, 'tools/list')->assertStatus(404);
        // not for someone else than an administrator, nor an invalid length
        $this->actingAs($this->user([$a], false, true))->post(route('workflowpacks.ai.agents.expiry', ['id' => $agent->id]), ['days' => 30])->assertStatus(403);
    }

    public function testAnAgentIsChangedWithoutANewKeyAndDeletedOnceDisconnected()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        list($token) = $this->agent($admin, []);
        $agent = \Modules\WorkflowPacks\Entities\Agent::orderBy('id', 'desc')->first();
        $tools = function () use ($token) {
            return array_column(json_decode($this->rpc($token, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        };
        $this->assertNotContains('list_tickets', $tools());
        // rights changed: tickets of A, read only; the same address keeps working
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.update', ['id' => $agent->id]), ['name' => 'Claude 2', 'mailbox_ids' => [$a->id], 'read_only' => 1])
            ->assertSessionHas('flash_success_floating');
        $agent = $agent->fresh();
        $this->assertSame('Claude 2', $agent->name);
        $this->assertTrue($agent->read_only);
        $this->assertSame(['get_catalog', 'list_flows', 'list_tickets', 'simulate_flow'], $tools(), 'same key, new rights at once');
        $this->assertTrue(\Modules\WorkflowPacks\Entities\Activity::where('kind', 'agent')->where('message', 'like', 'rights changed%')->exists());
        // an invalid address in the change: nothing changed
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.update', ['id' => $agent->id]), ['name' => 'X', 'allowed_ips' => 'nope'])->assertSessionHas('flash_error_floating');
        $this->assertSame('Claude 2', $agent->fresh()->name);

        // a flow it proposed (rights back to writing), then deletion: refused while it works, done once disconnected
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.update', ['id' => $agent->id]), ['name' => 'Claude 2', 'mailbox_ids' => [$a->id]]);
        list($made) = $this->tool($token, 'propose_flow', ['flow' => ['name' => 'Gardé', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'],
            ['id' => 'b2', 'type' => 'action.status', 'params' => ['status' => 'pending']]], 'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2']]]]);
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.delete', ['id' => $agent->id]))->assertSessionHas('flash_error_floating');
        $this->assertNotNull(\Modules\WorkflowPacks\Entities\Agent::find($agent->id));
        $agent->revoke();
        // a disconnected agent is not changed (a new one is made instead)
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.update', ['id' => $agent->id]), ['name' => 'Revenu'])->assertSessionHas('flash_error_floating');
        $this->actingAs($this->user([$a], false, true))->post(route('workflowpacks.ai.agents.delete', ['id' => $agent->id]))->assertStatus(403);
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.delete', ['id' => $agent->id]))->assertSessionHas('flash_success_floating');
        $this->assertNull(\Modules\WorkflowPacks\Entities\Agent::find($agent->id));
        $kept = Flow::find($made['id']);
        $this->assertNotNull($kept, 'its flows stay');
        $this->assertNull($kept->agent_id);
        $this->rpc($token, 'tools/list')->assertStatus(404);
    }

    protected function similarChain($status = 'pending')
    {
        return ['name' => 'Suivi', 'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'],
            ['id' => 'b2', 'type' => 'condition.ticket', 'params' => ['var' => 'ticket.subject', 'operator' => 'contains', 'value' => 'facture']],
            ['id' => 'b3', 'type' => 'action.status', 'params' => ['status' => $status]]],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2'], ['from' => 'b2', 'port' => 'yes', 'to' => 'b3']]];
    }

    public function testAnAgentSimulatesAFlowFollowsItLiveAndGivesALinkToReplayIt()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        list($token) = $this->agent($admin, ['mailbox_ids' => [$a->id]]);
        list($made) = $this->tool($token, 'propose_flow', ['flow' => $this->similarChain()]);
        // no e-mail given: one is made to take the positive path to the end
        list($sim, $isError) = $this->tool($token, 'simulate_flow', ['id' => $made['id']]);
        $this->assertFalse($isError, json_encode($sim));
        $this->assertTrue($sim['mail_made']);
        $this->assertStringContainsStringIgnoringCase('facture', $sim['mail']['subject']);
        $this->assertSame(['b1', 'b2', 'b3'], array_column($sim['steps'], 'block'));
        $this->assertSame('yes', $sim['steps'][1]['output']);
        $this->assertCount(3, $sim['story']);
        $this->assertNotEmpty($sim['steps'][2]['changes']);
        // an e-mail given: its own path
        list($other) = $this->tool($token, 'simulate_flow', ['id' => $made['id'], 'mail' => ['subject' => 'Bonjour', 'from' => 'a@example.test', 'body' => 'x']]);
        $this->assertFalse($other['mail_made']);
        $this->assertSame('no', $other['steps'][1]['output']);
        // the replay address: the editor plays the same e-mail (administrators)
        $page = $this->actingAs($admin)->get($sim['replay_url'])->assertStatus(200);
        $this->assertStringContainsString(json_encode($sim['mail']['subject']), $page->getContent());
        $this->assertStringNotContainsString('"replay":{', $this->actingAs($admin)->get(route('workflowpacks.flows', ['flow' => $made['id'], 'replay' => str_repeat('a', 24)]))->getContent());

        // streamed: the steps as progress notifications, then the result
        $res = $this->call('POST', route('workflowpacks.mcp', ['token' => $token]), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'],
            json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'simulate_flow', 'arguments' => ['id' => $made['id']], '_meta' => ['progressToken' => 'p1']]]));
        $this->assertStringContainsString('text/event-stream', $res->headers->get('Content-Type'));
        // every chunk kept (the server flushes after each step, as a stream should)
        $stream = '';
        ob_start(function ($chunk) use (&$stream) {
            $stream .= $chunk;

            return '';
        });
        $res->baseResponse->sendContent();
        ob_end_clean();
        $this->assertSame(3, substr_count($stream, 'notifications/progress'));
        $this->assertStringContainsString('"id":7', $stream);
        $this->assertStringContainsString('replay_url', $stream);
        // through the API, as JSON
        $api = json_decode($this->call('POST', route('workflowpacks.api.simulate', ['id' => $made['id']]), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json'])->getContent(), true);
        $this->assertCount(3, $api['steps']);
    }

    public function testRightsOnTheOtherFlows()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $ticket = new \App\Conversation();
        $ticket->type = \App\Conversation::TYPE_EMAIL;
        $ticket->mailbox_id = $a->id;
        $ticket->subject = 'Votre facture';
        $ticket->state = \App\Conversation::STATE_PUBLISHED;
        $ticket->status = \App\Conversation::STATUS_ACTIVE;
        $ticket->source_via = \App\Conversation::PERSON_CUSTOMER;
        $ticket->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $ticket->save();
        $draft = $this->similarChain();
        unset($draft['name']);
        $inA = Flow::create(['name' => 'Flux de A', 'mailbox_ids' => [$a->id], 'active' => false, 'once' => true, 'created_by' => $admin->id, 'draft' => $draft]);
        $inB = Flow::create(['name' => 'Flux de B', 'mailbox_ids' => [$b->id], 'active' => false, 'once' => true, 'created_by' => $admin->id, 'draft' => $draft]);

        // without the rights: neither seen nor simulated
        list($plain) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_activate' => 1]);
        list($flows) = $this->tool($plain, 'list_flows');
        $this->assertSame([], $flows['flows']);
        list($out, $isError) = $this->tool($plain, 'simulate_flow', ['id' => $inA->id]);
        $this->assertSame('not_found', $out['error']);
        list($out) = $this->tool($plain, 'activate_flow', ['id' => $inA->id]);
        $this->assertSame('not_found', $out['error']);

        // seeing the others: listed and simulated, still not published
        list($seer) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_see_all' => 1, 'can_activate' => 1]);
        list($flows) = $this->tool($seer, 'list_flows');
        $this->assertEqualsCanonicalizing(['Flux de A', 'Flux de B'], array_column($flows['flows'], 'name'));
        list($sim, $isError) = $this->tool($seer, 'simulate_flow', ['id' => $inB->id]);
        $this->assertFalse($isError);
        list($out) = $this->tool($seer, 'activate_flow', ['id' => $inA->id]);
        $this->assertSame('not_found', $out['error']);

        // acting on the others of its mailboxes: tested then switched on; another mailbox refused; never changed
        list($actor) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_see_all' => 1, 'can_activate' => 1, 'can_publish_others' => 1]);
        list($test) = $this->tool($actor, 'test_flow', ['id' => $inA->id, 'ticket_ids' => [$ticket->id]]);
        $this->assertTrue($test['verdict']['passed']);
        list($on, $isError) = $this->tool($actor, 'activate_flow', ['id' => $inA->id]);
        $this->assertFalse($isError, json_encode($on));
        $this->assertTrue($inA->fresh()->active);
        $this->tool($actor, 'test_flow', ['id' => $inB->id]);
        list($out) = $this->tool($actor, 'activate_flow', ['id' => $inB->id]);
        $this->assertSame('mailbox_denied', $out['error']);
        $this->assertFalse($inB->fresh()->active);
        list($out) = $this->tool($actor, 'update_flow', ['id' => $inA->id, 'flow' => $this->similarChain('closed')]);
        $this->assertSame('not_found', $out['error']);
        // rights shown and changed from the page
        $agent = \Modules\WorkflowPacks\Entities\Agent::orderBy('id', 'desc')->first();
        $this->assertTrue($agent->can_see_all && $agent->can_publish_others);
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.update', ['id' => $agent->id]), ['name' => 'X', 'mailbox_ids' => [$a->id]]);
        $this->assertFalse($agent->fresh()->can_see_all);
        $this->assertFalse($agent->fresh()->can_publish_others);
    }

    public function testAFlowOnEveryMailboxIsSwitchedOnByAnAgentGivenEveryMailbox()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $all = \App\Mailbox::pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        $ticket = new \App\Conversation();
        $ticket->type = \App\Conversation::TYPE_EMAIL;
        $ticket->mailbox_id = $a->id;
        $ticket->subject = 'Votre facture';
        $ticket->state = \App\Conversation::STATE_PUBLISHED;
        $ticket->status = \App\Conversation::STATUS_ACTIVE;
        $ticket->source_via = \App\Conversation::PERSON_CUSTOMER;
        $ticket->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $ticket->save();
        $draft = $this->similarChain();
        unset($draft['name']);
        // an older flow on every mailbox (none chosen)
        $old = Flow::create(['name' => 'Ancien', 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id, 'draft' => $draft]);

        // an agent given every mailbox: tested, then switched on
        list($every) = $this->agent($admin, ['mailbox_ids' => $all, 'can_see_all' => 1, 'can_activate' => 1, 'can_publish_others' => 1]);
        $this->tool($every, 'test_flow', ['id' => $old->id, 'ticket_ids' => [$ticket->id]]);
        list($on, $isError) = $this->tool($every, 'activate_flow', ['id' => $old->id]);
        $this->assertFalse($isError, json_encode($on));
        $this->assertTrue($old->fresh()->active);

        // an agent given only some mailboxes: still refused (the flow would run on the others too)
        $old->update(['active' => false]);
        list($some) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_see_all' => 1, 'can_activate' => 1, 'can_publish_others' => 1]);
        $this->tool($some, 'test_flow', ['id' => $old->id, 'ticket_ids' => [$ticket->id]]);
        list($out) = $this->tool($some, 'activate_flow', ['id' => $old->id]);
        $this->assertSame('mailbox_denied', $out['error']);
        // a mailbox added afterwards and not given: the agent no longer has every mailbox
        $this->mailbox('C');
        $this->tool($every, 'test_flow', ['id' => $old->id, 'ticket_ids' => [$ticket->id]]);
        list($out) = $this->tool($every, 'activate_flow', ['id' => $old->id]);
        $this->assertSame('mailbox_denied', $out['error']);
    }

    public function testTheTileShowsTheLengthOfAccessItReallyHas()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $this->agent($admin, ['days' => 0]);
        $agent = \Modules\WorkflowPacks\Entities\Agent::orderBy('id', 'desc')->first();
        $this->assertNull($agent->expires_at);
        $page = $this->actingAs($admin)->get(route('workflowpacks.ai'))->getContent();
        $this->assertStringContainsString(e(__('workflowpacks::ai.no_limit_short')), $page);
        $this->assertSame(1, preg_match('#<option value="0"\s+selected\s*>#', $page), 'the menu shows "no limit", not 30 days');
        $this->actingAs($admin)->post(route('workflowpacks.ai.agents.expiry', ['id' => $agent->id]), ['days' => 90]);
        $page = $this->actingAs($admin)->get(route('workflowpacks.ai'))->getContent();
        $this->assertSame(1, preg_match('#<option value="90"\s+selected\s*>#', $page));
    }

    public function testAgentsRenameAndDeleteFlowsTheirOwnAndTheOthersOfTheirMailboxes()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([$a, $b], true);
        $draft = $this->similarChain();
        unset($draft['name']);
        $inA = Flow::create(['name' => 'Autre de A', 'mailbox_ids' => [$a->id], 'active' => true, 'once' => true, 'created_by' => $admin->id, 'draft' => $draft]);
        $inB = Flow::create(['name' => 'Autre de B', 'mailbox_ids' => [$b->id], 'active' => false, 'once' => true, 'created_by' => $admin->id, 'draft' => $draft]);

        // without the right: no delete tool, its own flow renamed only
        list($plain) = $this->agent($admin, ['mailbox_ids' => [$a->id]]);
        $names = array_column(json_decode($this->rpc($plain, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertContains('rename_flow', $names);
        $this->assertNotContains('delete_flow', $names);
        list($made) = $this->tool($plain, 'propose_flow', ['flow' => $this->similarChain()]);
        list($renamed, $isError) = $this->tool($plain, 'rename_flow', ['id' => $made['id'], 'name' => 'Nouveau nom']);
        $this->assertFalse($isError);
        $this->assertSame('Nouveau nom', Flow::find($made['id'])->name);
        list($out) = $this->tool($plain, 'rename_flow', ['id' => $inA->id, 'name' => 'Pris']);
        $this->assertSame('not_found', $out['error'], 'the others: not without the right');

        // with the rights: the others of its mailboxes renamed and deleted (confirmation, backup), another mailbox refused
        list($agent) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'can_see_all' => 1, 'can_publish_others' => 1, 'can_delete' => 1]);
        list($out) = $this->tool($agent, 'rename_flow', ['id' => $inA->id, 'name' => 'Renommé par Claude']);
        $this->assertSame('Renommé par Claude', $inA->fresh()->name);
        $this->assertSame(\Modules\WorkflowPacks\Entities\FlowVersion::where('flow_id', $inA->id)->count(), 0, 'only the name changed');
        list($out) = $this->tool($agent, 'rename_flow', ['id' => $inB->id, 'name' => 'Non']);
        $this->assertSame('mailbox_denied', $out['error']);
        list($out) = $this->tool($agent, 'delete_flow', ['id' => $inA->id]);
        $this->assertSame('confirm_required', $out['error']);
        $this->assertNotNull(Flow::find($inA->id), 'nothing deleted without confirm: true');
        list($deleted, $isError) = $this->tool($agent, 'delete_flow', ['id' => $inA->id, 'confirm' => true]);
        $this->assertFalse($isError, json_encode($deleted));
        $this->assertNull(Flow::find($inA->id));
        $backup = \Modules\WorkflowPacks\Entities\Backup::find($deleted['backup_id']);
        $saved = json_decode($backup->content, true);
        $this->assertSame('Renommé par Claude', $saved['flow']['name']);
        $this->assertCount(1, $saved['pack']['flows'], 'the whole flow kept, to import again');
        $this->actingAs($admin)->get($deleted['backup_url'])->assertStatus(200);
        list($out) = $this->tool($agent, 'delete_flow', ['id' => $inB->id, 'confirm' => true]);
        $this->assertSame('mailbox_denied', $out['error']);
        $this->assertNotNull(Flow::find($inB->id));
        $this->assertTrue(\Modules\WorkflowPacks\Entities\Activity::where('kind', 'agent')->where('message', 'like', 'deleted #%')->exists());
        // through the API: confirmation needed too
        $this->call('POST', route('workflowpacks.api.delete', ['id' => $made['id']]), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$agent])->assertStatus(409);
        $this->assertNotNull(Flow::find($made['id']));
        // read only: neither rename nor delete
        list($reader) = $this->agent($admin, ['mailbox_ids' => [$a->id], 'read_only' => 1, 'can_delete' => 1]);
        $names = array_column(json_decode($this->rpc($reader, 'tools/list')->getContent(), true)['result']['tools'], 'name');
        $this->assertNotContains('rename_flow', $names);
        $this->assertNotContains('delete_flow', $names);
    }
}
