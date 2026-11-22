<?php

namespace Modules\WorkflowPacks\Services\Imap;

use Modules\WorkflowPacks\Jobs\FileIntoImapFolder;

/**
 * Option "File into an e-mail folder [WP]" (Manage › Settings › Workflow Packs › E-mail folders, off by default), used
 * by the block "File into a folder" of the flows: the move is queued (FreeScout's queue), never done inline.
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

    /**
     * The folders of the module already on the mail server of a mailbox, as written in a block ("Invoices/Suppliers"):
     * read-only, kept 10 minutes. ['ok', 'folders' => string[], 'error' => string]
     */
    public static function managedFolders($mailbox)
    {
        return \Cache::remember('workflowpacks.imap_folders.'.$mailbox->id.'.'.md5(self::prefix()), 10, function () use ($mailbox) {
            $prefix = self::prefix();
            $seen = (new ImapInspector())->inspect($mailbox, $prefix);
            if (!$seen['ok']) {
                return ['ok' => false, 'folders' => [], 'error' => (string) $seen['error']];
            }
            $delimiter = (string) $seen['delimiter'] !== '' ? (string) $seen['delimiter'] : '/';
            $out = [];
            foreach ($seen['folders'] as $f) {
                if (!$f['managed']) {
                    continue;
                }
                $path = $f['path'];
                if ($seen['parent'] !== '' && strpos($path, $seen['parent'].$delimiter) === 0) {
                    $path = substr($path, strlen($seen['parent'].$delimiter));
                }
                $out[] = implode('/', explode($delimiter, substr($path, strlen($prefix) + 1)));
            }
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);

            return ['ok' => true, 'folders' => array_values(array_unique($out)), 'error' => ''];
        });
    }


    /**
     * Queues the move of the last e-mail received in the conversation. False when there is nothing to move. Every call
     * is written in the history (Workflow Packs › History), with the reason when nothing is queued.
     * $target: another mailbox (the block can file it on another mail account); null: the mailbox of the ticket.
     */
    public static function queue($conversation, $value, $target = null)
    {
        $mailbox = $target ? \App\Mailbox::find((int) $target) : $conversation->mailbox;
        $entry = [
            'mailbox_id'      => $mailbox ? $mailbox->id : $conversation->mailbox_id,
            'conversation_id' => $conversation->id,
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
            return $skip(\Modules\WorkflowPacks\Services\Support\Texts::t($parsed['error']));
        }
        // mailbox without IMAP fetching (e.g. e-mails received another way): nothing on a server to file
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
        FileIntoImapFolder::dispatch((int) $mailbox->id, (int) $thread->id, (string) $value, $activity);

        return true;
    }
}
