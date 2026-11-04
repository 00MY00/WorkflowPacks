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
        $sizes = $request->input('sizes', []);
        if (!is_array($positions) || !is_array($settings) || !is_array($sizes)) {
            return response()->json(['error' => 'invalid'], 422);
        }
        $saved = (new LayoutStore())->save(auth()->user(), 'main', $positions, $settings, (bool) $request->input('replace'), $sizes);

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

    /** Several rules at once (selection of the diagram). */
    public function toggleMany(Request $request)
    {
        $this->guard();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            return response()->json(['ok' => false, 'errors' => ['invalid']], 422);
        }

        return response()->json((new RuleEditor())->toggleMany(auth()->user(), $ids, (bool) $request->input('active')));
    }

    /** "Apply to previous conversations" on / off for several rules (selection of the diagram). */
    public function previousMany(Request $request)
    {
        $this->guard();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            return response()->json(['ok' => false, 'errors' => ['invalid']], 422);
        }

        return response()->json((new RuleEditor())->applyToPreviousMany(auth()->user(), $ids, (bool) $request->input('on')));
    }

    /** "File again" for several rules (selection of the diagram): only their e-mail folder action. */
    public function refileMany(Request $request)
    {
        $this->guard();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            return response()->json(['ok' => false, 'errors' => ['invalid']], 422);
        }

        $result = (new \Modules\WorkflowPacks\Services\Imap\Refiler())->rules(auth()->user(), $ids);
        list($result['message'], $result['warning']) = \Modules\WorkflowPacks\Services\Imap\Refiler::summary($result);

        return response()->json($result);
    }

    /** A fake e-mail through the rules of a mailbox, in memory (Services/Flow/Simulator): nothing written or sent. */
    public function simulate(Request $request)
    {
        $this->guard();
        $input = $request->only(['mailbox_id', 'subject', 'body', 'customer_email', 'customer_name', 'cc', 'tags', 'assigned', 'replied', 'status', 'include_inactive', 'target']);
        $result = (new \Modules\WorkflowPacks\Services\Flow\SimulationService())->run(auth()->user(), $input);

        return response()->json($result, $result['ok'] ? 200 : 403);
    }

    /** "Delete" of selected blocks: what will happen (shown in the confirmation), nothing done. */
    public function deletePlan(Request $request)
    {
        $this->guard();
        $plan = (new \Modules\WorkflowPacks\Services\Graph\BlockRemover())->plan(auth()->user(), (array) $request->input('keys', []));

        return response()->json(['ok' => true, 'count' => $plan['count'], 'lines' => $plan['lines'], 'warnings' => $plan['warnings'],
            'refused' => $plan['refused'], 'token' => $plan['token']]);
    }

    /** "Delete", confirmed: applied after a backup ("Undo"). */
    public function delete(Request $request)
    {
        $this->guard();
        $result = (new \Modules\WorkflowPacks\Services\Graph\BlockRemover())->apply(auth()->user(), (array) $request->input('keys', []), (string) $request->input('token'));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** "Undo" of a deletion: the rules as they were. */
    public function undoDelete(Request $request)
    {
        $this->guard();
        $result = (new \Modules\WorkflowPacks\Services\Graph\BlockRemover())->restore(auth()->user(), (int) $request->input('backup_id'));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** "Test this rule": the fake e-mail made for the rule (filled in the simulation form). */
    public function sample($id)
    {
        $this->guard();
        $result = (new \Modules\WorkflowPacks\Services\Flow\SimulationService())->sample(auth()->user(), (int) $id);

        return response()->json($result, $result['ok'] ? 200 : 403);
    }

    public function apply(Request $request, $id)
    {
        $this->guard();
        $result = (new RuleEditor())->applyTo(auth()->user(), (int) $id, (int) $request->input('mailbox_id'));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
