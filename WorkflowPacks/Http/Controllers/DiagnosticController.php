<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Engine\BlockRegistry;

/**
 * Administrators: state of the module (versions, tables, optional modules), who updates it (FreeScout's Modules page,
 * or UpdateGuard), and the blocks of the flows on this instance (the types to use in a pack of flows).
 */
class DiagnosticController extends Controller
{
    public function index()
    {
        $this->guard();

        return $this->page('diagnostic', [
            'result' => Checker::run(true),
            'blocks' => (new BlockRegistry())->catalog(),
        ]);
    }
}
