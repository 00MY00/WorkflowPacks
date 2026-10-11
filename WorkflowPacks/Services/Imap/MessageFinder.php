<?php

namespace Modules\WorkflowPacks\Services\Imap;

/**
 * Finds an e-mail by its Message-ID in one folder of the mail server. Several ways, the next one only when the
 * previous one found nothing, because servers answer the IMAP search differently:
 *  1. SEARCH HEADER Message-ID, as FreeScout does;
 *  2. the same search without charset (some servers, Microsoft among them, refuse the charset);
 *  3. the headers of the messages received around the date of the e-mail, compared here one by one (servers whose
 *     search by Message-ID answers nothing); without a date, the last messages of the folder. Reading headers does
 *     not mark the messages as read.
 * Every step is written in $trace (diagnostic "Test a ticket" of the History tab).
 */
class MessageFinder
{
    /** messages whose headers are read at most, per folder */
    const SCAN_LIMIT = 500;
    const CHUNK = 50;

    /** [['folder', 'step' => search|search_nocharset|headers, 'result' => found|none|error, 'detail']] */
    public $trace = [];

    /**
     * @param \Webklex\PHPIMAP\Client $client connected
     * @param \Webklex\PHPIMAP\Folder $folder
     * @param \Carbon\Carbon|null     $date   date of the e-mail (received by FreeScout)
     * @param bool                    $scanWithoutDate read the last messages of the folder when there is no date
     *
     * @return \Webklex\PHPIMAP\Message|null
     */
    public function find($client, $folder, $messageId, $date = null, $scanWithoutDate = true)
    {
        $id = self::normalize($messageId);
        if ($id === '' || !$folder) {
            return null;
        }
        $name = mb_convert_encoding((string) $folder->path, 'UTF-8', 'UTF7-IMAP');
        foreach (['search' => true, 'search_nocharset' => false] as $step => $charset) {
            try {
                $message = $this->bySearch($folder, $id, $charset);
                $this->note($name, $step, $message ? 'found' : 'none', $this->lastError());
                if ($message) {
                    return $message;
                }
            } catch (\Throwable $e) {
                $this->note($name, $step, 'error', $e->getMessage());
            }
        }
        if (!$date && !$scanWithoutDate) {
            return null;
        }
        try {
            $message = $this->byHeaders($client, $folder, $id, $date);
            $this->note($name, 'headers', $message ? 'found' : 'none', $this->lastError());

            return $message;
        } catch (\Throwable $e) {
            $this->note($name, 'headers', 'error', $e->getMessage());

            return null;
        }
    }

    protected function bySearch($folder, $id, $charset)
    {
        $query = $folder->query()->whereMessageId('"<'.addcslashes($id, '\"').'>"')->leaveUnread()->limit(1);
        if (!$charset) {
            if (!method_exists($query, 'setCharset')) {
                return null;
            }
            $query->setCharset(null);
        }

        return $query->get()->first();
    }

    protected function byHeaders($client, $folder, $id, $date)
    {
        $connection = $client->getConnection();
        $client->openFolder($folder->path, true);
        if ($date) {
            // INTERNALDATE of the server may differ from the date seen by FreeScout: a few days around it
            $criteria = 'SINCE "'.$date->copy()->subDays(2)->format('d-M-Y').'" BEFORE "'.$date->copy()->addDays(3)->format('d-M-Y').'"';
        } else {
            $criteria = 'ALL';
        }
        $uids = array_values((array) $connection->search([$criteria]));
        // newest first
        $uids = array_reverse(array_slice($uids, -self::SCAN_LIMIT));
        foreach (array_chunk($uids, self::CHUNK) as $chunk) {
            foreach ((array) $connection->headers($chunk) as $uid => $header) {
                if (strcasecmp(self::messageIdOf($header), $id) === 0) {
                    return $folder->query()->leaveUnread()->getMessageByUid($uid);
                }
            }
        }

        return null;
    }

    /** Message-ID written in raw headers (folded lines joined), without < >. */
    public static function messageIdOf($rawHeaders)
    {
        if (!preg_match('/^Message-ID:[ \t]*((?:.*)(?:\r?\n[ \t]+.*)*)/im', (string) $rawHeaders, $m)) {
            return '';
        }

        return self::normalize(preg_replace('/\s+/', '', $m[1]));
    }

    public static function normalize($messageId)
    {
        return trim((string) $messageId, " \t\r\n<>");
    }

    protected function lastError()
    {
        try {
            if (class_exists('\Webklex\PHPIMAP\Connection\Protocols\ImapProtocol') && method_exists('\Webklex\PHPIMAP\Connection\Protocols\ImapProtocol', 'getLastError')) {
                return (string) \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol::getLastError();
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    protected function note($folder, $step, $result, $detail = '')
    {
        $this->trace[] = ['folder' => $folder, 'step' => $step, 'result' => $result, 'detail' => mb_substr((string) $detail, 0, 300)];
    }
}
