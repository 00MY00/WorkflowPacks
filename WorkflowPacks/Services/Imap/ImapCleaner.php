<?php

namespace Modules\WorkflowPacks\Services\Imap;

/**
 * Removes the old e-mails of ONE managed folder ("[WP] Newsletters") of a mailbox, on its IMAP server (FreeScout's
 * IMAP client, same settings as the e-mail fetching). Standard commands only: LIST, SEARCH BEFORE, MOVE to the trash,
 * or STORE \Deleted + EXPUNGE — the EXPUNGE is sent with that managed folder selected, so nothing else is purged.
 *
 * Guards: the folder must carry the prefix (never a folder of the user); a folder that does not exist is left alone
 * (nothing created); at most $limit e-mails per run; an e-mail without a readable date is kept.
 */
class ImapCleaner extends ImapMover
{
    /**
     * @param int    $cutoff e-mails dated before this time (Unix timestamp) are removed
     * @param string $mode   trash | delete
     * @return array ['moved' => n, 'deleted' => n, 'folder' => path on the server, 'error' => '', 'message_ids' => [...]]
     *               message_ids: Message-IDs of the e-mails removed (their tickets leave the folder, TicketFolders)
     */
    public function clean($mailbox, $folder, $prefix, $cutoff, $mode, $limit)
    {
        $out = ['moved' => 0, 'deleted' => 0, 'folder' => '', 'error' => '', 'message_ids' => []];
        $parsed = FolderPath::parse($folder);
        if ($parsed['error'] !== null) {
            return ['error' => $parsed['error']] + $out;
        }
        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        try {
            $layout = $this->layout($client);
            $paths = $layout['paths'];
            $delimiter = $layout['delimiter'];
            $parent = $layout['parent'];
            $trash = $layout['trash'] ? $layout['trash'][0] : null;
            $levels = FolderPath::levels($parsed['segments'], $prefix, $delimiter, $parent);
            $label = end($levels);
            $out['folder'] = $label;
            if (!FolderPath::isManaged($label, $prefix, $delimiter, $parent)) {
                return ['error' => 'not a managed folder'] + $out;
            }
            if ($this->insideTrash($levels[0], $layout) !== null) {
                return ['error' => 'refused: inside the trash folder'] + $out;
            }
            $target = mb_convert_encoding($label, 'UTF7-IMAP', 'UTF-8');
            if (!in_array($target, $paths, true)) {
                return $out; // not created in this mailbox (no e-mail filed there yet)
            }
            $box = $client->getFolderByPath($target);
            if (!$box) {
                return $out;
            }
            // SEARCH BEFORE works by day: the day after the cut-off, then the exact date of each e-mail
            $before = \Carbon\Carbon::createFromTimestamp($cutoff)->addDay()->startOfDay();
            $messages = $box->query()->whereBefore($before)->setFetchBody(false)->leaveUnread()->limit($limit)->get();
            $toTrash = $mode === 'trash' && $trash !== null && $trash !== $target;
            foreach ($messages as $message) {
                $date = $this->dateOf($message);
                if ($date === null || $date > $cutoff) {
                    continue;
                }
                $messageId = $this->messageIdOf($message);
                if ($toTrash) {
                    if ($message->move($trash)) {
                        $out['moved']++;
                        if ($messageId !== '') {
                            $out['message_ids'][] = $messageId;
                        }
                        continue;
                    }
                }
                // no trash folder in this account (or the move refused): deleted for good, in this folder only
                $message->setFlag('Deleted');
                $out['deleted']++;
                if ($messageId !== '') {
                    $out['message_ids'][] = $messageId;
                }
            }
            if ($out['deleted']) {
                $client->openFolder($target, true);
                $client->expunge();
            }

            return $out;
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }
    }

    /** Message-ID of the e-mail without "<>", or '' when it cannot be read. */
    protected function messageIdOf($message)
    {
        try {
            $id = $message->getMessageId();
            if (is_object($id) && method_exists($id, 'first')) {
                $id = $id->first();
            }

            return trim((string) $id, " <>\t\r\n");
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Date of the e-mail (Unix timestamp), or null when it cannot be read. */
    protected function dateOf($message)
    {
        try {
            $date = $message->getDate();
            if (is_object($date) && method_exists($date, 'toDate')) {
                $date = $date->toDate();
            } elseif (is_object($date) && method_exists($date, 'first')) {
                $date = $date->first();
            }
            if ($date instanceof \DateTimeInterface) {
                return $date->getTimestamp();
            }
            $time = strtotime((string) $date);

            return $time ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
