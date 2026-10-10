<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Modules\WorkflowPacks\Providers\WorkflowPacksServiceProvider;
use Modules\WorkflowPacks\Services\Compat\Checker;

abstract class Controller extends \App\Http\Controllers\Controller
{
    /** 403 unless the user may manage the workflows of at least one mailbox. */
    protected function guard()
    {
        if (!WorkflowPacksServiceProvider::userCanUse()) {
            \Helper::denyAccess();
        }
    }

    /** Page of the module, with the data every view uses (Refresh look, compatibility state). */
    protected function page($view, array $data = [])
    {
        return view('workflowpacks::'.$view, $data + [
            'wpRefresh' => Checker::refreshActive(),
            'compat'    => Checker::run(),
        ]);
    }

    /** Page shown instead of a form when Workflows is missing or not compatible. */
    protected function blockedPage()
    {
        return $this->page('blocked', ['reasons' => Checker::blockingReasons()]);
    }
}
