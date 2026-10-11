<?php

namespace Modules\WorkflowPacks\Services\Imap;

/**
 * Read-only look at the mail server of a mailbox (Workflow Packs › History, administrators): the folders as the server
 * lists them, its delimiter and personal namespace, where the managed folders go, the trash. Nothing is created,
 * moved or changed.
 */
class ImapInspector extends ImapMover
{
    /**
     * @return array ['ok' => bool, 'error' => string, 'delimiter', 'namespace', 'parent', 'trash' => string[],
     *               'folders' => [['path' => UTF-8 path, 'managed' => bool, 'trash' => bool]]]
     */
    public function inspect($mailbox, $prefix)
    {
        if (!$mailbox->in_server || !$mailbox->in_username) {
            return ['ok' => false, 'error' => 'no IMAP settings for this mailbox'];
        }
        try {
            $client = \MailHelper::getMailboxClient($mailbox);
            $client->connect();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        try {
            $layout = $this->layout($client);
            $folders = [];
            foreach ($layout['paths'] as $path) {
                $name = mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');
                $folders[] = [
                    'path'    => $name,
                    'managed' => FolderPath::isManaged($name, $prefix, $layout['delimiter'], $layout['parent']),
                    'trash'   => in_array($path, $layout['trash'], true),
                ];
            }
            usort($folders, function ($a, $b) {
                return strcasecmp($a['path'], $b['path']);
            });

            return [
                'ok'        => true,
                'error'     => '',
                'delimiter' => $layout['delimiter'],
                'namespace' => $layout['namespace'],
                'parent'    => $layout['parent'],
                'trash'     => array_map(function ($p) {
                    return mb_convert_encoding($p, 'UTF-8', 'UTF7-IMAP');
                }, $layout['trash']),
                'folders'   => $folders,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * "Test a ticket": how the e-mail of a ticket is looked for on the mail server, step by step (read only): the
     * Message-ID FreeScout knows, each way of searching in each folder with the answer of the server, where it was
     * found, and the last e-mails of the inbox to compare with.
     *
     * @return array ['ok', 'error', 'message_id', 'date', 'trace' => [...], 'found_in' => string|null,
     *               'recent_folder', 'recent' => [['date', 'subject', 'message_id']]]
     */
    public function trace($mailbox, $conversation)
    {
        $thread = $conversation->threads()->where('type', \App\Thread::TYPE_CUSTOMER)
            ->whereNotNull('message_id')->where('message_id', '!=', '')->orderBy('id', 'desc')->first();
        if (!$thread) {
            return ['ok' => false, 'error' => __('workflowpacks::messages.history_reason_no_email')];
        }
        $out = ['ok' => true, 'error' => '', 'message_id' => MessageFinder::normalize($thread->message_id),
            'date' => (string) $thread->created_at, 'trace' => [], 'found_in' => null, 'recent_folder' => '', 'recent' => []];
        if (!$mailbox->in_server || !$mailbox->in_username) {
            return ['ok' => false, 'error' => __('workflowpacks::messages.history_reason_no_imap')] + $out;
        }
        try {
            $client = \MailHelper::getMailboxClient($mailbox);
            $client->connect();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()] + $out;
        }
        try {
            $layout = $this->layout($client);
            $fetched = $this->fetchedFolders($mailbox);
            foreach ($fetched as $path) {
                try {
                    $folder = $client->getFolderByPath($path);
                } catch (\Throwable $e) {
                    $folder = null;
                }
                if ($this->finder()->find($client, $folder, $thread->message_id, $thread->created_at)) {
                    $out['found_in'] = mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');
                    break;
                }
            }
            if ($out['found_in'] === null) {
                list($message, $from) = $this->findElsewhere($client, $thread->message_id, $fetched, $layout, $thread->created_at);
                $out['found_in'] = $message ? $from : null;
            }
            $out['recent_folder'] = mb_convert_encoding($fetched[0], 'UTF-8', 'UTF7-IMAP');
            $out['recent'] = $this->recent($client, $fetched[0]);
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['error'] = $e->getMessage();
        } finally {
            $out['trace'] = $this->finder()->trace;
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }

    /** The last e-mails of a folder as the server lists them (headers only, nothing marked as read). */
    protected function recent($client, $path, $count = 8)
    {
        $out = [];
        try {
            $folder = $client->getFolderByPath($path);
            if (!$folder) {
                return [];
            }
            $client->openFolder($folder->path, true);
            $connection = $client->getConnection();
            $uids = array_reverse(array_slice(array_values((array) $connection->search(['ALL'])), -$count));
            foreach ((array) $connection->headers($uids) as $uid => $raw) {
                $subject = preg_match('/^Subject:[ \t]*(.*)$/im', (string) $raw, $m) ? trim($m[1]) : '';
                $date = preg_match('/^Date:[ \t]*(.*)$/im', (string) $raw, $d) ? trim($d[1]) : '';
                $out[] = ['date' => $date, 'subject' => function_exists('iconv_mime_decode') ? (string) @iconv_mime_decode($subject, 2, 'UTF-8') : $subject,
                    'message_id' => MessageFinder::messageIdOf($raw)];
            }
        } catch (\Throwable $e) {
            $out[] = ['date' => '', 'subject' => $e->getMessage(), 'message_id' => ''];
        }

        return $out;
    }
}
