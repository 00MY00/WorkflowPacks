<?php

namespace Modules\WorkflowPacks\Services;

/**
 * The origin tags RefreshGlobal (≥ 1.11.0) gives to every e-mail fetched — inbox, spam, classified_spam, trash, sent,
 * drafts, archive, folder:<name>, the administrator's own (RefreshGlobal docs/api-tags.md) — read through its filter
 * "refreshglobal.mail_tags". Used by the block "E-mail tag" of the flows (Blocks\Conditions\MailTag) and by the
 * limits of "apply to the existing tickets" (Services\Engine\TicketScope). No hard dependency: without RefreshGlobal a
 * ticket has no tags.
 */
class MailTags
{
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

}
