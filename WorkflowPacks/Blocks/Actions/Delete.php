<?php

namespace Modules\WorkflowPacks\Blocks\Actions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/**
 * Moves the ticket to the "Deleted" folder of FreeScout, as the "Delete" button does: it can be restored from there,
 * and is only deleted for good when someone empties that folder. Last block of a branch: only an "error" output.
 */
class Delete extends AbstractBlock
{
    public static function type()
    {
        return 'action.delete';
    }

    public static function family()
    {
        return 'action';
    }

    public static function fields()
    {
        return [];
    }

    public static function outputs()
    {
        return ['error'];
    }

    public function run(Context $ctx, array $params)
    {
        $done = $ctx->actions()->delete();

        return Result::next('next', $done ? self::title($params) : __('workflowpacks::blocks.already'));
    }
}
