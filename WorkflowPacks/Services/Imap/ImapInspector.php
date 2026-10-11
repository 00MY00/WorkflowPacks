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
}
