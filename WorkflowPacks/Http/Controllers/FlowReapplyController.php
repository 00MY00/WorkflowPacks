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

        return $this->view($flow, $this->options($request, $flow), null);
    }

    public function estimate(Request $request, $id)
    {
        $flow = $this->flow($id);
        $o = $this->options($request, $flow);

        return $this->view($flow, $o, (new Reapply())->estimate($flow, $o));
    }

    public function start(Request $request, $id)
    {
        $flow = $this->flow($id);
        $o = $this->options($request, $flow);
        $estimate = (new Reapply())->estimate($flow, $o, false);
        // blocks that delete or notify: started only when it was ticked
        if ($estimate['risky'] && !$request->input('confirm_risky')) {
            return $this->view($flow, $o, (new Reapply())->estimate($flow, $o))->with('wp_risky_missing', true);
        }
        // never published: published here, then applied (one step; its problems on the page when it cannot be)
        if (!$flow->version_id) {
            $published = $this->publishFlow($flow);
            if ($published !== true) {
                return $published;
            }
            $flow = $flow->fresh(['version']);
        }
        $job = (new Reapply())->start($flow, $o, auth()->user());
        if ($job && $flow->temporary) {
            \Modules\WorkflowPacks\Services\Engine\TempFlows::started($flow);
        }
        $message = $job ? __('workflowpacks::reapply.started', ['count' => $job->total]) : __('workflowpacks::reapply.nothing');

        return redirect()->route('workflowpacks.reapply', ['id' => $flow->id])->with($job ? 'flash_success_floating' : 'flash_error_floating', $message);
    }

    /** "Publish now" from this page (the version published is the one applied); its problems when it cannot be. */
    public function publish($id)
    {
        $flow = $this->flow($id);
        $published = $this->publishFlow($flow);

        return $published === true
            ? redirect()->route('workflowpacks.reapply', ['id' => $flow->id])->with('flash_success_floating', __('workflowpacks::reapply.published_now', ['n' => $flow->fresh(['version'])->version->number]))
            : $published;
    }

    /** True when published, else the way back to this page with the problems to fix (shown on the page). */
    protected function publishFlow(Flow $flow)
    {
        $result = (new \Modules\WorkflowPacks\Services\Engine\Publisher())->publish($flow, auth()->user());
        $back = redirect()->route('workflowpacks.reapply', ['id' => $flow->id]);
        if ($result['ok']) {
            return true;
        }
        // shown on the page itself (not a floating message that goes away): what to fix in the editor
        $registry = new \Modules\WorkflowPacks\Services\Engine\BlockRegistry();
        $titles = [];
        foreach ((array) (($flow->draft ?: [])['blocks'] ?? []) as $block) {
            $class = $registry->get($block['type'] ?? '');
            $titles[$block['id'] ?? ''] = $class ? $class::title((array) ($block['params'] ?? [])) : (string) ($block['type'] ?? '?');
        }
        $lines = array_map(function ($p) use ($titles) {
            return (!empty($p['block']) && isset($titles[$p['block']]) ? $titles[$p['block']].' — ' : '').$p['message'];
        }, array_slice($result['problems'], 0, 10));

        return $back->with('wp_publish_problems', $lines);
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

    /** The choices of the page; a temporary flow: its list of tickets only (any status, any date). */
    protected function options(Request $request, Flow $flow)
    {
        $o = Reapply::options($request->all());

        return $flow->temporary ? \Modules\WorkflowPacks\Services\Engine\TempFlows::options($flow, $o) : $o;
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
