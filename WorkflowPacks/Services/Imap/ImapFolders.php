<?php

namespace Modules\WorkflowPacks\Services\Imap;

use Modules\WorkflowPacks\Jobs\FileIntoImapFolder;

/**
 * Optional action "File into an e-mail folder [WP]" (Manage › Settings › Workflow Packs › E-mail folders, off by default).
 *
 * Added to Workflows through its own extension filters (no change to Workflows):
 *   workflows.actions_config   the action appears in the catalog of every mailbox while the option is on
 *   workflow.validate_action   an invalid folder name (or the option off) makes the rule invalid
 *   workflow.perform_action    Workflows runs the action: the move is queued (FreeScout's queue), never done inline
 *
 * On the mail server the e-mail is found by its Message-ID and moved into "<prefix> Folder/Sub-folder"; missing
 * folders are created. The ticket in FreeScout is not changed.
 */
class ImapFolders
{
    const OPTION_ENABLED = 'workflowpacks.imap_folders';
    const OPTION_PREFIX = 'workflowpacks.imap_prefix';

    // read without Option's in-memory cache: Option::set() does not refresh it (app/Option.php)
    public static function enabled()
    {
        return (bool) \App\Option::get(self::OPTION_ENABLED, false, true, false);
    }

    public static function prefix()
    {
        return FolderPath::prefix(\App\Option::get(self::OPTION_PREFIX, FolderPath::DEFAULT_PREFIX, true, false));
    }

    public static function register()
    {
        \Eventy::addFilter('workflows.actions_config', function ($config, $mailboxId = null) {
            if (self::enabled() && is_array($config)) {
                $config['workflowpacks'] = [
                    'title' => __('workflowpacks::messages.imap_group'),
                    'items' => [FolderPath::ACTION => ['title' => __('workflowpacks::messages.imap_action', ['prefix' => self::prefix()])]],
                ];
            }

            return $config;
        }, 20, 2);

        \Eventy::addFilter('workflow.validate_action', function ($hasError, $action = [], $workflow = null) {
            if (($action['type'] ?? null) !== FolderPath::ACTION) {
                return $hasError;
            }

            return !self::enabled() || FolderPath::parse($action['value'] ?? null)['error'] !== null;
        }, 20, 3);

        \Eventy::addFilter('workflow.perform_action', function ($performed, $type = '', $operator = '', $value = '', $conversation = null, $workflow = null) {
            if ($type !== FolderPath::ACTION || !$conversation) {
                return $performed;
            }

            return self::queue($conversation, $value) || $performed;
        }, 20, 6);
    }

    /** Queues the move of the last e-mail received in the conversation. False when there is nothing to move. */
    public static function queue($conversation, $value)
    {
        if (!self::enabled() || FolderPath::parse($value)['error'] !== null) {
            return false;
        }
        // mailbox without IMAP fetching (e.g. e-mails received another way): nothing on a server to file
        $mailbox = $conversation->mailbox;
        if (!$mailbox || !$mailbox->in_server || !$mailbox->in_username) {
            return false;
        }
        // last e-mail of the customer (FreeScout's own replies are not in the mailbox's inbox)
        $thread = $conversation->threads()
            ->where('type', \App\Thread::TYPE_CUSTOMER)
            ->whereNotNull('message_id')->where('message_id', '!=', '')
            ->orderBy('id', 'desc')->first();
        if (!$thread) {
            return false;
        }
        FileIntoImapFolder::dispatch((int) $conversation->mailbox_id, (int) $thread->id, (string) $value);

        return true;
    }
}
