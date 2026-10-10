<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Graph\GraphBuilder;
use Modules\WorkflowPacks\Services\Graph\LayoutStore;
use Modules\WorkflowPacks\Services\Graph\RuleEditor;

/**
 * Workflow Packs › Diagram: page + JSON endpoints used by Public/js/graph/*.js. "web" group (CSRF token sent in the
 * X-CSRF-TOKEN header), FreeScout's auth, and the Workflows permission checked per mailbox by the services.
 */
class GraphController extends Controller
{
    public function show()
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }

        return $this->page('graph', []);
    }

    public function data()
    {
        $this->guard();
        if (Checker::blocking()) {
            return response()->json(['error' => implode("\n", Checker::blockingReasons())], 409);
        }
        $user = auth()->user();

        return response()->json((new GraphBuilder())->build($user) + ['layout' => (new LayoutStore())->get($user)]);
    }

    public function saveLayout(Request $request)
    {
        $this->guard();
        $positions = $request->input('positions', []);
        $settings = $request->input('settings', []);
        if (!is_array($positions) || !is_array($settings)) {
            return response()->json(['error' => 'invalid'], 422);
        }
        $saved = (new LayoutStore())->save(auth()->user(), 'main', $positions, $settings, (bool) $request->input('replace'));

        return is_array($saved) ? response()->json(['ok' => true]) : response()->json(['error' => $saved], 422);
    }

    public function rule($id)
    {
        $this->guard();
        $detail = (new RuleEditor())->detail(auth()->user(), (int) $id);

        return $detail ? response()->json($detail) : response()->json(['error' => 'denied'], 403);
    }

    public function catalog($mailboxId)
    {
        $this->guard();
        $catalog = (new RuleEditor())->catalog(auth()->user(), (int) $mailboxId);

        return $catalog ? response()->json($catalog) : response()->json(['error' => 'denied'], 403);
    }

    public function saveRule(Request $request)
    {
        $this->guard();
        $rule = $request->input('rule');
        if (!is_array($rule)) {
            return response()->json(['ok' => false, 'errors' => ['invalid rule']], 422);
        }
        $result = (new RuleEditor())->save(auth()->user(), (int) $request->input('mailbox_id'), $request->input('workflow_id') ? (int) $request->input('workflow_id') : null,
            $rule, (bool) $request->input('activate'));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function toggle(Request $request, $id)
    {
        $this->guard();
        $result = (new RuleEditor())->toggle(auth()->user(), (int) $id, (bool) $request->input('active'));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function apply(Request $request, $id)
    {
        $this->guard();
        $result = (new RuleEditor())->applyTo(auth()->user(), (int) $id, (int) $request->input('mailbox_id'));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
