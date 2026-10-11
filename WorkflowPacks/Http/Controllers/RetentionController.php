<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Services\Imap\Retention;
use Modules\WorkflowPacks\Services\Pack\Problems;

/**
 * Automatic clean-up of a managed e-mail folder (administrators: it acts on the mail server of every mailbox).
 * JSON for the side panel of a folder in the diagram, redirect for the list of Manage › Settings › Workflow Packs.
 */
class RetentionController extends Controller
{
    public function save(Request $request)
    {
        $this->guard();
        abort_if(!auth()->user()->isAdmin(), 403);
        $error = Retention::save($request->input('folder'), ['number' => $request->input('number'), 'metric' => $request->input('metric')],
            (string) $request->input('mode', 'trash'), auth()->user());
        if ($error !== null) {
            return $this->answer($request, false, Problems::t($error, ['type' => '']));
        }

        return $this->answer($request, true, __('workflowpacks::messages.retention_saved'));
    }

    public function remove(Request $request)
    {
        $this->guard();
        abort_if(!auth()->user()->isAdmin(), 403);
        Retention::remove($request->input('folder'));

        return $this->answer($request, true, __('workflowpacks::messages.retention_removed'));
    }

    protected function answer(Request $request, $ok, $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return redirect()->route('settings', ['section' => 'workflowpacks'])
            ->with($ok ? 'flash_success_floating' : 'flash_error_floating', $message);
    }
}
