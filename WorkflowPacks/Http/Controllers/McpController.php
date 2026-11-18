<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Modules\WorkflowPacks\Services\Ai\AgentToolError;
use Modules\WorkflowPacks\Services\Ai\AgentTools;
use Modules\WorkflowPacks\Services\ModuleInfo;

/**
 * Connector for Claude (Model Context Protocol, "Streamable HTTP" transport, JSON answers): the address given when a
 * connected agent is created is added in Claude › Settings › Connectors. Claude then reads the catalog and the flows,
 * the tickets of the mailboxes the agent was given (anonymised by default; none given: no ticket), proposes flows as
 * switched-off drafts, tests and changes its own ones, publishes only when allowed — the same as the JSON API
 * (Services\Ai\AgentTools), with the same token, rate and History. No session, no cookie (outside the "web" group).
 *
 * The token is in the address (Claude's custom connectors send no header) or in "Authorization: Bearer"; it is checked
 * on every request and can be disconnected at any time (Workflow Packs › AI assistant).
 */
class McpController extends BaseController
{
    const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    const MAX_BATCH = 20;
    const MAX_BYTES = 1048576;

    public function handle(Request $request, $token = null)
    {
        return AgentApiController::protect($this->reply($request, $token));
    }

    protected function reply(Request $request, $token)
    {
        if (!$request->isMethod('post')) {
            // a person opening the address in a browser: a short page saying what it is (the token is not checked,
            // nothing is shown); an MCP client asking for a stream: none, answers come in the response of each request
            if ($request->isMethod('get') && strpos((string) $request->header('Accept'), 'text/html') !== false
                && strpos((string) $request->header('Accept'), 'text/event-stream') === false) {
                return response()->view('workflowpacks::mcp_browser', [], 200, ['Allow' => 'POST']);
            }

            return response('', 405, ['Allow' => 'POST']);
        }
        $header = (string) $request->header('Authorization');
        $inAddress = (bool) $token;
        $token = $token ?: (stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : null);
        list($agent, $status, $code) = AgentApiController::agentOf($token, $request);
        if (!$agent) {
            // the address holds the key: an unknown, disconnected or expired one is an address that no longer exists
            // (404). A 401 would make Claude believe that a sign-in (OAuth) is expected, which is never the case here.
            if ($inAddress && $code === 'invalid_token') {
                self::noteUnknown($request);

                return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001,
                    'message' => 'unknown_address: this connector address is unknown, disconnected or expired; create a new connected agent in Workflow Packs and use its address']], 404);
            }

            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => $code]], $status);
        }
        // a request of a few kilobytes at most is expected (a flow): anything far bigger is refused before reading it
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'too large']], 413);
        }
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'parse error']], 400);
        }
        $tools = new AgentTools($agent);
        // a simulation followed live (the client reads a stream and gave a progress token): each step sent as it comes,
        // then the result; without a token, the usual JSON answer (sturdier behind proxies)
        if (($body['method'] ?? '') === 'tools/call' && (($body['params']['name'] ?? '') === 'simulate_flow')
            && isset($body['params']['_meta']['progressToken'])
            && strpos((string) $request->header('Accept'), 'text/event-stream') !== false) {
            return $this->streamSimulation($tools, $body);
        }
        $batch = array_keys($body) === range(0, count($body) - 1);
        // a batch of a few messages at most: one request must not stand for hundreds
        if ($batch && count($body) > self::MAX_BATCH) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'batch too large']], 413);
        }
        $answers = [];
        foreach ($batch ? $body : [$body] as $message) {
            $answer = is_array($message) ? $this->answer($tools, $message) : self::error(null, -32600, 'invalid request');
            if ($answer !== null) {
                $answers[] = $answer;
            }
        }
        // only notifications: nothing to answer
        if (!$answers) {
            return response('', 202);
        }

        return response()->json($batch ? $answers : $answers[0]);
    }

    /** One JSON-RPC message; null for a notification (no id). */
    protected function answer(AgentTools $tools, array $m)
    {
        $id = $m['id'] ?? null;
        $method = (string) ($m['method'] ?? '');
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        if (!array_key_exists('id', $m)) {
            return null;
        }
        switch ($method) {
            case 'initialize':
                $asked = (string) ($params['protocolVersion'] ?? '');

                return self::result($id, [
                    'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'workflow-packs', 'title' => 'Workflow Packs (FreeScout)', 'version' => ModuleInfo::version()],
                    'instructions'    => self::INSTRUCTIONS,
                ]);
            case 'ping':
                return self::result($id, new \stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => $this->tools($tools)]);
            case 'tools/call':
                return self::result($id, $this->call($tools, (string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []));
            default:
                return self::error($id, -32601, 'method not found: '.$method);
        }
    }

    const INSTRUCTIONS = 'Workflow Packs automates a FreeScout help desk with flows: linked blocks (trigger, conditions, waits, '
        .'actions). Start with get_catalog (the blocks, the rules and an example), look at the existing flows with list_flows, '
        .'and when the administrator allowed it read tickets with list_tickets (anonymised by default). Propose a new flow '
        .'with propose_flow: it is saved as a switched-off draft that an administrator reviews; test it on tickets with '
        .'test_flow (dry run, nothing is written) and change it with update_flow. When allowed, activate_flow switches on a flow '
        .'whose last test passed. Only the flows you proposed can be changed. '
        .'Nothing is ever sent to customers by these tools.';

    /** The tools this agent may use (publish only when allowed, tickets only when mailboxes were given). */
    protected function tools(AgentTools $tools)
    {
        $none = ['type' => 'object', 'properties' => new \stdClass()];
        $flow = ['type' => 'object', 'description' => 'The flow, as in the example of get_catalog: {"name", "description", "blocks": [...], "links": [...]}'];
        $list = [
            ['name' => 'get_catalog', 'title' => 'Blocks and rules', 'description' => 'The blocks a flow can use (their fields and outputs), the rules of a valid flow and an example. Read it first.',
                'inputSchema' => $none, 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_flows', 'title' => 'Existing flows', 'description' => ($tools->me()['can_see_all'] ? 'Every flow of the help desk' : 'The flows you proposed')
                .' (name, active, published version, mailboxes, blocks and links); "mine" marks the ones you proposed, the only ones you can change.'
                .($tools->me()['can_publish_others'] ? ' You may also test, publish or switch on the others that run only on your mailboxes.' : ''),
                'inputSchema' => $none, 'annotations' => ['readOnlyHint' => true]],
        ];
        if ($tools->mayReadTickets()) {
            $list[] = ['name' => 'list_tickets', 'title' => 'Tickets', 'description' => 'Recent tickets of the mailboxes you were given: id, subject, beginning of the first e-mail (anonymised unless the administrator chose otherwise).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'mailbox_id' => ['type' => 'integer', 'description' => 'One of your mailboxes (default: all of them)'],
                    'days'       => ['type' => 'integer', 'description' => 'Last days (default 30)'],
                    'limit'      => ['type' => 'integer', 'description' => 'At most (default 100, max 200)'],
                    'search'     => ['type' => 'string', 'description' => 'Subject containing'],
                ]], 'annotations' => ['readOnlyHint' => true]];
        }
        // reading only: a simulation writes nothing
        $list[] = ['name' => 'simulate_flow', 'title' => 'Simulate a flow', 'description' => 'Plays a flow step by step with a sample e-mail (dry run, nothing written or sent). '
            .'Without "mail", an e-mail is made to take the positive path to the end (texts near the examples for "Looks like"). Returns each step (block, decision, changes, time), '
            .'a story in plain words to explain it, and replay_url: give it to the user, an administrator sees the simulation played on the diagram. With a progress token, steps are streamed.',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'mail' => ['type' => 'object', 'description' => 'Optional: subject, from, body, status (active|pending|closed|spam), assigned, replied',
                'properties' => ['subject' => ['type' => 'string'], 'from' => ['type' => 'string'], 'body' => ['type' => 'string'], 'status' => ['type' => 'string'],
                    'assigned' => ['type' => 'boolean'], 'replied' => ['type' => 'boolean']]]], 'required' => ['id']],
            'annotations' => ['readOnlyHint' => true]];
        if (!$tools->mayWrite()) {
            return $list;
        }
        $list[] = ['name' => 'propose_flow', 'title' => 'Propose a flow', 'description' => 'Saves a new flow as a switched-off draft (an administrator reviews and publishes it). Its problems are returned when it is not valid.',
            'inputSchema' => ['type' => 'object', 'properties' => ['flow' => $flow], 'required' => ['flow']]];
        $list[] = ['name' => 'update_flow', 'title' => 'Change a flow', 'description' => 'Replaces the draft of one of your flows (the published version keeps running until the next publishing).',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'flow' => $flow], 'required' => ['id', 'flow']]];
        $list[] = ['name' => 'test_flow', 'title' => 'Test a flow', 'description' => 'Plays the draft of one of your flows on tickets (dry run, nothing written): what each ticket would get.',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'],
                'ticket_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Default: recent tickets of your mailboxes']], 'required' => ['id']],
            'annotations' => ['readOnlyHint' => true]];
        if ($tools->mayActivate()) {
            $list[] = ['name' => 'activate_flow', 'title' => 'Switch a flow on', 'description' => 'Publishes and switches on one of your flows. '
                .($tools->me()['can_publish'] ? 'Allowed at once.' : 'Only after test_flow passed on this very draft (unchanged since): test it first.')
                .' It then runs on new e-mails.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
                'annotations' => ['destructiveHint' => true]];
        }
        if ($tools->me()['can_publish']) {
            $list[] = ['name' => 'publish_flow', 'title' => 'Publish a flow', 'description' => 'Publishes one of your flows (and switches it on with activate: true). It then runs on new e-mails.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'activate' => ['type' => 'boolean']], 'required' => ['id']],
                'annotations' => ['destructiveHint' => true]];
        }

        return $list;
    }

    /** A tool called: its result as JSON text; a refusal as a tool error Claude can read (not a protocol error). */
    protected function call(AgentTools $tools, $name, array $a)
    {
        $allowed = array_column($this->tools($tools), 'name');
        try {
            if (!in_array($name, $allowed, true)) {
                throw new AgentToolError(404, 'unknown_tool');
            }
            switch ($name) {
                case 'get_catalog':
                    $out = $tools->catalog();
                    break;
                case 'list_flows':
                    $out = $tools->flows(true);
                    break;
                case 'list_tickets':
                    $out = $tools->tickets($a);
                    break;
                case 'propose_flow':
                    $out = $tools->propose($a['flow'] ?? null);
                    break;
                case 'update_flow':
                    $out = $tools->update($a['id'] ?? 0, $a['flow'] ?? null);
                    break;
                case 'test_flow':
                    $out = $tools->test($a['id'] ?? 0, (array) ($a['ticket_ids'] ?? []));
                    break;
                case 'simulate_flow':
                    $out = $tools->simulate($a['id'] ?? 0, $a['mail'] ?? null);
                    break;
                case 'activate_flow':
                    $out = $tools->activate($a['id'] ?? 0);
                    break;
                default:
                    $out = $tools->publish($a['id'] ?? 0, !empty($a['activate']));
            }

            return ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'isError' => false];
        } catch (AgentToolError $e) {
            $text = json_encode(array_filter(['error' => $e->codeName, 'problems' => $e->problems]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
        }
    }

    /** The steps of a simulation as MCP progress notifications (when the client gave a progress token), then the result. */
    protected function streamSimulation(AgentTools $tools, array $m)
    {
        $id = $m['id'] ?? null;
        $args = is_array($m['params']['arguments'] ?? null) ? $m['params']['arguments'] : [];
        $progress = $m['params']['_meta']['progressToken'] ?? null;

        return AgentApiController::events(function ($send) use ($tools, $id, $args, $progress) {
            try {
                $out = $tools->simulate($args['id'] ?? 0, $args['mail'] ?? null);
            } catch (AgentToolError $e) {
                $send('message', self::result($id, ['content' => [['type' => 'text', 'text' => json_encode(array_filter(['error' => $e->codeName, 'problems' => $e->problems]))]], 'isError' => true]));

                return;
            }
            foreach ($out['story'] as $i => $line) {
                $send('message', ['jsonrpc' => '2.0', 'method' => 'notifications/progress',
                    'params' => ['progressToken' => $progress, 'progress' => $i + 1, 'total' => count($out['story']), 'message' => $line]]);
            }
            $send('message', self::result($id, ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'isError' => false]));
        });
    }

    /** An unknown or disconnected address used: in History once an hour per caller (enough to understand, not a flood). */
    protected static function noteUnknown(Request $request)
    {
        $key = 'workflowpacks.mcp.unknown.'.md5((string) $request->ip()).'.'.date('YmdH');
        if (!\Cache::has($key)) {
            \Cache::put($key, 1, 61);
            \Modules\WorkflowPacks\Services\History::add('agent', 'failed', ['subject' => 'MCP',
                'message' => 'connector address unknown, disconnected or expired (caller '.$request->ip().')']);
        }
    }

    protected static function result($id, $result)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    protected static function error($id, $code, $message)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
