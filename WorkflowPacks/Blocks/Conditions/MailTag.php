<?php

namespace Modules\WorkflowPacks\Blocks\Conditions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;
use Modules\WorkflowPacks\Services\MailTags;

/**
 * "E-mail tag": the origin tags RefreshGlobal gives to each e-mail it fetches (inbox, spam, classified_spam, trash,
 * sent, drafts, archive, folder:<name>, the administrator's own). Yes when the ticket has (or has not) the tag.
 *
 * RefreshGlobal tags the e-mail while FreeScout fetches it, on the same event that starts the flows: for a new e-mail
 * still without any tag, the block waits for them, a minute at a time, WAIT_TIMES times at most; then it decides with
 * what is there. Without RefreshGlobal: the "error" output.
 */
class MailTag extends AbstractBlock
{
    /** a new e-mail: its tags are waited for this many minutes at most */
    const WAIT_TIMES = 10;
    /** "new": received less than this many minutes ago (older tickets have their tags already) */
    const NEW_MINUTES = 15;
    const VAR_WAITS = '_mail_tag_waits';

    public static function type()
    {
        return 'condition.mail_tag';
    }

    public static function family()
    {
        return 'condition';
    }

    public static function fields()
    {
        return [
            'has' => ['type' => 'choice', 'values' => MailTags::OPERATORS, 'default' => 'has', 'required' => true],
            'tag' => ['type' => 'text', 'required' => true],
        ];
    }

    public static function outputs()
    {
        return ['yes', 'no', 'error'];
    }

    protected static function titleParams(array $params)
    {
        return ['has' => self::choice('values.has', $params['has'] ?? ''), 'tag' => self::short($params['tag'] ?? '…', 40) ?: '…'];
    }

    public static function validate(array $params)
    {
        $problems = parent::validate($params);
        if (!isset($problems['tag']) && mb_strlen(MailTags::normalize($params['tag'] ?? '')) > 60) {
            $problems['tag'] = __('workflowpacks::blocks.errors.long', ['max' => 60]);
        }

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        if (!MailTags::available()) {
            return Result::error(__('workflowpacks::blocks.condition.mail_tag.missing'));
        }
        $conversation = $ctx->conversation;
        $tags = $conversation ? MailTags::tagsOf($conversation->id) : (array) ($ctx->ticket['mail_tags'] ?? []);
        if (!$tags && $conversation && !$ctx->dryRun && self::isNew($conversation, $ctx)) {
            $waits = (int) ($ctx->vars[self::VAR_WAITS] ?? 0);
            if ($waits < self::WAIT_TIMES) {
                $ctx->vars[self::VAR_WAITS] = $waits + 1;

                // no output: on wake-up the block runs again
                return Result::wait($ctx->now->copy()->addMinute(), __('workflowpacks::blocks.condition.mail_tag.waiting'), null);
            }
        }
        unset($ctx->vars[self::VAR_WAITS]);
        $yes = MailTags::check((string) ($params['has'] ?? 'has'), (string) ($params['tag'] ?? ''), $tags);

        return Result::next($yes ? 'yes' : 'no', self::text(self::type(), 'decision', [
            'tags'   => $tags ? self::short(implode(', ', $tags), 80) : '—',
            'output' => __('workflowpacks::blocks.outputs.'.($yes ? 'yes' : 'no')),
        ]));
    }

    protected static function isNew($conversation, Context $ctx)
    {
        return $conversation->created_at && $conversation->created_at->gt($ctx->now->copy()->subMinutes(self::NEW_MINUTES));
    }
}
