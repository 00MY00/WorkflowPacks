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
     * @param bool         $anywhere  filed again ("File again"): an e-mail no longer in the inbox is also looked for in
     *                                the other folders of the account, never in the trash
     */
    public function move($mailbox, $messageId, $value, $prefix, $anywhere = false)
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
            $layout = $this->layout($client);
            $paths = $layout['paths'];
            $delimiter = $layout['delimiter'];
            $parent = $layout['parent'];
            $levels = FolderPath::levels($parsed['segments'], $prefix, $delimiter, $parent);
            $label = end($levels);
            if (!FolderPath::isManaged($label, $prefix, $delimiter, $parent)) {
                return $this->result('failed', $label, 'not a managed folder');
            }
            // never inside the trash (or any folder the server flags as such)
            $inTrash = $this->insideTrash($levels[0], $layout);
            if ($inTrash !== null) {
                return $this->result('failed', $label, 'refused: this place is inside the trash folder "'.$inTrash.'"');
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
            $from = '';
            if (!$message && $anywhere) {
                list($message, $from) = $this->findElsewhere($client, $messageId, $target, $layout);
            }
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

            return $this->result('moved', $label, $from !== '' ? 'found in "'.$from.'"' : '');
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

    /** Usual names of the trash folder (last level, lower case), when the server does not flag it. */
    const TRASH_NAMES = ['trash', 'deleted', 'deleted items', 'deleted messages', 'corbeille', 'éléments supprimés',
        'papierkorb', 'gelöschte elemente', 'papelera', 'elementos eliminados', 'cestino', 'posta eliminata', 'prullenbak',
        'verwijderde items', 'lixeira', 'itens excluídos', 'bin'];

    /**
     * Where the managed folders go on this server:
     *  - the setting "Create the folders" (auto | root | inbox),
     *  - auto: at the top, or under INBOX when every folder of the account is under "INBOX." (Courier, some Dovecot).
     * The personal namespace of the account (IMAP NAMESPACE, RFC 2342) is read for the diagnostic page.
     *
     * @return array ['paths' => string[], 'delimiter' => string, 'parent' => string, 'trash' => string[], 'namespace' => array|null]
     */
    public function layout($client)
    {
        $paths = [];
        $trash = [];
        $delimiter = '';
        $folders = [];
        foreach ($client->getFolders(false) as $folder) {
            $paths[] = (string) $folder->path;
            $folders[] = $folder;
            if ($delimiter === '' && (string) $folder->delimiter !== '') {
                $delimiter = (string) $folder->delimiter;
            }
        }
        // shown by the diagnostic only: the placement below stays the one of the previous versions (no folder moved)
        $namespace = $this->namespace($client);
        $delimiter = $delimiter !== '' ? $delimiter : '/';
        foreach ($folders as $folder) {
            if ($this->isTrash($folder, $delimiter)) {
                $trash[] = (string) $folder->path;
            }
        }
        $mode = ImapFolders::parentMode();
        if ($mode === 'root') {
            $parent = '';
        } elseif ($mode === 'inbox') {
            $parent = 'INBOX';
        } else {
            $parent = $this->parent($paths, $delimiter);
        }

        return ['paths' => $paths, 'delimiter' => $delimiter, 'parent' => $parent, 'trash' => $trash, 'namespace' => $namespace];
    }

    /** Personal namespace of the account (["prefix" => "INBOX.", "delimiter" => "."]), or null when unknown. */
    protected function namespace($client)
    {
        try {
            $lines = $client->getConnection()->requestAndResponse('NAMESPACE');
        } catch (\Throwable $e) {
            return null;
        }
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (!is_array($line) || strtoupper((string) ($line[0] ?? '')) !== 'NAMESPACE') {
                continue;
            }
            $personal = $line[1] ?? null;
            if (is_array($personal) && isset($personal[0]) && is_array($personal[0])) {
                $prefix = (string) ($personal[0][0] ?? '');
                $delimiter = (string) ($personal[0][1] ?? '');

                return ['prefix' => $prefix === 'NIL' ? '' : $prefix, 'delimiter' => $delimiter === 'NIL' ? '' : $delimiter];
            }
        }

        return null;
    }

    /** The trash folder containing this path, or null. */
    protected function insideTrash($path, array $layout)
    {
        foreach ($layout['trash'] as $trash) {
            $name = mb_convert_encoding($trash, 'UTF-8', 'UTF7-IMAP');
            if ($path === $name || strpos($path, $name.$layout['delimiter']) === 0) {
                return $name;
            }
        }

        return null;
    }

    protected function isTrash($folder, $delimiter)
    {
        foreach (['attributes', 'flags'] as $property) {
            $flags = isset($folder->$property) ? (array) $folder->$property : [];
            foreach ($flags as $flag) {
                if (is_string($flag) && strcasecmp($flag, '\Trash') === 0) {
                    return true;
                }
            }
        }
        $path = mb_convert_encoding((string) $folder->path, 'UTF-8', 'UTF7-IMAP');
        $parts = explode($delimiter ?: '/', $path);

        return in_array(mb_strtolower(trim((string) end($parts))), self::TRASH_NAMES, true);
    }

    /** Servers keeping every folder under INBOX (delimiter "."), when they do not answer NAMESPACE. */
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

    /**
     * The e-mail in another folder of the account (moved by hand, by a mail client or a filter of the server): the
     * first one holding it, the trash and the target excluded. [message, UTF-8 path of its folder] or [null, ''].
     */
    protected function findElsewhere($client, $messageId, $target, array $layout)
    {
        foreach ($layout['paths'] as $path) {
            if ($path === $target || strtoupper($path) === 'INBOX' || in_array($path, $layout['trash'], true)
                || $this->insideTrash(mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP'), $layout) !== null) {
                continue;
            }
            try {
                $folder = $client->getFolderByPath($path);
                $found = $folder ? $folder->query()->whereMessageId('"<'.addcslashes($messageId, '\"').'>"')->leaveUnread()->limit(1)->get()->first() : null;
            } catch (\Throwable $e) {
                // folder that cannot be opened (\Noselect…): the next one
                continue;
            }
            if ($found) {
                return [$found, mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP')];
            }
        }

        return [null, ''];
    }

    protected function result($result, $folder, $reason = '', $retry = false)
    {
        return ['result' => $result, 'folder' => $folder, 'reason' => $reason, 'retry' => $retry];
    }
}
