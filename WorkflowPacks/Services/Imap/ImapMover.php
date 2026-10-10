<?php

namespace Modules\WorkflowPacks\Services\Imap;

/**
 * Moves one e-mail of a mailbox into a managed folder on its IMAP server, with FreeScout's own IMAP client (same
 * connection settings, oAuth included). Missing folders are created, level by level, only under the prefix.
 *
 * Results: moved | already (the e-mail is already in the folder) | not_found | failed (reason given).
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

        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        try {
            $paths = [];
            $delimiter = '/';
            foreach ($client->getFolders(false) as $folder) {
                $paths[] = (string) $folder->path;
                $delimiter = (string) ($folder->delimiter ?: $delimiter);
            }
            $levels = FolderPath::levels($parsed['segments'], $prefix, $delimiter, $this->parent($paths, $delimiter));
            $target = '';
            foreach ($levels as $level) {
                $target = mb_convert_encoding($level, 'UTF7-IMAP', 'UTF-8');
                if (!in_array($target, $paths, true)) {
                    if (!$client->createFolder($target, false)) {
                        return $this->result('failed', $level, 'cannot create the folder');
                    }
                    $paths[] = $target;
                }
            }
            $label = end($levels);

            // the e-mail, in the folders FreeScout fetches (INBOX by default)
            $message = \MailHelper::fetchMessage($mailbox, $messageId);
            if (!$message) {
                return $this->result($this->isIn($client, $target, $messageId) ? 'already' : 'not_found', $label);
            }
            $current = $message->getFolder();
            if ($current && (string) $current->path === $target) {
                return $this->result('already', $label);
            }
            if (!$message->move($target)) {
                // servers without the MOVE command: copy, then mark the original as deleted — without EXPUNGE, which
                // would also purge the other messages flagged as deleted in that folder
                if (!$message->copy($target)) {
                    return $this->result('failed', $label, 'the server refused the move');
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

    protected function result($result, $folder, $reason = '')
    {
        return ['result' => $result, 'folder' => $folder, 'reason' => $reason];
    }
}
