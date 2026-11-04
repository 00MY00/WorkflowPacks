<?php

namespace Modules\WorkflowPacks\Services\Flow;

use Modules\WorkflowPacks\Services\Imap\FolderPath;
use Modules\WorkflowPacks\Services\Pack\Timer;

/**
 * Texts of the flow blocks, from Workflows' catalog of the mailbox — no Laravel dependency: the translation and the
 * names of users / mailboxes are given as functions (FreeScout: __(), User::find(), Mailbox::find()).
 */
class FlowLabels
{
    protected $catalog;
    protected $t;
    protected $userName;
    protected $mailboxName;

    /**
     * @param array    $catalog     Bridge::catalog($mailboxId)
     * @param callable $t           function (key, params): text of messages.php
     * @param callable $userName    function (int id): name
     * @param callable $mailboxName function (int id): name
     */
    public function __construct(array $catalog, callable $t, callable $userName, callable $mailboxName)
    {
        $this->catalog = $catalog;
        $this->t = $t;
        $this->userName = $userName;
        $this->mailboxName = $mailboxName;
    }

    public function all()
    {
        return ($this->t)('flow_all', []);
    }

    /** "Subject contains « invoice »", "Assigned: no", or "a OR b" for a lane of OR conditions. */
    public function condition(array $condition)
    {
        if (($condition['type'] ?? '') === '__or') {
            return implode(' '.($this->t)('flow_or', []).' ', array_map([$this, 'condition'], $condition['items']));
        }
        $type = (string) ($condition['type'] ?? '');
        $item = $this->catalog['conditions'][$type] ?? [];
        $title = $item['title'] ?? $type;
        $operator = (string) ($condition['operator'] ?? '');
        $op = $item['operator_labels'][$operator] ?? $operator;
        if (in_array($type, TicketConditions::TYPES, true)) {
            return $title.' : '.$op;
        }
        $value = $condition['value'] ?? null;
        if (is_array($value) && Timer::check($value)['error'] === null) {
            return $title.' '.$op.' '.Timer::label(Timer::check($value)['value']);
        }
        if ($value === null || $value === '' || (isset($item['values']) && $item['values'] === [])) {
            return trim($title.' '.$op);
        }
        $text = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = $item['value_labels'][$text] ?? $text;

        return $title.' '.$op.' « '.mb_strimwidth($text, 0, 80, '…').' »';
    }

    /** "After 2 days · since the arrival" */
    public function delay(array $condition)
    {
        $value = Timer::check($condition['value'] ?? null)['value'];
        $since = ['created' => 'flow_since_created', 'waiting' => 'flow_since_waiting', 'user_reply' => 'flow_since_user_reply', 'customer_reply' => 'flow_since_customer_reply'];

        return ($this->t)('flow_delay', ['delay' => Timer::label($value)]).' · '.($this->t)($since[$condition['type']] ?? 'flow_since_created', []);
    }

    /** ['label' => what, 'sub' => kind of action] */
    public function action(array $action)
    {
        $type = (string) ($action['type'] ?? '');
        $value = $action['value'] ?? null;
        $kind = FlowBuilder::actionKind($action);
        $title = $this->catalog['actions'][$type]['title'] ?? $type;
        $t = $this->t;
        if (strpos($kind, 'status-') === 0) {
            return ['label' => $t('dest_'.substr($kind, 7), []), 'sub' => $t('flow_status', [])];
        }
        switch ($kind) {
            case 'folder':
                $parsed = FolderPath::parse($value);

                return ['label' => $parsed['error'] === null ? implode(' / ', $parsed['segments']) : (string) (is_scalar($value) ? $value : '?'), 'sub' => $t('dest_imap', [])];
            case 'assign':
                $label = (string) $value === '-1' ? $t('dest_unassigned', []) : ((string) $value === '-10' ? $t('dest_current_user', []) : ($this->userName)((int) $value));

                return ['label' => $label, 'sub' => $t('dest_assigned', [])];
            case 'move':
                return ['label' => ($this->mailboxName)((int) $value), 'sub' => $t('dest_other_mailbox', [])];
            case 'note':
                $body = trim(strip_tags((string) (is_array($value) ? ($value['body'] ?? '') : $value)));

                return ['label' => $title, 'sub' => mb_strimwidth($body, 0, 60, '…')];
            case 'send':
                $to = $type === 'forward' && is_array($value) ? (string) ($value['to'] ?? '') : ($type === 'notification' ? '' : $t('flow_customer', []));

                return ['label' => $title, 'sub' => $to];
            case 'delete':
                return ['label' => $t('dest_'.$type, []), 'sub' => $t('dest_folder', [])];
            case 'tag':
                return ['label' => implode(', ', array_filter((array) $value, 'is_scalar')), 'sub' => $t('dest_tag', [])];
        }

        return ['label' => $title, 'sub' => is_scalar($value) ? mb_strimwidth((string) $value, 0, 60, '…') : ''];
    }
}
