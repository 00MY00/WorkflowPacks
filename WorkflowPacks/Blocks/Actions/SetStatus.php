<?php

namespace Modules\WorkflowPacks\Blocks\Actions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/** Status of the ticket: active, pending, closed or spam. */
class SetStatus extends AbstractBlock
{
    public static function type()
    {
        return 'action.status';
    }

    public static function family()
    {
        return 'action';
    }

    public static function fields()
    {
        return ['status' => ['type' => 'choice', 'values' => array_values(Context::STATUS), 'default' => 'closed', 'required' => true]];
    }

    public static function outputs()
    {
        return ['next', 'error'];
    }

    protected static function titleParams(array $params)
    {
        return ['status' => self::choice('values.status', $params['status'] ?? '')];
    }

    public function run(Context $ctx, array $params)
    {
        $done = $ctx->actions()->status((string) $params['status']);

        return Result::next('next', $done ? self::title($params) : __('workflowpacks::blocks.already'));
    }
}
