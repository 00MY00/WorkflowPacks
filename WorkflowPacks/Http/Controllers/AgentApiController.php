<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Modules\WorkflowPacks\Entities\Agent;
use Modules\WorkflowPacks\Services\Ai\AgentToolError;
use Modules\WorkflowPacks\Services\Ai\AgentTools;
use Modules\WorkflowPacks\Services\Engine\Engine;

/**
 * JSON API for a connected AI agent (docs/agent-api.md): "Authorization: Bearer wpa_…". The token is checked on every
 * request (not revoked, not expired), 60 requests a minute at most. What the agent may do is in Services\Ai\AgentTools
 * (the same for the MCP connector of Claude). No session, no cookie: these routes are outside FreeScout's "web" group.
 */
class AgentApiController extends BaseController
{
    const PER_MINUTE = 60;
    /** wrong tokens from one address in a minute before it is refused (a token cannot be guessed, this keeps it so) */
    const FAILURES_PER_MINUTE = 20;

    public function me(Request $request)
    {
        return $this->guarded($request, function (AgentTools $tools) {
            return $tools->me();
        });
    }

    public function catalog(Request $request)
    {
        return $this->guarded($request, function (AgentTools $tools) {
            return $tools->catalog();
        });
    }

    public function tickets(Request $request)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request) {
            return $tools->tickets($request->only(['mailbox_id', 'days', 'limit', 'search']));
        });
    }

    public function flows(Request $request)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request) {
            return $tools->flows((bool) $request->input('all'));
        });
    }

    public function propose(Request $request)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request) {
            return $tools->propose($request->getContent());
        });
    }

    public function update(Request $request, $id)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request, $id) {
            return $tools->update($id, $request->getContent());
        });
    }

    public function test(Request $request, $id)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request, $id) {
            return $tools->test($id, (array) $request->input('ticket_ids', []));
        });
    }

    public function publish(Request $request, $id)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request, $id) {
            return $tools->publish($id, (bool) $request->input('activate'));
        });
    }

    /** The flow simulated (mail given or made); "Accept: text/event-stream": the steps come one by one (server-sent events). */
    public function simulate(Request $request, $id)
    {
        if (strpos((string) $request->header('Accept'), 'text/event-stream') !== false) {
            $header = (string) $request->header('Authorization');
            list($agent, $status, $code) = self::agentOf(stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : null, $request);
            if (!$agent) {
                return $this->fail($status, $code);
            }
            try {
                $out = (new AgentTools($agent))->simulate($id, $request->input('mail'));
            } catch (AgentToolError $e) {
                return $this->fail($e->status, $e->codeName, $e->problems);
            }

            return self::protect(self::events(function ($send) use ($out) {
                foreach ($out['steps'] as $i => $step) {
                    $send('step', $step + ['story' => $out['story'][$i]]);
                }
                $send('result', $out);
            }));
        }

        return $this->guarded($request, function (AgentTools $tools) use ($request, $id) {
            return $tools->simulate($id, $request->input('mail'));
        });
    }

    /** A response of server-sent events: $produce receives send(event, data), each one written at once. */
    public static function events(\Closure $produce)
    {
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($produce) {
            $produce(function ($event, $data) {
                echo ($event ? 'event: '.$event."\n" : '').'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            });
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        // proxies (nginx, Cloudflare) pass each event at once instead of keeping them
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public function rename(Request $request, $id)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request, $id) {
            return $tools->rename($id, $request->input('name'));
        });
    }

    /** Deleted only with {"confirm": true}; a backup is kept first. */
    public function deleteFlow(Request $request, $id)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($request, $id) {
            return $tools->deleteFlow($id, $request->input('confirm') === true || $request->input('confirm') === 'true');
        });
    }

    public function deactivate(Request $request, $id)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($id) {
            return $tools->deactivate($id);
        });
    }

    public function activate(Request $request, $id)
    {
        return $this->guarded($request, function (AgentTools $tools) use ($id) {
            return $tools->activate($id);
        });
    }

    /**
     * The agent of a request, or the reason of the refusal: [Agent|null, status, code]. In this order: engine on, https
     * (the token never travels in clear; a local address is accepted for tests), too many wrong tokens from this
     * address, the token (not revoked, not expired), the addresses allowed for it, its rate.
     */
    public static function agentOf($token, Request $request)
    {
        if (!Engine::enabled()) {
            return [null, 404, 'engine_off'];
        }
        if (!self::overHttps($request) && !in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)) {
            return [null, 403, 'https_required'];
        }
        $ip = (string) $request->ip();
        $failures = 'workflowpacks.agent.fail.'.md5($ip).'.'.date('YmdHi');
        if ((int) \Cache::get($failures, 0) >= self::FAILURES_PER_MINUTE) {
            return [null, 429, 'too_many_requests'];
        }
        $agent = Agent::byToken($token);
        if (!$agent) {
            \Cache::put($failures, (int) \Cache::get($failures, 0) + 1, 2);

            return [null, 401, 'invalid_token'];
        }
        if (!$agent->allowsIp($ip)) {
            \Modules\WorkflowPacks\Services\History::add('agent', 'failed', ['user_id' => $agent->created_by, 'subject' => $agent->name, 'message' => 'address refused: '.$ip]);

            return [null, 403, 'address_refused'];
        }
        $key = 'workflowpacks.agent.rate.'.$agent->id.'.'.date('YmdHi');
        $count = (int) \Cache::get($key, 0) + 1;
        \Cache::put($key, $count, 2);
        if ($count > self::PER_MINUTE) {
            return [null, 429, 'too_many_requests'];
        }
        $agent->forceFill(['last_used_at' => \Carbon\Carbon::now(), 'last_ip' => mb_substr($ip, 0, 45)])->save();

        return [$agent, 200, ''];
    }

    /**
     * Did this request really come over https? FreeScout's own secure() answers from its configured address (APP_URL),
     * not from the connection, so the connection is read here, with the signs FreeScout itself trusts: HTTPS of the
     * server, the protocol given by a reverse proxy, Cloudflare's visitor scheme.
     */
    public static function overHttps(Request $request)
    {
        return in_array(strtolower((string) $request->server('HTTPS')), ['on', '1'], true)
            || in_array(strtolower((string) $request->server('X_FORWARDED_PROTO')), ['https', 'on', 'ssl', '1'], true)
            || strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https'
            || (string) $request->headers->get('CF-Visitor') === '{"scheme":"https"}';
    }

    /** Answers of the API and the connector: never kept in a cache, never read as another type. */
    public static function protect($response)
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /** Token, expiry, rate; then the action. Errors are JSON with a stable code. */
    protected function guarded(Request $request, \Closure $action)
    {
        $header = (string) $request->header('Authorization');
        list($agent, $status, $code) = self::agentOf(stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : null, $request);
        if (!$agent) {
            return $this->fail($status, $code);
        }
        // a flow is a few kilobytes: anything far bigger is refused before being read
        if (strlen($request->getContent()) > McpController::MAX_BYTES) {
            return $this->fail(413, 'too_large');
        }
        try {
            return self::protect(response()->json($action(new AgentTools($agent))));
        } catch (AgentToolError $e) {
            return $this->fail($e->status, $e->codeName, $e->problems);
        }
    }

    protected function fail($status, $code, array $problems = [])
    {
        return self::protect(response()->json(array_filter(['error' => $code, 'problems' => $problems]), $status));
    }
}
