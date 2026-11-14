<?php

namespace Modules\WorkflowPacks\Blocks\Actions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/** Assign the ticket to a user of its mailbox, or to nobody (-1). */
class Assign extends AbstractBlock
{
    public static function type()
    {
        return 'action.assign';
    }

    public static function family()
    {
        return 'action';
    }

    public static function fields()
    {
        return ['user' => ['type' => 'user', 'required' => true]];
    }

    public static function outputs()
    {
        return ['next', 'error'];
    }

    protected static function titleParams(array $params)
    {
        $id = (int) ($params['user'] ?? 0);
        $user = $id > 0 ? \App\User::find($id) : null;

        return ['user' => $id <= 0 ? __('workflowpacks::blocks.values.nobody') : ($user ? $user->getFullName() : '#'.$id)];
    }

    public static function validate(array $params)
    {
        $problems = parent::validate($params);
        if (!$problems && !preg_match('/^(-1|[1-9][0-9]*)$/', (string) $params['user'])) {
            $problems['user'] = __('workflowpacks::blocks.errors.choice');
        }

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        $id = (int) $params['user'];
        // a user who cannot see the mailbox (removed since): not assigned, the "error" output
        if ($id > 0 && $ctx->conversation) {
            $user = \App\User::find($id);
            if (!$user || !$user->hasAccessToMailbox((int) $ctx->conversation->mailbox_id)) {
                return Result::error(__('workflowpacks::blocks.errors.user'));
            }
        }
        $done = $ctx->actions()->assign($id);

        return Result::next('next', $done ? self::title($params) : __('workflowpacks::blocks.already'));
    }
}
