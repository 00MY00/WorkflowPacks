<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Engine\Engine;

abstract class Controller extends \App\Http\Controllers\Controller
{
    /** Administrators only (the flows act on every mailbox): 403 for anyone else. */
    protected function guard()
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            \Helper::denyAccess();
        }
    }

    /**
     * Administrators, and the flow engine on (Manage › Settings › Workflow Packs). Engine off: a page saying so, with
     * the way to switch it on again, instead of an error (a JSON answer for the editor's calls).
     */
    protected function guardEngine()
    {
        $this->guard();
        if (!Engine::enabled()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(request()->expectsJson()
                ? response()->json(['ok' => false, 'message' => __('workflowpacks::messages.engine_off')], 409)
                : response($this->page('engine_off')->render(), 200));
        }
    }

    /** Page of the module, with the data every view uses (Refresh look, state of the module). */
    protected function page($view, array $data = [])
    {
        return view('workflowpacks::'.$view, $data + [
            'wpRefresh' => Checker::refreshActive(),
            'compat'    => Checker::run(),
        ]);
    }
}
