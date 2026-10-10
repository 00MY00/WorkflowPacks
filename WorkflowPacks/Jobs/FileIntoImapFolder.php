<?php

namespace Modules\WorkflowPacks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;
use Modules\WorkflowPacks\Services\Imap\ImapMover;

/**
 * "File into an e-mail folder [WP]": moves the e-mail of a thread on the mail server (queued by ImapFolders when
 * Workflows runs the action). Tried again 5 minutes later when the server does not answer, 3 attempts in all.
 * Every outcome is written in FreeScout's log ("[WorkflowPacks] [imap]").
 */
class FileIntoImapFolder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const ATTEMPTS = 3;
    const RETRY_DELAY = 300;

    /** Laravel's limit, above the --tries=1 of FreeScout's worker */
    public $tries = self::ATTEMPTS;

    public $mailboxId;
    public $threadId;
    public $folder;

    public function __construct($mailboxId, $threadId, $folder)
    {
        $this->mailboxId = (int) $mailboxId;
        $this->threadId = (int) $threadId;
        $this->folder = (string) $folder;
    }

    public function handle()
    {
        if (!ImapFolders::enabled()) {
            return $this->log('skipped: the option is off');
        }
        $mailbox = \App\Mailbox::find($this->mailboxId);
        $thread = \App\Thread::find($this->threadId);
        if (!$mailbox || !$thread || !$thread->message_id) {
            return $this->log('skipped: mailbox or e-mail no longer exists');
        }
        try {
            $r = (new ImapMover())->move($mailbox, $thread->message_id, $this->folder, ImapFolders::prefix());
        } catch (\Throwable $e) {
            // connection or protocol error: the server may be unreachable for a moment
            $r = ['result' => 'failed', 'folder' => '', 'reason' => $e->getMessage(), 'retry' => true];
        }
        $this->log($r['result'].($r['folder'] !== '' ? ' "'.$r['folder'].'"' : '').($r['reason'] !== '' ? ': '.$r['reason'] : ''),
            $r['result'] === 'failed' ? 'warning' : 'info');
        // try again later only when it may help (not for an invalid name or a mailbox without IMAP settings)
        if ($r['result'] === 'failed' && !empty($r['retry']) && $this->job && $this->attempts() < self::ATTEMPTS) {
            $this->release(self::RETRY_DELAY);
        }
    }

    protected function log($text, $level = 'info')
    {
        \Log::{$level}('[WorkflowPacks] [imap] mailbox '.$this->mailboxId.', thread '.$this->threadId.': '.$text);
    }
}
