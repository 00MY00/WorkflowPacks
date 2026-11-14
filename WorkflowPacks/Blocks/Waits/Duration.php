<?php

namespace Modules\WorkflowPacks\Blocks\Waits;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;
use Modules\WorkflowPacks\Services\Pack\Timer;

/** Wait a duration (15 minutes … 90 days or any): the ticket sleeps, the scheduler wakes it. */
class Duration extends AbstractBlock
{
    public static function type()
    {
        return 'wait.duration';
    }

    public static function family()
    {
        return 'wait';
    }

    public static function fields()
    {
        return ['delay' => ['type' => 'duration', 'default' => ['number' => 2, 'metric' => 'h'], 'required' => true]];
    }

    protected static function titleParams(array $params)
    {
        $delay = Timer::check($params['delay'] ?? null)['value'];

        return ['delay' => $delay ? Timer::label($delay) : '?'];
    }

    public static function validate(array $params)
    {
        $check = Timer::check($params['delay'] ?? null);

        return $check['error'] === null ? [] : ['delay' => __('workflowpacks::blocks.errors.delay')];
    }

    public function run(Context $ctx, array $params)
    {
        $delay = Timer::check($params['delay'] ?? null)['value'] ?: ['number' => 1, 'metric' => 'h'];

        return Result::wait($ctx->now->copy()->addSeconds(Timer::seconds($delay)), self::title($params));
    }
}
