<?php

namespace Modules\WorkflowPacks\Blocks\Actions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;
use Modules\WorkflowPacks\Services\Notify\WebPush;

/**
 * Push notification to agents' phones and computers, through the WebPush module: the assignee, every user of the
 * mailbox, or one user. {{ticket.subject}} and the other variables are replaced. Without the module: "error" output.
 */
class Push extends AbstractBlock
{
    const TO = ['assignee', 'mailbox', 'user'];

    public static function type()
    {
        return 'action.push';
    }

    public static function family()
    {
        return 'action';
    }

    public static function fields()
    {
        return [
            'to'    => ['type' => 'choice', 'values' => self::TO, 'default' => 'assignee', 'required' => true],
            'user'  => ['type' => 'user'],
            'title' => ['type' => 'text', 'default' => '{{ticket.subject}}'],
            'text'  => ['type' => 'textarea', 'required' => true],
        ];
    }

    public static function outputs()
    {
        return ['next', 'error'];
    }

    protected static function titleParams(array $params)
    {
        return ['to' => self::choice('values.to', $params['to'] ?? 'assignee')];
    }

    public static function validate(array $params)
    {
        $problems = parent::validate($params);
        if (!isset($problems['to']) && !in_array($params['to'], self::TO, true)) {
            $problems['to'] = __('workflowpacks::blocks.errors.choice');
        }
        if (($params['to'] ?? '') === 'user' && (int) ($params['user'] ?? 0) <= 0) {
            $problems['user'] = __('workflowpacks::blocks.errors.required');
        }
        if (!isset($problems['text']) && mb_strlen((string) $params['text']) > WebPush::MAX_TEXT) {
            $problems['text'] = __('workflowpacks::blocks.errors.long', ['max' => WebPush::MAX_TEXT]);
        }

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        if (!WebPush::available()) {
            return Result::error(__('workflowpacks::blocks.errors.push_off'));
        }
        $users = self::recipients($ctx, $params);
        if (!$users) {
            return Result::error(__('workflowpacks::blocks.errors.push_nobody'));
        }
        // plain text: the variables are replaced, then the escaping of render() (meant for HTML) is undone
        $plain = function ($text) use ($ctx) {
            return html_entity_decode($ctx->render((string) $text), ENT_QUOTES, 'UTF-8');
        };
        $title = trim($plain($params['title'] ?? '')) ?: (string) ($ctx->ticket['subject'] ?? '');
        $ctx->actions()->push($users, $title, $plain($params['text']));

        return Result::next('next', self::title($params));
    }

    /** @return \App\User[] active users who can see the mailbox of the ticket */
    protected static function recipients(Context $ctx, array $params)
    {
        $mailboxId = (int) ($ctx->ticket['mailbox_id'] ?? 0);
        $to = (string) ($params['to'] ?? 'assignee');
        if ($to === 'mailbox') {
            $mailbox = \App\Mailbox::find($mailboxId);

            // FreeScout already leaves out deleted and disabled users
            return $mailbox ? collect($mailbox->usersHavingAccess())->values()->all() : [];
        }
        $user = \App\User::find($to === 'user' ? (int) ($params['user'] ?? 0) : (int) ($ctx->ticket['user_id'] ?? 0));

        return $user && (int) $user->status === \App\User::STATUS_ACTIVE && $user->hasAccessToMailbox($mailboxId) ? [$user] : [];
    }
}
