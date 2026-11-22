<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Services\Separation;

/**
 * The separation from Workflows waits for an administrator (Services\Separation): what will be deleted, the backup
 * to download first, then either confirm, or stay on the previous version (nothing is deleted until then), or decide
 * later (the module works meanwhile; a notice stays on its pages).
 */
class SeparationController extends Controller
{
    /** "Later": the home page opens the flows for the rest of the session (the notice stays). */
    const LATER = 'workflowpacks.separation_later';

    public function show()
    {
        $this->guard();
        if (!Separation::pending()) {
            return redirect()->route('workflowpacks.index');
        }

        return $this->page('separation', [
            'plan'        => Separation::plan(),
            'previous'    => Separation::PREVIOUS,
            'releaseUrl'  => Separation::releaseUrl(),
            'zipUrl'      => Separation::zipUrl(),
            'updateGuard' => \Modules\WorkflowPacks\Services\ModuleInfo::updateGuardUrl(),
        ]);
    }

    /** Everything a confirmation would remove, as it is now, to keep before deciding (nothing is changed). */
    public function backup()
    {
        $this->guard();

        return response(json_encode(Separation::backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n", 200, [
            'Content-Type'           => 'application/json; charset=utf-8',
            'Content-Disposition'    => 'attachment; filename="workflowpacks-'.Separation::BACKUP_KEY.'-'.date('Ymd-His').'.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function confirm(Request $request)
    {
        $this->guard();
        if ($request->input('choice') === 'later') {
            session([self::LATER => true]);

            return redirect()->route('workflowpacks.flows');
        }
        // the deletion only with the box ticked (the browser cannot send it by mistake)
        if ($request->input('choice') !== 'confirm' || !$request->input('understood')) {
            return redirect()->route('workflowpacks.separation')->with('flash_error_floating', __('workflowpacks::separation.tick_first'));
        }
        $result = Separation::apply(auth()->user());
        if (!$result['ok']) {
            return redirect()->route($result['error'] === 'nothing' ? 'workflowpacks.index' : 'workflowpacks.separation')
                ->with('flash_error_floating', __('workflowpacks::separation.error_'.$result['error']));
        }

        return redirect()->route('workflowpacks.flows')->with('flash_success_floating', __('workflowpacks::separation.done', [
            'rules' => $result['deleted'], 'url' => route('workflowpacks.backup', ['id' => $result['backup_id']]),
        ]));
    }
}
