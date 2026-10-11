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
    /** where the managed folders are created: auto (what the server says) | root | inbox */
    const OPTION_PARENT = 'workflowpacks.imap_parent';
    const PARENT_MODES = ['auto', 'root', 'inbox'];

    // read without Option's in-memory cache: Option::set() does not refresh it (app/Option.php)
    public static function enabled()
    {
        return (bool) \App\Option::get(self::OPTION_ENABLED, false, true, false);
    }

    public static function setEnabled($enabled)
    {
        \App\Option::set(self::OPTION_ENABLED, $enabled ? 1 : 0);
        \Modules\WorkflowPacks\Services\Workflows\Bridge::forgetCatalogs();
    }

    /** Does a (validated) pack use the action? Used by the import to offer turning the option on. */
    public static function usedBy(array $pack)
    {
        foreach ((array) ($pack['workflows'] ?? []) as $rule) {
            foreach ((array) ($rule['actions'] ?? []) as $action) {
                if (($action['type'] ?? null) === FolderPath::ACTION) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function parentMode()
    {
        $mode = (string) \App\Option::get(self::OPTION_PARENT, 'auto', true, false);

        return in_array($mode, self::PARENT_MODES, true) ? $mode : 'auto';
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

            return self::queue($conversation, $value, $workflow) || $performed;
        }, 20, 6);
    }

    /**
     * Queues the move of the last e-mail received in the conversation. False when there is nothing to move. Every call
     * is written in the history (Workflow Packs › History), with the reason when nothing is queued.
     */
    public static function queue($conversation, $value, $workflow = null)
    {
        $entry = [
            'mailbox_id'      => $conversation->mailbox_id,
            'conversation_id' => $conversation->id,
            'workflow_id'     => $workflow && isset($workflow->id) ? $workflow->id : null,
            'subject'         => is_string($value) ? FolderPath::label($value, self::prefix()) : '',
        ];
        $skip = function ($reason) use ($entry) {
            \Modules\WorkflowPacks\Services\History::add('imap_file', 'skipped', $entry + ['message' => $reason]);

            return false;
        };
        if (!self::enabled()) {
            return $skip(__('workflowpacks::messages.history_reason_option_off'));
        }
        $parsed = FolderPath::parse($value);
        if ($parsed['error'] !== null) {
            return $skip(\Modules\WorkflowPacks\Services\Pack\Problems::t($parsed['error']));
        }
        // mailbox without IMAP fetching (e.g. e-mails received another way): nothing on a server to file
        $mailbox = $conversation->mailbox;
        if (!$mailbox || !$mailbox->in_server || !$mailbox->in_username) {
            return $skip(__('workflowpacks::messages.history_reason_no_imap'));
        }
        // last e-mail of the customer (FreeScout's own replies are not in the mailbox's inbox)
        $thread = $conversation->threads()
            ->where('type', \App\Thread::TYPE_CUSTOMER)
            ->whereNotNull('message_id')->where('message_id', '!=', '')
            ->orderBy('id', 'desc')->first();
        if (!$thread) {
            return $skip(__('workflowpacks::messages.history_reason_no_email'));
        }
        $activity = \Modules\WorkflowPacks\Services\History::add('imap_file', 'queued', $entry);
        FileIntoImapFolder::dispatch((int) $conversation->mailbox_id, (int) $thread->id, (string) $value, $activity);

        return true;
    }
}
