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
    /** @var MessageFinder|null */
    protected $finder;

    /**
     * @param \App\Mailbox $mailbox
     * @param string       $messageId Message-ID of the e-mail, without < >
     * @param string       $value     folder written in the rule ("Invoices/Suppliers")
     * @param bool         $anywhere  filed again ("File again"): an e-mail no longer in the inbox is also looked for in
     *                                the other folders of the account, never in the trash
     * @param \Carbon\Carbon|null $date when FreeScout received the e-mail: lets the search read the headers of the
     *                                messages of those days when the server does not answer a search by Message-ID
     */
    public function move($mailbox, $messageId, $value, $prefix, $anywhere = false, $date = null)
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
            if ($this->finder()->find($client, $client->getFolderByPath($target), $messageId, $date, false)) {
                return $this->result('already', $label);
            }
            // the e-mail, in the folders FreeScout fetches (INBOX by default)
            $message = null;
            $fetched = $this->fetchedFolders($mailbox);
            foreach ($fetched as $path) {
                $message = $this->finder()->find($client, $client->getFolderByPath($path), $messageId, $date);
                if ($message) {
                    break;
                }
            }
            $from = '';
            if (!$message && $anywhere) {
                list($message, $from) = $this->findElsewhere($client, $messageId, array_merge([$target], $fetched), $layout, $date);
            }
            if (!$message) {
                $looked = array_values(array_unique(array_column($this->finder()->trace, 'folder')));

                return $this->result('not_found', $label, $looked ? 'looked in "'.implode('", "', array_slice($looked, 0, 8)).'"' : '');
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

    /** Search of the e-mails (several ways, see MessageFinder); one per move, its trace tells what was tried. */
    public function finder()
    {
        if (!$this->finder) {
            $this->finder = app(MessageFinder::class);
        }

        return $this->finder;
    }

    /** Folders FreeScout fetches for this mailbox (INBOX by default), as server paths. */
    public function fetchedFolders($mailbox)
    {
        $names = method_exists($mailbox, 'getInImapFolders') ? (array) $mailbox->getInImapFolders() : [];

        return array_map(function ($name) {
            return mb_convert_encoding((string) $name, 'UTF7-IMAP', 'UTF-8');
        }, $names ?: ['INBOX']);
    }

    /**
     * The e-mail in another folder of the account (moved by hand, by a mail client or a filter of the server): the
     * first one holding it; the trash, the target and the folders already searched are left out. Headers are read
     * only around the date of the e-mail. [message, UTF-8 path of its folder] or [null, ''].
     */
    protected function findElsewhere($client, $messageId, array $searched, array $layout, $date = null)
    {
        $done = array_map('strtoupper', array_merge($searched, ['INBOX']));
        foreach ($layout['paths'] as $path) {
            if (in_array(strtoupper($path), $done, true) || in_array($path, $layout['trash'], true)
                || $this->insideTrash(mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP'), $layout) !== null) {
                continue;
            }
            try {
                $folder = $client->getFolderByPath($path);
            } catch (\Throwable $e) {
                // folder that cannot be opened (\Noselect…): the next one
                continue;
            }
            $found = $this->finder()->find($client, $folder, $messageId, $date, false);
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
