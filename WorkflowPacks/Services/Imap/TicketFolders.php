<?php

namespace Modules\WorkflowPacks\Services\Imap;

/**
 * Current e-mail folder of each ticket (table workflowpacks_ticket_folders, one row per ticket whose e-mail this module
 * filed). Unlike the history (90 days), a row is kept as long as its ticket: removed only with it (FreeScout's
 * "conversations.before_delete_forever", with a late priority so the other modules listening to it still read it),
 * plus a daily check for tickets removed without that action.
 *
 *  - Written when the e-mail of a ticket is filed ("moved", or found "already" in its folder).
 *  - State "cleaned" when the automatic clean-up of its folder sent it to the trash or deleted it.
 *
 * For the other modules — stable, documented in docs/api-dossiers.md:
 *
 *   \Eventy::filter('workflowpacks.ticket_folder', null, $conversationId)     one ticket: array (see get()) or null
 *   \Eventy::filter('workflowpacks.ticket_folders', [], $conversationIds)    several: [conversation id => array]
 *   \Eventy::addAction('workflowpacks.ticket_folder_changed', function ($conversationId, $current, $previous) {}, 20, 3)
 *
 *   SQL (read only): workflowpacks_ticket_folders.conversation_id (unique), mailbox_id, folder ("Factures/Fournisseurs",
 *   as in the rules), label ("[WP] Factures › Fournisseurs"), path (on the mail server), state (filed | cleaned),
 *   filed_at, cleaned_at; indexes (mailbox_id, state) and (folder).
 */
class TicketFolders
{
    const TABLE = 'workflowpacks_ticket_folders';
    /** priority of the removal on "conversations.before_delete_forever": after the modules reading it (20 by default) */
    const DELETE_PRIORITY = 1000;
    const OPTION_PRUNED = 'workflowpacks.ticket_folders_pruned';

    public static function register()
    {
        \Eventy::addFilter('workflowpacks.ticket_folder', function ($default, $conversationId = null) {
            $folder = self::get($conversationId);

            return $folder !== null ? $folder : $default;
        }, 20, 2);
        \Eventy::addFilter('workflowpacks.ticket_folders', function ($default, $conversationIds = []) {
            return self::many((array) $conversationIds) + (array) $default;
        }, 20, 2);
        \Eventy::addAction('conversations.before_delete_forever', function ($conversationIds) {
            self::forget((array) $conversationIds);
        }, self::DELETE_PRIORITY, 1);
        // in the ticket, next to its subject
        \Eventy::addAction('conversation.after_subject', function ($conversation, $mailbox = null) {
            $folder = $conversation ? self::get($conversation->id) : null;
            if ($folder) {
                echo \View::make('workflowpacks::partials.ticket_folder', ['folder' => $folder])->render();
            }
        }, 20, 2);
    }

    public static function exists()
    {
        try {
            return \Schema::hasTable(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The e-mail of a ticket is in a managed folder (just filed, or found there).
     *
     * @param string $value folder as written in the rule ("Factures/Fournisseurs")
     * @param string $path  folder on the mail server (UTF-8), as the move returned it
     */
    public static function record($conversationId, $mailboxId, $value, $prefix, $path = '')
    {
        $parsed = FolderPath::parse($value);
        if (!$conversationId || $parsed['error'] !== null || !self::exists()) {
            return;
        }
        $folder = implode('/', $parsed['segments']);
        $previous = self::get($conversationId);
        $now = date('Y-m-d H:i:s');
        $values = ['mailbox_id' => (int) $mailboxId, 'folder' => mb_substr($folder, 0, 191),
            'label' => mb_substr(FolderPath::label($folder, $prefix), 0, 191), 'path' => mb_substr((string) $path, 0, 191),
            'state' => 'filed', 'filed_at' => $now, 'cleaned_at' => null, 'updated_at' => $now];
        if (\DB::table(self::TABLE)->where('conversation_id', (int) $conversationId)->exists()) {
            \DB::table(self::TABLE)->where('conversation_id', (int) $conversationId)->update($values);
        } else {
            \DB::table(self::TABLE)->insert($values + ['conversation_id' => (int) $conversationId, 'created_at' => $now]);
        }
        self::changed($conversationId, $previous);
    }

    /**
     * The automatic clean-up removed these e-mails (Message-IDs) from a folder of the mailbox: their tickets are no
     * longer there. $path: that folder on the server, so that a ticket filed elsewhere since is left as it is.
     */
    public static function cleaned($mailboxId, array $messageIds, $path = null)
    {
        $ids = [];
        foreach ($messageIds as $id) {
            $id = MessageFinder::normalize($id);
            if ($id !== '') {
                // FreeScout stores the Message-ID without < >, some older rows with them
                $ids[] = $id;
                $ids[] = '<'.$id.'>';
            }
        }
        if (!$ids || !self::exists()) {
            return;
        }
        $conversationIds = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $conversationIds = array_merge($conversationIds, \App\Thread::join('conversations', 'conversations.id', '=', 'threads.conversation_id')
                ->where('conversations.mailbox_id', (int) $mailboxId)->whereIn('threads.message_id', $chunk)
                ->distinct()->pluck('threads.conversation_id')->all());
        }
        foreach (array_unique(array_map('intval', $conversationIds)) as $conversationId) {
            $query = \DB::table(self::TABLE)->where('conversation_id', $conversationId)->where('state', 'filed');
            if ($path !== null && $path !== '') {
                $query->where('path', mb_substr((string) $path, 0, 191));
            }
            $previous = self::get($conversationId);
            $now = date('Y-m-d H:i:s');
            if ($query->update(['state' => 'cleaned', 'cleaned_at' => $now, 'updated_at' => $now])) {
                self::changed($conversationId, $previous);
            }
        }
    }

    /**
     * The folder of a ticket, null when the module never filed its e-mail.
     *
     * @return array|null ['conversation_id', 'mailbox_id', 'folder' ("Factures/Fournisseurs"), 'label'
     *                    ("[WP] Factures › Fournisseurs"), 'path' (on the mail server), 'state' (filed|cleaned),
     *                    'filed_at', 'cleaned_at', 'updated_at']
     */
    public static function get($conversationId)
    {
        $all = self::many([(int) $conversationId]);

        return $all[(int) $conversationId] ?? null;
    }

    /** @return array [conversation id => same as get()], only the tickets that have one */
    public static function many(array $conversationIds)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $conversationIds))));
        if (!$ids || !self::exists()) {
            return [];
        }
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (\DB::table(self::TABLE)->whereIn('conversation_id', $chunk)->get() as $row) {
                    $out[(int) $row->conversation_id] = [
                        'conversation_id' => (int) $row->conversation_id, 'mailbox_id' => (int) $row->mailbox_id,
                        'folder' => (string) $row->folder, 'label' => (string) $row->label, 'path' => (string) $row->path,
                        'state' => (string) $row->state, 'filed_at' => $row->filed_at !== null ? (string) $row->filed_at : null,
                        'cleaned_at' => $row->cleaned_at !== null ? (string) $row->cleaned_at : null, 'updated_at' => (string) $row->updated_at,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }

    /** Tickets deleted for good: their rows go with them. */
    public static function forget($conversationIds)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $conversationIds)));
        if (!$ids || !self::exists()) {
            return;
        }
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                \DB::table(self::TABLE)->whereIn('conversation_id', $chunk)->delete();
            }
        } catch (\Throwable $e) {
            // never block FreeScout's deletion: the daily check removes them
            \Log::warning('[WorkflowPacks] [ticket-folders] '.$e->getMessage());
        }
    }

    /**
     * Rows of tickets that no longer exist (removed without FreeScout's action). At most once a day unless $force.
     * Returns the number of rows removed.
     */
    public static function prune($force = false)
    {
        if (!self::exists()) {
            return 0;
        }
        if (!$force && (int) \App\Option::get(self::OPTION_PRUNED, 0, true, false) > time() - 86400) {
            return 0;
        }
        \App\Option::set(self::OPTION_PRUNED, time());
        try {
            $table = \DB::getTablePrefix().self::TABLE;

            return \DB::table(self::TABLE)->whereNotExists(function ($q) use ($table) {
                $q->select(\DB::raw(1))->from('conversations')->whereRaw(\DB::getTablePrefix().'conversations.id = '.$table.'.conversation_id');
            })->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Filings written in the history before this table existed (1.11.0 to 1.13.x): the last one of each ticket. The
     * folder comes from the rule when it still exists, else from the path on the server.
     */
    public static function backfill()
    {
        if (!self::exists() || !\Schema::hasTable('workflowpacks_activity')) {
            return 0;
        }
        $prefix = ImapFolders::prefix();
        $entries = \DB::table('workflowpacks_activity')->where('kind', 'imap_file')->whereIn('status', ['moved', 'already'])
            ->whereNotNull('conversation_id')->orderBy('id', 'desc')->get(['conversation_id', 'mailbox_id', 'workflow_id', 'subject', 'updated_at']);
        $seen = [];
        $count = 0;
        foreach ($entries as $entry) {
            if (isset($seen[$entry->conversation_id])) {
                continue;
            }
            $seen[$entry->conversation_id] = true;
            $conversation = \App\Conversation::find($entry->conversation_id);
            if (!$conversation || \DB::table(self::TABLE)->where('conversation_id', $entry->conversation_id)->exists()) {
                continue;
            }
            $workflow = null;
            try {
                $workflow = $entry->workflow_id ? \Modules\WorkflowPacks\Services\Workflows\Bridge::query()->find($entry->workflow_id) : null;
            } catch (\Throwable $e) {
            }
            $value = $workflow ? Refiler::folderOf($workflow) : null;
            if ($value === null) {
                $value = self::valueFromPath((string) $entry->subject, $prefix);
            }
            if ($value === null) {
                continue;
            }
            self::record($conversation->id, $conversation->mailbox_id, $value, $prefix, (string) $entry->subject);
            \DB::table(self::TABLE)->where('conversation_id', $conversation->id)->update(['filed_at' => $entry->updated_at, 'updated_at' => $entry->updated_at]);
            $count++;
        }

        return $count;
    }

    /**
     * Folder as written in the rules, from a path on the server or a label of the history ("[WP] Factures/Fournisseurs",
     * "INBOX.[WP] Factures.Fournisseurs", "[WP] Factures › Fournisseurs"); null for a folder not managed here. The
     * delimiter is the character before the prefix (under a parent folder), else "/" — so a dot in a name is kept.
     */
    public static function valueFromPath($path, $prefix)
    {
        $path = trim((string) $path);
        $at = $prefix !== '' ? mb_strpos($path, $prefix.' ') : false;
        if ($path === '' || $at === false) {
            return null;
        }
        $delimiter = $at > 0 ? mb_substr($path, $at - 1, 1) : '/';
        $rest = mb_substr($path, $at + mb_strlen($prefix) + 1);
        $parts = mb_strpos($rest, ' › ') !== false ? explode(' › ', $rest) : explode($delimiter, $rest);
        $parts = array_values(array_filter(array_map('trim', $parts), 'strlen'));

        return $parts ? implode('/', $parts) : null;
    }

    protected static function changed($conversationId, $previous)
    {
        $current = self::get($conversationId);
        $key = function ($f) {
            return $f ? $f['folder'].'|'.$f['state'].'|'.$f['path'] : '';
        };
        if ($key($current) !== $key($previous)) {
            \Eventy::action('workflowpacks.ticket_folder_changed', (int) $conversationId, $current, $previous);
        }
    }
}
