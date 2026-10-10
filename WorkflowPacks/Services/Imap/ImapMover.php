<?php

namespace Modules\WorkflowPacks\Services\Imap;

/**
 * Moves one e-mail of a mailbox into a managed folder on its IMAP server, with FreeScout's own IMAP client (same
 * connection settings, oAuth included). Standard IMAP commands only: LIST, CREATE, SUBSCRIBE, SEARCH, MOVE (or COPY +
 * \Deleted flag, without EXPUNGE).
 *
 * Guards:
 *  - the target is checked to be a managed folder (top level carries the prefix) before anything is written;
 *  - the e-mail already in the target folder: nothing is done ("already"), so running the rule twice never duplicates;
 *  - a folder created meanwhile by another job is accepted (creation re-checked by listing);
 *  - new folders are subscribed, so that mail clients showing subscribed folders only display them.
 *
 * Results: moved | already | not_found | failed. "retry" tells whether trying again later may help (server refusal)
 * or not (invalid name, mailbox without IMAP settings). Connection errors are thrown (the job tries again).
 */
class ImapMover
{
    /**
     * @param \App\Mailbox $mailbox
     * @param string       $messageId Message-ID of the e-mail, without < >
     * @param string       $value     folder written in the rule ("Invoices/Suppliers")
     */
    public function move($mailbox, $messageId, $value, $prefix)
    {
        $parsed = FolderPath::parse($value);
        if ($parsed['error'] !== null) {
            return $this->result('failed', '', $parsed['error']);
        }
        if (!$mailbox->in_server || !$mailbox->in_username) {
            return $this->result('failed', '', 'no IMAP settings for this mailbox');
        }
        if (trim((string) $messageId) === '') {
            return $this->result('not_found', '');
        }

        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        try {
            $paths = [];
            $delimiter = '/';
            foreach ($client->getFolders(false) as $folder) {
                $paths[] = (string) $folder->path;
                $delimiter = (string) ($folder->delimiter ?: $delimiter);
            }
            $parent = $this->parent($paths, $delimiter);
            $levels = FolderPath::levels($parsed['segments'], $prefix, $delimiter, $parent);
            $label = end($levels);
            if (!FolderPath::isManaged($label, $prefix, $delimiter, $parent)) {
                return $this->result('failed', $label, 'not a managed folder');
            }

            $target = '';
            foreach ($levels as $level) {
                $target = mb_convert_encoding($level, 'UTF7-IMAP', 'UTF-8');
                if (!$this->ensureFolder($client, $target, $paths)) {
                    return $this->result('failed', $level, 'cannot create the folder', true);
                }
            }

            // already filed (rule applied twice, or moved by hand): nothing to do
            if ($this->isIn($client, $target, $messageId)) {
                return $this->result('already', $label);
            }
            // the e-mail, in the folders FreeScout fetches (INBOX by default)
            $message = \MailHelper::fetchMessage($mailbox, $messageId);
            if (!$message) {
                return $this->result('not_found', $label);
            }
            if (!$message->move($target)) {
                // servers without the MOVE command: copy, then mark the original as deleted — without EXPUNGE, which
                // would also purge the other messages flagged as deleted in that folder
                if (!$message->copy($target)) {
                    return $this->result('failed', $label, 'the server refused the move', true);
                }
                $message->delete(false);
            }

            return $this->result('moved', $label);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }
    }

    /** The folder exists, or is created (and subscribed). A folder created at the same time elsewhere is accepted. */
    protected function ensureFolder($client, $path, array &$paths)
    {
        if (in_array($path, $paths, true)) {
            return true;
        }
        try {
            $client->createFolder($path, false);
        } catch (\Throwable $e) {
            // already created by another job, or refused: the listing below tells
        }
        if (!$client->getFolderByPath($path)) {
            return false;
        }
        $paths[] = $path;
        try {
            $client->getConnection()->subscribeFolder($path);
        } catch (\Throwable $e) {
            // optional: some servers subscribe on creation or have no subscriptions
        }

        return true;
    }

    /** Servers keeping every folder under INBOX (delimiter "."): the managed folders go there too. */
    protected function parent(array $paths, $delimiter)
    {
        $others = array_filter($paths, function ($p) {
            return strtoupper($p) !== 'INBOX';
        });
        if ($delimiter !== '.' || !$others) {
            return '';
        }
        foreach ($others as $path) {
            if (stripos($path, 'INBOX.') !== 0) {
                return '';
            }
        }

        return 'INBOX';
    }

    protected function isIn($client, $folderPath, $messageId)
    {
        try {
            $folder = $client->getFolderByPath($folderPath);
            if (!$folder) {
                return false;
            }

            return $folder->query()->whereMessageId('"<'.addcslashes($messageId, '\"').'>"')->leaveUnread()->limit(1)->get()->count() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function result($result, $folder, $reason = '', $retry = false)
    {
        return ['result' => $result, 'folder' => $folder, 'reason' => $reason, 'retry' => $retry];
    }
}
