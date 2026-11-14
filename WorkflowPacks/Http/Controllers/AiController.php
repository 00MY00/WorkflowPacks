<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Agent;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Ai\Answer;
use Modules\WorkflowPacks\Services\Ai\Brief;
use Modules\WorkflowPacks\Services\Engine\Engine;

/**
 * AI assistant, without any connection (the AI is used elsewhere, by copy / paste or a file):
 *   1. brief  — tickets (id + text, anonymised by default) + the request + the blocks, for the AI;
 *   2. check  — its answer read, checked and played on those tickets (dry run): what each ticket would get;
 *   3. create — the flow saved as a draft (switched off), opened in the editor.
 * Administrators, when the internal engine is on. Nothing is sent anywhere by the module.
 */
class AiController extends Controller
{
    const SESSION = 'workflowpacks.ai.tickets';

    public function show()
    {
        $this->guardAi();

        return $this->page('ai', $this->data());
    }

    public function brief(Request $request)
    {
        $this->guardAi();
        $mailboxes = array_values(array_intersect(array_map('intval', (array) $request->input('mailbox_ids', [])),
            \App\Mailbox::pluck('id')->map(function ($id) {
                return (int) $id;
            })->all()));
        $brief = (new Brief())->build([
            'mailbox_ids' => $mailboxes,
            'days'        => (int) $request->input('days', 30),
            'limit'       => (int) $request->input('limit', 100),
            'search'      => (string) $request->input('search', ''),
            'task'        => (string) $request->input('task', ''),
            // anonymised unless the administrator explicitly turns it off
            'anonymise'   => !$request->input('full_text'),
        ]);
        // the tickets of this brief: the answer is played on them
        session([self::SESSION => array_column($brief['tickets'], 'id')]);
        $json = json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($request->input('download')) {
            return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="workflowpacks-ai-brief.json"']);
        }

        return $this->page('ai', $this->data(['brief' => $json, 'count' => count($brief['tickets']), 'old' => $request->all()]));
    }

    public function check(Request $request)
    {
        $this->guardAi();
        $answer = (new Answer())->read((string) $request->input('answer'));
        $test = null;
        if ($answer['ok'] && !$answer['problems']) {
            $test = (new Answer())->test($answer['draft'], (array) session(self::SESSION, []));
        }

        return $this->page('ai', $this->data(['answer' => $answer, 'answerText' => (string) $request->input('answer'), 'test' => $test]));
    }

    public function create(Request $request)
    {
        $this->guardAi();
        $answer = (new Answer())->read((string) $request->input('answer'));
        if (!$answer['ok']) {
            return redirect()->route('workflowpacks.ai');
        }
        $flow = Flow::create(['name' => $answer['name'] ?: __('workflowpacks::flows.new_name'), 'mailbox_ids' => [], 'active' => false, 'once' => true,
            'draft' => $answer['draft'], 'created_by' => auth()->id()]);
        \Modules\WorkflowPacks\Services\History::add('flow', 'ok', ['subject' => $flow->name, 'message' => __('workflowpacks::ai.created_history')]);

        return redirect()->route('workflowpacks.flows', ['flow' => $flow->id]);
    }

    /** A connected agent: its token is shown once, on this page, never again. */
    public function createAgent(Request $request)
    {
        $this->guardAi();
        $mailboxes = array_values(array_intersect(array_map('intval', (array) $request->input('mailbox_ids', [])),
            \App\Mailbox::pluck('id')->map(function ($id) {
                return (int) $id;
            })->all()));
        list($agent, $token) = Agent::issue([
            'name'        => mb_substr(trim((string) $request->input('name')) ?: 'Agent', 0, 100),
            'mode'        => $request->input('mode') === Agent::CONTINUOUS ? Agent::CONTINUOUS : Agent::ONCE,
            'mailbox_ids' => $mailboxes,
            'anonymise'   => !$request->input('full_text'),
            'can_publish' => (bool) $request->input('can_publish'),
            'created_by'  => auth()->id(),
        ]);
        \Modules\WorkflowPacks\Services\History::add('agent', 'ok', ['subject' => $agent->name, 'message' => 'connected ('.$agent->mode.')']);

        return $this->page('ai', $this->data(['newToken' => $token, 'newAgent' => $agent]));
    }

    /** Disconnected at once: its token no longer works. */
    public function revokeAgent($id)
    {
        $this->guardAi();
        $agent = Agent::findOrFail((int) $id);
        $agent->revoke();
        \Modules\WorkflowPacks\Services\History::add('agent', 'ok', ['subject' => $agent->name, 'message' => 'disconnected']);

        return redirect()->route('workflowpacks.ai');
    }

    protected function data(array $extra = [])
    {
        return $extra + [
            'agents'    => Agent::orderBy('id', 'desc')->limit(50)->get(),
            'newToken'  => null,
            'newAgent'  => null,
            'apiUrl'    => url(\Helper::getSubdirectory().'/workflow-packs/api/v1'),
            'mailboxes' => \App\Mailbox::orderBy('name')->get(['id', 'name']),
            'brief'     => null,
            'count'     => 0,
            'old'       => [],
            'answer'    => null,
            'answerText' => '',
            'test'      => null,
            'ticketCount' => count((array) session(self::SESSION, [])),
        ];
    }

    protected function guardAi()
    {
        $this->guard();
        if (!auth()->user()->isAdmin() || !Engine::enabled()) {
            \Helper::denyAccess();
        }
    }
}
