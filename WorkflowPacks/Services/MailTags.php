<?php

namespace Modules\WorkflowPacks\Services;

use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Condition "E-mail tag" of the Workflows rules: the origin tags RefreshGlobal (≥ 1.11.0) gives to every e-mail
 * fetched — inbox, spam, classified_spam, trash, sent, drafts, archive, folder:<name>, the administrator's own
 * (RefreshGlobal docs/api-tags.md). Added to Workflows through its own extension filters, no change to Workflows:
 *
 *   workflows.conditions_config   the condition in the catalog of every mailbox while RefreshGlobal is active, with
 *                                 the known tags as suggestions (the editor of the diagram offers them, any other
 *                                 tag can be typed)
 *   workflow.check_condition      evaluated by Workflows itself: the tags of the ticket (all its e-mails)
 *   refreshglobal.mail_tagged     RefreshGlobal tags the e-mail while FreeScout fetches it, on the same event that
 *                                 runs the rules: whichever comes first, the rules using this condition are checked
 *                                 again for that ticket once it has its tags (never the rules it has already run,
 *                                 never older tickets unless "existing tickets" is on for the rule)
 *
 * No hard dependency: without RefreshGlobal the condition is not offered and is never true.
 */
class MailTags
{
    const TYPE = 'wp_mail_tag';
    const OPERATORS = ['has', 'has_not'];
    /** standard tags of RefreshGlobal (always in English) */
    const STANDARD = ['inbox', 'spam', 'classified_spam', 'trash', 'sent', 'drafts', 'archive'];

    public static function available()
    {
        try {
            return (bool) \App\Module::isActive('refreshglobal');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function register()
    {
        \Eventy::addFilter('workflows.conditions_config', function ($config, $mailboxId = null) {
            if (!is_array($config) || !self::available()) {
                return $config;
            }
            $config['workflowpacks_tags'] = [
                'title' => __('workflowpacks::messages.mail_tags_group'),
                'items' => [self::TYPE => [
                    'title'     => __('workflowpacks::messages.mail_tags_condition'),
                    'operators' => ['has' => __('workflowpacks::messages.mail_tags_has'), 'has_not' => __('workflowpacks::messages.mail_tags_has_not')],
                    // the same events as "New / Reply" (Workflows starts a rule only on the events of its conditions)
                    'triggers'  => ['conversation.created_by_customer', 'conversation.customer_replied', 'conversation.created_by_user', 'conversation.moved'],
                    // known tags, shown by the editor of the diagram (Workflows' own form shows a text field)
                    'suggest'   => self::suggestions($mailboxId),
                ]],
            ];

            return $config;
        }, 20, 2);

        \Eventy::addFilter('workflow.check_condition', function ($result, $type = '', $operator = '', $value = '', $conversation = null, $workflow = null) {
            if ($type !== self::TYPE || !$conversation) {
                return $result;
            }

            return self::check($operator, $value, self::tagsOf($conversation->id));
        }, 20, 6);

        \Eventy::addAction('refreshglobal.mail_tagged', function ($conversationId, $threadId = null, $newTags = []) {
            try {
                self::recheck((int) $conversationId, (int) $threadId);
            } catch (\Throwable $e) {
                \Log::warning('[WorkflowPacks] [mail-tags] '.$e->getMessage());
            }
        }, 20, 3);
    }

    /** Does a ticket with these tags match "has / has not" the tag of the condition? */
    public static function check($operator, $value, array $tags)
    {
        $tag = self::normalize($value);
        if ($tag === '') {
            return false;
        }
        $has = in_array($tag, array_map([self::class, 'normalize'], $tags), true);

        return $operator === 'has_not' ? !$has : $has;
    }

    public static function normalize($tag)
    {
        return mb_strtolower(trim((string) $tag));
    }

    /** Tags of a ticket, through RefreshGlobal's filter (empty when it is not installed). */
    public static function tagsOf($conversationId)
    {
        $all = \Eventy::filter('refreshglobal.mail_tags', [], [(int) $conversationId]);

        return is_array($all[(int) $conversationId] ?? null) ? $all[(int) $conversationId] : [];
    }

    /** Known tags for the editor: [tag => label]: the standard ones, then those already seen in the mailbox. */
    public static function suggestions($mailboxId = null)
    {
        $out = [];
        foreach (self::STANDARD as $tag) {
            $out[$tag] = __('workflowpacks::messages.mail_tag_'.$tag);
        }
        try {
            if (\Schema::hasTable('refreshglobal_mail_tags')) {
                $query = \DB::table('refreshglobal_mail_tags')->select('tag')->distinct()->orderBy('tag')->limit(60);
                if ($mailboxId) {
                    $query->where('mailbox_id', (int) $mailboxId);
                }
                foreach ($query->pluck('tag') as $tag) {
                    $out[(string) $tag] = $out[(string) $tag] ?? (string) $tag;
                }
            }
        } catch (\Throwable $e) {
            // suggestions only
        }

        return $out;
    }

    /**
     * The ticket just got its tags: the active automatic rules of its mailbox that use this condition, not run yet on
     * it, are checked again (with the event of the e-mail), and run when they match — as Workflows would have.
     */
    public static function recheck($conversationId, $threadId = null)
    {
        $conversation = \App\Conversation::find($conversationId);
        if (!$conversation || !Bridge::classAvailable()) {
            return 0;
        }
        $class = Bridge::modelClass();
        $done = [];
        try {
            if (\Schema::hasTable('conversation_workflow')) {
                $done = \DB::table('conversation_workflow')->where('conversation_id', $conversation->id)->pluck('workflow_id')->map(function ($id) {
                    return (int) $id;
                })->all();
            }
        } catch (\Throwable $e) {
            $done = [];
        }
        // the event of the e-mail: first one of the ticket = new conversation, else a reply
        $first = \App\Thread::where('conversation_id', $conversation->id)->where('type', \App\Thread::TYPE_CUSTOMER)->orderBy('id')->value('id');
        $event = !$threadId || (int) $first === (int) $threadId ? 'conversation.created_by_customer' : 'conversation.customer_replied';
        $ran = 0;
        $rules = $class::where('mailbox_id', $conversation->mailbox_id)->where('active', true)->where('type', 1)->orderBy('sort_order')->get();
        foreach ($rules as $workflow) {
            if (in_array((int) $workflow->id, $done, true) || !self::uses($workflow)) {
                continue;
            }
            if (method_exists($workflow, 'checkPrevious') && !$workflow->checkPrevious($conversation)) {
                continue;
            }
            if ($workflow->checkConditions($conversation, $event)) {
                $workflow->performActions($conversation);
                $ran++;
            }
        }

        return $ran;
    }

    /** Does the rule use the "E-mail tag" condition? */
    public static function uses($workflow)
    {
        foreach ((array) Bridge::attributesOf($workflow)['conditions'] as $group) {
            foreach ((array) $group as $row) {
                if (($row['type'] ?? '') === self::TYPE) {
                    return true;
                }
            }
        }

        return false;
    }
}
