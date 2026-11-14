<?php

namespace Modules\WorkflowPacks\Blocks\Triggers;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Blocks\Contracts\Trigger;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/** Start: a customer writes a new e-mail (new conversation) in a mailbox of the flow. */
class NewMail extends AbstractBlock implements Trigger
{
    public static function type()
    {
        return 'trigger.new_mail';
    }

    public static function family()
    {
        return 'trigger';
    }

    public static function event()
    {
        return 'new_mail';
    }

    public static function fields()
    {
        return [];
    }

    /** The ticket, as the next blocks can test it. */
    public static function variables(array $params)
    {
        return [
            'ticket.subject'  => ['type' => 'text'],
            'ticket.body'     => ['type' => 'text'],
            'ticket.from'     => ['type' => 'text'],
            'ticket.status'   => ['type' => 'choice', 'values' => array_values(Context::STATUS)],
            'ticket.assigned' => ['type' => 'bool'],
            'ticket.replied'  => ['type' => 'bool'],
        ];
    }

    public function run(Context $ctx, array $params)
    {
        return Result::next('next', self::text(self::type(), 'title'));
    }
}
