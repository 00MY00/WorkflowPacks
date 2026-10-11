<?php

namespace Modules\WorkflowPacks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;
use Modules\WorkflowPacks\Services\Imap\ImapMover;
use Modules\WorkflowPacks\Services\History;

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
    /** entry of the history to complete with the result */
    public $activityId;
    /** "File again": the e-mail is also looked for outside the inbox (not set in jobs queued by older versions) */
    public $anywhere = false;

    public function __construct($mailboxId, $threadId, $folder, $activityId = null, $anywhere = false)
    {
        $this->mailboxId = (int) $mailboxId;
        $this->threadId = (int) $threadId;
        $this->folder = (string) $folder;
        $this->activityId = $activityId ? (int) $activityId : null;
        $this->anywhere = (bool) $anywhere;
    }

    public function handle()
    {
        if (!ImapFolders::enabled()) {
            History::update($this->activityId, 'skipped', ['message' => __('workflowpacks::messages.history_reason_turned_off')]);

            return $this->log('skipped: the option is off');
        }
        $mailbox = \App\Mailbox::find($this->mailboxId);
        $thread = \App\Thread::find($this->threadId);
        if (!$mailbox || !$thread || !$thread->message_id) {
            History::update($this->activityId, 'skipped', ['message' => __('workflowpacks::messages.history_reason_gone')]);

            return $this->log('skipped: mailbox or e-mail no longer exists');
        }
        try {
            $r = (new ImapMover())->move($mailbox, $thread->message_id, $this->folder, ImapFolders::prefix(), (bool) $this->anywhere, $thread->created_at);
        } catch (\Throwable $e) {
            // connection or protocol error: the server may be unreachable for a moment
            $r = ['result' => 'failed', 'folder' => '', 'reason' => $e->getMessage(), 'retry' => true];
        }
        $this->log($r['result'].($r['folder'] !== '' ? ' "'.$r['folder'].'"' : '').($r['reason'] !== '' ? ': '.$r['reason'] : ''),
            $r['result'] === 'failed' ? 'warning' : 'info');
        $retry = $r['result'] === 'failed' && !empty($r['retry']) && $this->job && $this->attempts() < self::ATTEMPTS;
        History::update($this->activityId, $r['result'], [
            'subject' => $r['folder'] !== '' ? $r['folder'] : null,
            'message' => implode(' — ', array_filter([
                $this->anywhere ? __('workflowpacks::messages.history_reason_again') : '',
                self::reason($r['reason']),
                $retry ? __('workflowpacks::messages.history_reason_retry', ['n' => $this->attempts(), 'max' => self::ATTEMPTS]) : '',
            ], 'strlen')) ?: null,
        ]);
        // try again later only when it may help (not for an invalid name or a mailbox without IMAP settings)
        if ($retry) {
            $this->release(self::RETRY_DELAY);
        }
    }

    /** Reason of the mail server step, in the language of the interface when it is a known one. */
    protected static function reason($reason)
    {
        $known = [
            'the server refused the move' => 'refused_move',
            'cannot create the folder'    => 'cannot_create',
            'not a managed folder'        => 'not_managed',
            'no IMAP settings for this mailbox' => 'no_imap',
        ];
        if (isset($known[$reason])) {
            return __('workflowpacks::messages.history_reason_'.$known[$reason]);
        }
        if (strpos($reason, 'looked in "') === 0) {
            return __('workflowpacks::messages.history_reason_looked_in', ['folders' => trim(substr($reason, strlen('looked in')), ' ')]);
        }
        if (strpos($reason, 'found in "') === 0) {
            return __('workflowpacks::messages.history_reason_found_in', ['folder' => trim(substr($reason, strlen('found in')), ' "')]);
        }
        if (strpos($reason, 'refused: this place is inside the trash folder') === 0) {
            return __('workflowpacks::messages.history_reason_trash', ['folder' => trim(substr($reason, strlen('refused: this place is inside the trash folder')), ' "')]);
        }

        return $reason;
    }

    protected function log($text, $level = 'info')
    {
        \Log::{$level}('[WorkflowPacks] [imap] mailbox '.$this->mailboxId.', thread '.$this->threadId.': '.$text);
    }
}
