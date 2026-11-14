<?php

namespace Modules\WorkflowPacks\Services\Engine;

/**
 * What a block receives: the ticket (as the flow sees it), the variables of the run, the time (virtual in a simulation)
 * and the ticket actions. Nothing global: a block only uses this.
 *
 * The ticket is kept as a small state (status, assignee, folder…) updated by the actions, so that the next blocks see
 * the changes — also in a dry run, where nothing is written.
 */
class Context
{
    const STATUS = [1 => 'active', 2 => 'pending', 3 => 'closed', 4 => 'spam'];

    /** @var \App\Conversation|null null in a simulation without a real ticket */
    public $conversation;
    /** @var array subject, body, from, status, user_id, replied, mailbox_id, folder */
    public $ticket = [];
    /** @var array variables set by the blocks */
    public $vars = [];
    /** @var bool simulation / test: nothing is written */
    public $dryRun = false;
    /** @var \Carbon\Carbon */
    public $now;
    /** @var \App\User|null who the changes are made as (the publisher of the version) */
    public $actor;
    /** @var array changes of the current block: [[field, before, after]] */
    public $changes = [];
    /** @var TicketActions */
    protected $actions;

    public function __construct(array $ticket, array $vars = [], $dryRun = false, $now = null)
    {
        $this->ticket = $ticket;
        $this->vars = $vars;
        $this->dryRun = (bool) $dryRun;
        $this->now = $now ?: \Carbon\Carbon::now();
        $this->actions = new TicketActions($this);
    }

    /** Context of a real ticket: its state read once (the body only when a block asks for it). */
    public static function forConversation($conversation, array $vars = [], $dryRun = false)
    {
        $ctx = new self([
            'subject'    => (string) $conversation->subject,
            'from'       => (string) $conversation->customer_email,
            'status'     => self::STATUS[(int) $conversation->status] ?? 'active',
            'user_id'    => (int) $conversation->user_id,
            'mailbox_id' => (int) $conversation->mailbox_id,
            'folder'     => '',
        ], $vars, $dryRun);
        $ctx->conversation = $conversation;

        return $ctx;
    }

    public function actions()
    {
        return $this->actions;
    }

    /** Value of a variable: ticket.<field> (current state of the ticket) or one set by a block. */
    public function get($key)
    {
        if (strpos($key, 'ticket.') === 0) {
            $field = substr($key, 7);
            if ($field === 'assigned') {
                return (int) ($this->ticket['user_id'] ?? 0) > 0;
            }
            if (!array_key_exists($field, $this->ticket) && $this->conversation) {
                $this->ticket[$field] = $this->load($field);
            }

            return $this->ticket[$field] ?? null;
        }

        return $this->vars[$key] ?? null;
    }

    public function set($key, $value)
    {
        $this->vars[$key] = $value;
    }

    public function change($field, $before, $after)
    {
        $this->changes[] = [$field, $before, $after];
    }

    /** Fields read only when needed: the text of the first e-mail, whether the team has replied. */
    protected function load($field)
    {
        if ($field === 'body') {
            $thread = $this->conversation->threads()->where('type', \App\Thread::TYPE_CUSTOMER)->orderBy('id')->first();

            return $thread ? trim(html_entity_decode(strip_tags((string) $thread->body), ENT_QUOTES, 'UTF-8')) : '';
        }
        if ($field === 'replied') {
            return $this->conversation->threads()->where('type', \App\Thread::TYPE_MESSAGE)->exists();
        }

        return null;
    }

    /** {{ticket.subject}}, {{var}} replaced in a text (a note…), values escaped for HTML. */
    public function render($text)
    {
        $self = $this;

        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($self) {
            $value = $self->get($m[1]);

            return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES, 'UTF-8');
        }, (string) $text);
    }
}
