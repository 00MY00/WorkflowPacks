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
    /**
     * The rights given to an agent, read from the form (creation or change): name, readable mailboxes, anonymisation,
     * read only, switching on after a test, publishing, allowed addresses. A string: the reason of a refusal.
     *
     * @return array|string
     */
    protected function rights(Request $request)
    {
        $mailboxes = array_values(array_intersect(array_map('intval', (array) $request->input('mailbox_ids', [])),
            \App\Mailbox::pluck('id')->map(function ($id) {
                return (int) $id;
            })->all()));
        // addresses allowed to use the token, separated by commas: an address, a range "/24" or "start - end"; each
        // one valid, else nothing is saved
        $ips = [];
        foreach (preg_split('/[,;
]+/', (string) $request->input('allowed_ips', ''), -1, PREG_SPLIT_NO_EMPTY) as $text) {
            if (trim($text) === '') {
                continue;
            }
            $range = Agent::range($text);
            if ($range === null) {
                return __('workflowpacks::ai.ips_invalid', ['value' => e(mb_substr($text, 0, 60))]);
            }
            $ips[] = $range;
        }
        // the matrix of rights (the form); a request without it (older forms, scripts): the former check boxes
        $rights = $request->input('permissions_sent')
            ? array_values(array_intersect(Agent::PERMISSIONS, (array) $request->input('permissions', [])))
            : Agent::legacyRights($request->only(['read_only', 'can_publish', 'can_activate', 'can_see_all', 'can_publish_others', 'can_delete']));

        return [
            'name'        => mb_substr(trim((string) $request->input('name')) ?: 'Agent', 0, 100),
            'mailbox_ids' => $mailboxes,
            'anonymise'   => !$request->input('full_text'),
            'permissions' => $rights,
            'allowed_ips' => array_values(array_unique($ips)),
        ] + Agent::legacyFlags($rights);
    }

    public function createAgent(Request $request)
    {
        $this->guardAi();
        $rights = $this->rights($request);
        if (is_string($rights)) {
            return redirect()->route('workflowpacks.ai')->with('flash_error_floating', $rights);
        }
        $continuous = $request->input('mode') === Agent::CONTINUOUS;
        $days = (int) $request->input('days', 30);
        $days = in_array($days, Agent::DAYS, true) ? $days : 30;
        list($agent, $token) = Agent::issue($rights + [
            'mode'       => $continuous ? Agent::CONTINUOUS : Agent::ONCE,
            'created_by' => auth()->id(),
        ] + ($continuous && $days ? ['expires_at' => \Carbon\Carbon::now()->addDays($days)] : []));
        \Modules\WorkflowPacks\Services\History::add('agent', 'ok', ['subject' => $agent->name, 'message' => 'connected ('.$agent->mode.')']);

        return $this->page('ai', $this->data(['newToken' => $token, 'newAgent' => $agent]));
    }

    /**
     * Length of access changed (from today): 7 to 365 days, or until disconnected. A disconnected agent stays
     * disconnected (a new one is made instead), an access for one use keeps its 24 hours.
     */
    public function agentExpiry(Request $request, $id)
    {
        $this->guardAi();
        $agent = Agent::findOrFail((int) $id);
        $days = (int) $request->input('days', 30);
        if ($agent->revoked_at || $agent->mode !== Agent::CONTINUOUS || !in_array($days, Agent::DAYS, true)) {
            return redirect()->route('workflowpacks.ai')->with('flash_error_floating', __('workflowpacks::ai.expiry_refused'));
        }
        $agent->update(['expires_at' => $days ? \Carbon\Carbon::now()->addDays($days) : null]);
        \Modules\WorkflowPacks\Services\History::add('agent', 'ok', ['subject' => $agent->name, 'message' => 'length of access: '.($days ? $days.' day(s)' : 'no limit')]);

        return redirect()->route('workflowpacks.ai')->with('flash_success_floating', $days
            ? __('workflowpacks::ai.expiry_changed', ['date' => $agent->expires_at->format('Y-m-d')]) : __('workflowpacks::ai.expiry_none'));
    }

    /**
     * Its rights changed, its key kept (the address of the connector stays the same). Not for a disconnected agent.
     * The change is written in History: what it may read and do, before and after.
     */
    public function updateAgent(Request $request, $id)
    {
        $this->guardAi();
        $agent = Agent::findOrFail((int) $id);
        if ($agent->revoked_at) {
            return redirect()->route('workflowpacks.ai')->with('flash_error_floating', __('workflowpacks::ai.agent_update_refused'));
        }
        $rights = $this->rights($request);
        if (is_string($rights)) {
            return redirect()->route('workflowpacks.ai')->with('flash_error_floating', $rights);
        }
        $before = self::summary($agent);
        $agent->update($rights);
        \Modules\WorkflowPacks\Services\History::add('agent', 'ok', ['subject' => $agent->name, 'message' => 'rights changed: '.$before.' → '.self::summary($agent->fresh())]);

        return redirect()->route('workflowpacks.ai')->with('flash_success_floating', __('workflowpacks::ai.agent_updated', ['name' => e($agent->name)]));
    }

    /**
     * Removed from the list, only once it no longer works (disconnected or expired): its key is gone for good. The flows
     * it proposed stay, as ordinary flows; History keeps what it did.
     */
    public function deleteAgent($id)
    {
        $this->guardAi();
        $agent = Agent::findOrFail((int) $id);
        if ($agent->usable()) {
            return redirect()->route('workflowpacks.ai')->with('flash_error_floating', __('workflowpacks::ai.agent_delete_refused'));
        }
        \Modules\WorkflowPacks\Entities\Flow::where('agent_id', $agent->id)->update(['agent_id' => null]);
        $name = $agent->name;
        $agent->delete();
        \Modules\WorkflowPacks\Services\History::add('agent', 'ok', ['subject' => $name, 'message' => 'deleted']);

        return redirect()->route('workflowpacks.ai')->with('flash_success_floating', __('workflowpacks::ai.agent_deleted', ['name' => e($name)]));
    }

    /** What an agent may read and do, in a few words (for History). */
    protected static function summary(Agent $a)
    {
        return 'mailboxes ['.implode(',', array_map('intval', (array) $a->mailbox_ids)).']'.($a->anonymise ? '' : ' full text')
            .' rights ['.implode(',', $a->rights()).']'
            .(array_filter((array) $a->allowed_ips) ? ' ips '.implode(',', (array) $a->allowed_ips) : '');
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
            // the connector of Claude: the token in the address, shown once with it
            'mcpUrl'    => !empty($extra['newToken']) ? route('workflowpacks.mcp', ['token' => $extra['newToken']]) : null,
            'agentDays' => Agent::DAYS,
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
