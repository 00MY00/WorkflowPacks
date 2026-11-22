<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Services\Imap\ImapFolders;

/**
 * The only place where a block changes a ticket. Each action updates the state seen by the next blocks and records
 * the change (before -> after); in a dry run it stops there. FreeScout's own methods are used, so the ticket shows
 * the usual lines ("status changed…") and the other modules are told.
 */
class TicketActions
{
    /** @var Context */
    protected $ctx;

    public function __construct(Context $ctx)
    {
        $this->ctx = $ctx;
    }

    /** @param string $status active | pending | closed | spam */
    public function status($status)
    {
        $before = $this->ctx->ticket['status'] ?? null;
        if ($before === $status) {
            return false;
        }
        if (!$this->ctx->dryRun) {
            $this->ctx->conversation->changeStatus((int) array_search($status, Context::STATUS, true), $this->ctx->actor, true);
        }
        $this->ctx->ticket['status'] = $status;
        $this->ctx->change('status', $before, $status);

        return true;
    }

    /** @param int $userId -1: nobody */
    public function assign($userId)
    {
        $userId = (int) $userId;
        $before = (int) ($this->ctx->ticket['user_id'] ?? 0);
        if ($before === max(0, $userId)) {
            return false;
        }
        if (!$this->ctx->dryRun) {
            $this->ctx->conversation->changeUser($userId > 0 ? $userId : null, $this->ctx->actor, true);
        }
        $this->ctx->ticket['user_id'] = max(0, $userId);
        $this->ctx->change('assignee', self::userName($before), self::userName($userId), [$before ?: null, $userId > 0 ? $userId : null]);

        return true;
    }

    /** Internal note (HTML already escaped by Context::render). */
    public function note($html)
    {
        if (!$this->ctx->dryRun) {
            $actor = $this->ctx->actor;
            \App\Thread::create($this->ctx->conversation, \App\Thread::TYPE_NOTE, $html, [
                'user_id'            => $this->ctx->conversation->user_id,
                'created_by_user_id' => $actor ? $actor->id : null,
                'source_via'         => \App\Thread::PERSON_USER,
                'source_type'        => \App\Thread::SOURCE_TYPE_WEB,
            ]);
        }
        $this->ctx->change('note', '', mb_substr(strip_tags($html), 0, 60));

        return true;
    }

    /** E-mail folder on the mail server (queued). False: nothing to move (reason in History). */
    /** $mailboxId: the address where the e-mail is filed (null: the mailbox where it arrived). */
    public function imapFolder($path, $mailboxId = null)
    {
        $before = $this->ctx->ticket['folder'] ?? '';
        if (!$this->ctx->dryRun && !ImapFolders::queue($this->ctx->conversation, $path, $mailboxId)) {
            return false;
        }
        $this->ctx->ticket['folder'] = $path;
        $this->ctx->change('folder', $before, $path);

        return true;
    }

    /** To the "Deleted" folder, as FreeScout's "Delete" button (restorable from there). False: already deleted. */
    public function delete()
    {
        if (!empty($this->ctx->ticket['deleted'])
            || ($this->ctx->conversation && (int) $this->ctx->conversation->state === \App\Conversation::STATE_DELETED)) {
            return false;
        }
        if (!$this->ctx->dryRun) {
            $this->ctx->conversation->deleteToFolder($this->ctx->actor);
        }
        $this->ctx->ticket['deleted'] = true;
        $this->ctx->change('deleted', '', __('workflowpacks::blocks.ops.yes'));

        return true;
    }

    /**
     * Push notification through the WebPush module (Services/Notify/WebPush). @return array|null what became of it
     * (WebPush::send()); null in a dry run, which only says to whom.
     */
    public function push(array $users, $title, $text)
    {
        $report = $this->ctx->dryRun ? null
            : \Modules\WorkflowPacks\Services\Notify\WebPush::send($users, $title, $text, $this->ctx->conversation);
        $names = array_map(function ($u) {
            return $u->getFullName();
        }, array_slice($users, 0, 5));
        // for the other modules: the agents it was really sent to
        $this->ctx->change('push', '', implode(', ', $names).(count($users) > 5 ? ' (+'.(count($users) - 5).')' : '').' : '.mb_substr($title, 0, 60),
            [null, $report === null ? count($users) : $report['reached']]);

        return $report;
    }

    protected static function userName($id)
    {
        if ((int) $id <= 0) {
            return '—';
        }
        $user = \App\User::find((int) $id);

        return $user ? $user->getFullName() : '#'.(int) $id;
    }
}
