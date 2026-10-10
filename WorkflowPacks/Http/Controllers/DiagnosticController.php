<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Update\Updater;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Administrators: compatibility checks, versions, catalog of the conditions/actions offered by Workflows on this
 * instance (the identifiers to use in a pack), and the module's update (same mechanism as RefreshGlobal).
 */
class DiagnosticController extends Controller
{
    public function index(Request $request)
    {
        $result = Checker::run(true);
        $catalog = null;
        $mailboxes = collect();
        $mailbox = null;
        if ($result['state'] !== 'blocking') {
            $mailboxes = \App\Mailbox::orderBy('name')->get();
            $mailbox = $mailboxes->firstWhere('id', (int) $request->input('mailbox_id')) ?: $mailboxes->first();
            if ($mailbox) {
                try {
                    $catalog = Bridge::catalog($mailbox->id);
                } catch (\Throwable $e) {
                    $catalog = null;
                }
            }
        }

        return $this->page('diagnostic', [
            'result'    => $result,
            'catalog'   => $catalog,
            'mailboxes' => $mailboxes,
            'mailbox'   => $mailbox,
            'update'    => Updater::status(),
            'updateState' => Updater::state(),
            'autoUpdate' => Updater::enabled(),
        ]);
    }

    public function autoUpdate(Request $request)
    {
        Updater::setEnabled((bool) $request->input('enabled'));

        return redirect()->route('workflowpacks.diagnostic')->with('flash_success_floating', $request->input('enabled')
            ? __('workflowpacks::messages.auto_update_on') : __('workflowpacks::messages.auto_update_off'));
    }

    /** Runs from the scheduler within a minute (never inside a web request). */
    public function updateNow()
    {
        Updater::requestUpdate();

        return redirect()->route('workflowpacks.diagnostic')->with('flash_success_floating', __('workflowpacks::messages.update_requested'));
    }

    public function checkUpdate()
    {
        try {
            $info = (new Updater())->setTimeout(15)->check();
            $message = $info['available']
                ? __('workflowpacks::messages.update_available', ['version' => $info['latest']])
                : __('workflowpacks::messages.up_to_date', ['version' => $info['current']]);

            return redirect()->route('workflowpacks.diagnostic')->with('flash_success_floating', $message);
        } catch (\Throwable $e) {
            return redirect()->route('workflowpacks.diagnostic')->with('flash_error_floating', __('workflowpacks::messages.update_check_failed'));
        }
    }
}
