<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Modules\WorkflowPacks\Entities\Agent;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowVersion;
use Modules\WorkflowPacks\Services\Ai\Answer;
use Modules\WorkflowPacks\Services\Ai\Brief;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\History;

/**
 * JSON API for a connected AI agent (docs/agent-api.md): "Authorization: Bearer wpa_…". The token is checked on every
 * request (not revoked, not expired), 60 requests a minute at most. The agent reads only the mailboxes it was given
 * (anonymised unless the administrator chose otherwise), proposes flows as drafts (switched off), changes only the
 * flows it created, and publishes only when it was allowed to. Every change is written in History.
 * No session, no cookie: these routes are outside FreeScout's "web" group (routes.php).
 */
class AgentApiController extends BaseController
{
    const PER_MINUTE = 60;

    /** @var Agent */
    protected $agent;

    public function me(Request $request)
    {
        return $this->guarded($request, function (Agent $agent) {
            return ['name' => $agent->name, 'mode' => $agent->mode, 'mailbox_ids' => array_map('intval', (array) $agent->mailbox_ids),
                'anonymise' => $agent->anonymise, 'can_publish' => $agent->can_publish,
                'expires_at' => $agent->expires_at ? $agent->expires_at->toIso8601String() : null];
        });
    }

    /** The blocks and the rules of a flow: the same as in the brief of the AI assistant. */
    public function catalog(Request $request)
    {
        return $this->guarded($request, function () {
            return ['rules' => Brief::RULES, 'blocks' => (new Brief())->blocks(), 'example' => Brief::example()];
        });
    }

    public function tickets(Request $request)
    {
        return $this->guarded($request, function (Agent $agent) use ($request) {
            $mailboxes = $request->input('mailbox_id') ? [(int) $request->input('mailbox_id')] : array_map('intval', (array) $agent->mailbox_ids);
            foreach ($mailboxes as $id) {
                if (!$agent->mayRead($id)) {
                    return $this->fail(403, 'mailbox_denied');
                }
            }

            return ['anonymised' => $agent->anonymise, 'tickets' => (new Brief())->tickets([
                'mailbox_ids' => $mailboxes, 'days' => (int) $request->input('days', 30), 'limit' => (int) $request->input('limit', 100),
                'search' => (string) $request->input('search', ''), 'anonymise' => $agent->anonymise])];
        });
    }

    public function flows(Request $request)
    {
        return $this->guarded($request, function (Agent $agent) {
            $numbers = FlowVersion::pluck('number', 'id');

            return ['flows' => Flow::where('agent_id', $agent->id)->orderBy('id')->get()->map(function (Flow $f) use ($numbers) {
                return ['id' => (int) $f->id, 'name' => $f->name, 'active' => $f->active, 'version' => $numbers[$f->version_id] ?? null,
                    'draft' => $f->draft];
            })->values()];
        });
    }

    /** A new flow (same JSON as the answer of the AI assistant), saved as a draft, switched off. */
    public function propose(Request $request)
    {
        return $this->guarded($request, function (Agent $agent) use ($request) {
            $answer = (new Answer())->read($request->getContent());
            if (!$answer['ok'] || $answer['problems']) {
                return $this->fail(422, 'invalid_flow', $answer['problems']);
            }
            $flow = Flow::create(['name' => $answer['name'] ?: $agent->name, 'mailbox_ids' => array_map('intval', (array) $agent->mailbox_ids),
                'active' => false, 'once' => true, 'draft' => $answer['draft'], 'created_by' => $agent->created_by, 'agent_id' => $agent->id]);
            $this->log($agent, 'proposed', $flow);
            // an access for one use closes after its proposal
            if ($agent->mode === Agent::ONCE) {
                $agent->revoke();
            }

            return ['id' => (int) $flow->id, 'name' => $flow->name, 'state' => 'draft'];
        });
    }

    /** Its own flow changed (draft); the published version keeps running until the next publishing. */
    public function update(Request $request, $id)
    {
        return $this->guarded($request, function (Agent $agent) use ($request, $id) {
            $flow = $this->own($agent, $id);
            if (!$flow) {
                return $this->fail(404, 'not_found');
            }
            $answer = (new Answer())->read($request->getContent());
            if (!$answer['ok'] || $answer['problems']) {
                return $this->fail(422, 'invalid_flow', $answer['problems']);
            }
            $flow->update(['draft' => $answer['draft']] + ($answer['name'] !== '' ? ['name' => $answer['name']] : []));
            $this->log($agent, 'changed', $flow);

            return ['id' => (int) $flow->id, 'state' => 'draft'];
        });
    }

    /** The draft played on tickets of its mailboxes (dry run, nothing written): what each one would get. */
    public function test(Request $request, $id)
    {
        return $this->guarded($request, function (Agent $agent) use ($request, $id) {
            $flow = $this->own($agent, $id);
            if (!$flow) {
                return $this->fail(404, 'not_found');
            }
            $ids = array_map('intval', (array) $request->input('ticket_ids', []));
            $allowed = \App\Conversation::whereIn('id', $ids ?: [0])->get(['id', 'mailbox_id'])->filter(function ($c) use ($agent) {
                return $agent->mayRead($c->mailbox_id);
            })->pluck('id')->all();
            if (!$ids) {
                $allowed = array_column((new Brief())->tickets(['mailbox_ids' => (array) $agent->mailbox_ids, 'limit' => 100, 'anonymise' => true]), 'id');
            }

            return (new Answer())->test((array) $flow->draft, $allowed);
        });
    }

    /** Publish (and switch on) its own flow: only when the administrator allowed it for this agent. */
    public function publish(Request $request, $id)
    {
        return $this->guarded($request, function (Agent $agent) use ($request, $id) {
            if (!$agent->can_publish) {
                return $this->fail(403, 'publish_denied');
            }
            $flow = $this->own($agent, $id);
            if (!$flow) {
                return $this->fail(404, 'not_found');
            }
            $result = (new Publisher())->publish($flow, $agent->created_by ? \App\User::find($agent->created_by) : null);
            if (!$result['ok']) {
                return $this->fail(422, 'invalid_flow', $result['problems']);
            }
            if ($request->input('activate')) {
                $flow->update(['active' => true]);
            }
            $this->log($agent, 'published', $flow);

            return ['id' => (int) $flow->id, 'version' => (int) $result['version']->number, 'active' => (bool) $flow->fresh()->active];
        });
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** Token, expiry, rate; then the action. Errors are JSON with a stable code. */
    protected function guarded(Request $request, \Closure $action)
    {
        if (!Engine::enabled()) {
            return $this->fail(404, 'engine_off');
        }
        $header = (string) $request->header('Authorization');
        $agent = stripos($header, 'Bearer ') === 0 ? Agent::byToken(trim(substr($header, 7))) : null;
        if (!$agent) {
            return $this->fail(401, 'invalid_token');
        }
        $key = 'workflowpacks.agent.rate.'.$agent->id.'.'.date('YmdHi');
        $count = (int) \Cache::get($key, 0) + 1;
        \Cache::put($key, $count, 2);
        if ($count > self::PER_MINUTE) {
            return $this->fail(429, 'too_many_requests');
        }
        $agent->forceFill(['last_used_at' => \Carbon\Carbon::now()])->save();
        $result = $action($agent);

        return $result instanceof \Symfony\Component\HttpFoundation\Response ? $result : response()->json($result);
    }

    protected function own(Agent $agent, $id)
    {
        return Flow::where('id', (int) $id)->where('agent_id', $agent->id)->first();
    }

    protected function fail($status, $code, array $problems = [])
    {
        return response()->json(array_filter(['error' => $code, 'problems' => $problems]), $status);
    }

    protected function log(Agent $agent, $what, Flow $flow)
    {
        History::add('agent', 'ok', ['user_id' => $agent->created_by, 'subject' => $agent->name.' → '.$flow->name, 'message' => $what.' #'.$flow->id]);
    }
}
