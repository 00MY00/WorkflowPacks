<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowReapply;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Reapply;

/**
 * A flow applied to the tickets already there: choose (tickets in progress / existing tickets, period, statuses,
 * batches) -> estimate and dry run on a sample -> started, done batch after batch by the scheduler; pause, resume,
 * stop. Administrators, engine on.
 */
class FlowReapplyController extends Controller
{
    public function show(Request $request, $id)
    {
        $flow = $this->flow($id);

        return $this->view($flow, Reapply::options($request->all()), null);
    }

    public function estimate(Request $request, $id)
    {
        $flow = $this->flow($id);
        $o = Reapply::options($request->all());

        return $this->view($flow, $o, $flow->version_id ? (new Reapply())->estimate($flow, $o) : null);
    }

    public function start(Request $request, $id)
    {
        $flow = $this->flow($id);
        $o = Reapply::options($request->all());
        $estimate = (new Reapply())->estimate($flow, $o, false);
        // blocks that delete or notify: started only when it was ticked
        if ($estimate['risky'] && !$request->input('confirm_risky')) {
            return $this->view($flow, $o, (new Reapply())->estimate($flow, $o))->with('wp_risky_missing', true);
        }
        $job = (new Reapply())->start($flow, $o, auth()->user());
        $message = $job ? __('workflowpacks::reapply.started', ['count' => $job->total]) : __('workflowpacks::reapply.nothing');

        return redirect()->route('workflowpacks.reapply', ['id' => $flow->id])->with($job ? 'flash_success_floating' : 'flash_error_floating', $message);
    }

    /** "Publish now" from this page (the version published is the one applied); its problems when it cannot be. */
    public function publish($id)
    {
        $flow = $this->flow($id);
        $result = (new \Modules\WorkflowPacks\Services\Engine\Publisher())->publish($flow, auth()->user());
        $back = redirect()->route('workflowpacks.reapply', ['id' => $flow->id]);
        if ($result['ok']) {
            return $back->with('flash_success_floating', __('workflowpacks::reapply.published_now', ['n' => $result['version']->number]));
        }
        $lines = array_map(function ($p) {
            return $p['message'];
        }, array_slice($result['problems'], 0, 5));

        return $back->with('flash_error_floating', __('workflowpacks::reapply.publish_refused', ['list' => implode(' · ', $lines)]));
    }

    public function control(Request $request, $id)
    {
        $flow = $this->flow($id);
        $job = Reapply::current($flow);
        if ($job) {
            Reapply::control($job, (string) $request->input('action'));
        }

        return redirect()->route('workflowpacks.reapply', ['id' => $flow->id]);
    }

    protected function view(Flow $flow, array $o, $estimate)
    {
        return $this->page('reapply', [
            'flow'     => $flow,
            'o'        => $o,
            'estimate' => $estimate,
            'job'      => Reapply::current($flow) ?: FlowReapply::where('flow_id', $flow->id)->latest('id')->first(),
            'running'  => $flow->version_id ? Reapply::runs($flow)->count() : 0,
        ]);
    }

    protected function flow($id)
    {
        $this->guard();
        if (!auth()->user()->isAdmin() || !Engine::enabled()) {
            \Helper::denyAccess();
        }

        return Flow::with('version')->findOrFail((int) $id);
    }
}
