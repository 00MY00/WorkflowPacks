<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Administrators: compatibility checks, versions, catalog of the conditions/actions offered by Workflows on this
 * instance (the identifiers to use in a pack), and who updates the module (FreeScout's Modules page, or UpdateGuard).
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
        ]);
    }
}
