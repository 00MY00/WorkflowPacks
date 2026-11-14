<?php

namespace Modules\WorkflowPacks\Blocks\Actions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/** Internal note on the ticket; {{ticket.subject}} and the other variables are replaced (escaped). */
class Note extends AbstractBlock
{
    const MAX = 20000;

    public static function type()
    {
        return 'action.note';
    }

    public static function family()
    {
        return 'action';
    }

    public static function fields()
    {
        return ['text' => ['type' => 'textarea', 'required' => true]];
    }

    public static function outputs()
    {
        return ['next', 'error'];
    }

    protected static function titleParams(array $params)
    {
        return ['text' => self::short($params['text'] ?? '', 30)];
    }

    public static function validate(array $params)
    {
        $problems = parent::validate($params);
        if (!$problems && strlen((string) $params['text']) > self::MAX) {
            $problems['text'] = __('workflowpacks::blocks.errors.long', ['max' => self::MAX]);
        }

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        $ctx->actions()->note(nl2br($ctx->render((string) $params['text'])));

        return Result::next('next', self::title($params));
    }
}
