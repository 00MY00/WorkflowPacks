<?php

namespace Modules\WorkflowPacks\Services\Imap;

use Modules\WorkflowPacks\Entities\Retention as RetentionRow;
use Modules\WorkflowPacks\Services\Pack\Timer;

/**
 * Automatic clean-up of the managed e-mail folders: "the e-mails of [WP] Newsletters are deleted after 7 days".
 *
 * One setting per folder (as written in the rules, "Newsletters/Promotions"), for every mailbox where that folder
 * exists on the mail server. Run every 15 minutes by FreeScout's scheduler (workflowpacks:clean-folders), only while
 * the option "File into an e-mail folder" is on. The age of an e-mail is the one of its date (reception).
 *
 *   mode trash   moved to the trash folder of the account (deleted for good if the account has none)
 *   mode delete  deleted for good (flag + EXPUNGE of that managed folder only)
 */
class Retention
{
    const MODES = ['trash', 'delete'];
    /** e-mails handled per folder and per run (the next run goes on) */
    const BATCH = 500;

    /** Folder path as written in the rules, normalized ("Newsletters / Promos" -> "Newsletters/Promos"), or null. */
    public static function key($folder)
    {
        $parsed = FolderPath::parse($folder);

        return $parsed['error'] === null ? implode('/', $parsed['segments']) : null;
    }

    public static function all()
    {
        return RetentionRow::orderBy('folder')->get();
    }

    public static function forFolder($folder)
    {
        $key = self::key($folder);

        return $key === null ? null : RetentionRow::where('folder', $key)->first();
    }

    /** @return string|null error (key of texts.php) or null when saved */
    public static function save($folder, $delay, $mode, $user)
    {
        $key = self::key($folder);
        if ($key === null) {
            return FolderPath::parse($folder)['error'];
        }
        $timer = Timer::check($delay);
        if ($timer['error'] !== null) {
            return $timer['error'];
        }
        if (!in_array($mode, self::MODES, true)) {
            return 'retention_mode';
        }
        RetentionRow::updateOrCreate(['folder' => $key], [
            'number'     => $timer['value']['number'],
            'metric'     => $timer['value']['metric'],
            'mode'       => $mode,
            'updated_by' => $user ? $user->id : null,
        ]);

        return null;
    }

    public static function remove($folder)
    {
        $key = self::key($folder);
        if ($key !== null) {
            RetentionRow::where('folder', $key)->delete();
        }
    }

    /**
     * One run for every setting and every mailbox fetched by IMAP.
     *
     * @param callable|null $out function (string $line)
     * @return array ['moved' => n, 'deleted' => n, 'errors' => n]
     */
    public function run(?callable $out = null, $now = null)
    {
        $out = $out ?: function () {
        };
        $total = ['moved' => 0, 'deleted' => 0, 'errors' => 0];
        if (!ImapFolders::enabled()) {
            $out('option "file into an e-mail folder" off: nothing to clean');

            return $total;
        }
        \Modules\WorkflowPacks\Services\History::prune();
        $rows = self::all();
        if ($rows->isEmpty()) {
            return $total;
        }
        $now = $now ?: time();
        $prefix = ImapFolders::prefix();
        $mailboxes = \App\Mailbox::whereNotNull('in_server')->where('in_server', '!=', '')->get();
        foreach ($rows as $row) {
            $delay = ['number' => (int) $row->number, 'metric' => $row->metric];
            $cutoff = $now - Timer::seconds($delay);
            foreach ($mailboxes as $mailbox) {
                if (!$mailbox->in_username) {
                    continue;
                }
                try {
                    $r = (new ImapCleaner())->clean($mailbox, $row->folder, $prefix, $cutoff, $row->mode, self::BATCH);
                } catch (\Throwable $e) {
                    $r = ['moved' => 0, 'deleted' => 0, 'folder' => '', 'error' => $e->getMessage()];
                }
                $total['moved'] += $r['moved'];
                $total['deleted'] += $r['deleted'];
                if ($r['error'] !== '') {
                    $total['errors']++;
                }
                if ($r['moved'] || $r['deleted'] || $r['error'] !== '') {
                    $line = 'mailbox '.$mailbox->id.', "'.$r['folder'].'": '.$r['moved'].' moved to the trash, '.$r['deleted'].' deleted'
                        .($r['error'] !== '' ? ' — '.$r['error'] : '');
                    $out($line);
                    \Modules\WorkflowPacks\Services\History::add('imap_clean', $r['error'] !== '' ? 'failed' : 'ok', ['mailbox_id' => $mailbox->id, 'user_id' => 0,
                        'subject' => $r['folder'], 'message' => json_encode(['moved' => $r['moved'], 'deleted' => $r['deleted'], 'error' => $r['error']])]);
                    \Log::{$r['error'] !== '' ? 'warning' : 'info'}('[WorkflowPacks] [clean-up] '.$line);
                }
            }
        }

        return $total;
    }
}
