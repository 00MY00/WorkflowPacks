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
        $report = $ctx->actions()->push($users, $title, $plain($params['text']));

        return Result::next('next', self::outcome($ctx, $params, $report));
    }

    /**
     * What became of it, said in the path of the ticket: devices that took it, agents in their quiet hours, agents
     * without any device, devices that refused. Received by nobody: also written in History, so that it is seen (the
     * flow goes on: a missed notification never stops the blocks after it).
     */
    protected static function outcome(Context $ctx, array $params, $report)
    {
        if ($report === null) {
            return self::title($params);
        }
        $names = function (array $list) {
            return implode(', ', array_slice($list, 0, 5)).(count($list) > 5 ? ' (+'.(count($list) - 5).')' : '');
        };
        $parts = [];
        if ($report['sent']) {
            $parts[] = self::text(self::type(), 'sent', ['count' => $report['sent']]);
        }
        if ($report['silenced']) {
            $parts[] = self::text(self::type(), 'silenced', ['names' => $names($report['silenced'])]);
        }
        if ($report['no_device']) {
            $parts[] = self::text(self::type(), 'no_device', ['names' => $names($report['no_device'])]);
        }
        if ($report['devices'] > $report['sent']) {
            $parts[] = self::text(self::type(), 'refused', ['count' => $report['devices'] - $report['sent']]);
        }
        if ($report['sent']) {
            return self::title($params).' — '.implode(' · ', $parts);
        }
        $summary = self::text(self::type(), 'not_received').' — '.implode(' · ', $parts);
        \Modules\WorkflowPacks\Services\History::add('flow', 'skipped', ['mailbox_id' => $ctx->ticket['mailbox_id'] ?? null,
            'conversation_id' => $ctx->conversation ? $ctx->conversation->id : null, 'subject' => (string) ($ctx->ticket['subject'] ?? ''),
            'message' => $summary]);

        return $summary;
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
