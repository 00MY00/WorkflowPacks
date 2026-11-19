<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Definition;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Reapply;
use Modules\WorkflowPacks\Services\Engine\TempFlows;
use Modules\WorkflowPacks\Services\Engine\TicketScope;

/**
 * The tickets no flow has acted on (mailboxes, statuses, last days): how many, the most recent ones, and a temporary
 * flow made for them (empty, or a copy of a flow), opened in the editor; launched from there on that list, then it
 * deletes itself (Services\Engine\TempFlows). Administrators, engine on.
 */
class UntreatedController extends Controller
{
    /** tickets shown on the page */
    const SHOWN = 20;

    public function show(Request $request)
    {
        $this->guardEngine();
        $f = $this->filters($request);
        $query = TempFlows::untreatedQuery($f);

        return $this->page('untreated', [
            'f'         => $f,
            'total'     => (clone $query)->count(),
            'tickets'   => (clone $query)->with('mailbox')->orderBy('id', 'desc')->limit(self::SHOWN)->get(),
            'max'       => TicketScope::MAX_IDS,
            'mailboxes' => \App\Mailbox::orderBy('name')->get(['id', 'name']),
            'flows'     => Flow::permanent()->orderBy('name')->get(['id', 'name', 'version_id']),
        ]);
    }

    public function create(Request $request)
    {
        $this->guardEngine();
        $f = $this->filters($request);
        $back = redirect()->route('workflowpacks.untreated', $this->query($f));
        $ids = TempFlows::untreatedQuery($f)->orderBy('id')->limit(TicketScope::MAX_IDS + 1)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        if (!$ids) {
            return $back->with('flash_error_floating', __('workflowpacks::temp.none'));
        }
        if (count($ids) > TicketScope::MAX_IDS) {
            return $back->with('flash_error_floating', __('workflowpacks::temp.too_many', ['max' => TicketScope::MAX_IDS]));
        }
        // empty (its trigger only), or a copy of a flow (its published version, else its draft)
        $draft = null;
        if ((int) $request->input('from_flow')) {
            $source = Flow::permanent()->with('version')->find((int) $request->input('from_flow'));
            if (!$source) {
                return $back->with('flash_error_floating', __('workflowpacks::temp.flow_not_found'));
            }
            $draft = Definition::clean($source->version ? (array) $source->version->data : (array) ($source->draft ?: []));
        }
        $flow = TempFlows::create($ids, auth()->user(), ['name' => (string) $request->input('name', ''), 'draft' => $draft, 'origin' => 'untreated']);

        return redirect()->route('workflowpacks.flows', ['flow' => $flow->id])
            ->with('flash_success_floating', __('workflowpacks::temp.created', ['count' => count($ids)]));
    }

    /** The filters of the page, checked: existing mailboxes, known statuses, 0 to MAX_DAYS days back. */
    protected function filters(Request $request)
    {
        $known = \App\Mailbox::pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        $mailboxes = array_values(array_intersect(array_map('intval', (array) $request->input('mailboxes', [])), $known));
        $period = Reapply::period($request->input('period', 30));
        // the form sent with no status ticked: none (else the default ones)
        $statuses = $request->input('sent') ? (array) $request->input('statuses', []) : ['active', 'pending'];

        return TicketScope::clean(['mailboxes' => $mailboxes, 'statuses' => $statuses, 'period' => $period === null ? 30 : $period]);
    }

    /** The filters again in the address (back to the page). */
    protected function query(array $f)
    {
        return ['sent' => 1, 'mailboxes' => $f['mailboxes'], 'statuses' => $f['statuses'], 'period' => $f['period']];
    }

    protected function guardEngine()
    {
        $this->guard();
        if (!auth()->user()->isAdmin() || !Engine::enabled()) {
            \Helper::denyAccess();
        }
    }
}
