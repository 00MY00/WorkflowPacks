<?php

namespace Modules\WorkflowPacks\Services\Flow;

use Modules\WorkflowPacks\Services\Imap\FolderPath;
use Modules\WorkflowPacks\Services\Pack\Timer;

/**
 * Simulation of a fake e-mail through the rules of a mailbox — in memory only, no Laravel dependency (tests/unit).
 *
 * Nothing is written, nothing is sent, no e-mail folder is touched: the e-mail and its ticket exist only in the
 * arrays of this class and are gone when it returns. Sending actions (reply, forward, notification) are recorded
 * as "would be sent to …".
 *
 * Time line, as Workflows works:
 *  1. arrival (t = 0, an event): the rules WITHOUT date condition, in the order of Workflows;
 *  2. the scheduler's checks: at each moment a delay is passed (t = delay + 1 minute), the rules WITH a date
 *     condition that have not run yet.
 * A rule runs at most once for a ticket (max_executions = 1, the default). After each rule, the state of the ticket
 * is kept, so that what every block changed can be seen.
 */
class Simulator
{
    const MAX_RULES = 500;
    const MAX_CHECKS = 200;
    const MAX_TEXT = 20000;

    /** @param array $input subject, body, customer_email, customer_name, to, cc, tags[], assigned, replied, mailbox_id */
    public static function mail(array $input)
    {
        $text = function ($key, $max) use ($input) {
            return mb_substr(trim((string) ($input[$key] ?? '')), 0, $max);
        };

        return [
            'subject'        => $text('subject', 255),
            'body'           => $text('body', self::MAX_TEXT),
            'customer_email' => $text('customer_email', 191),
            'customer_name'  => $text('customer_name', 191),
            'to'             => $text('to', 191),
            'cc'             => $text('cc', 1000),
            'tags'           => array_slice(array_values(array_filter(array_map(function ($tag) {
                return mb_substr(trim((string) $tag), 0, 100);
            }, (array) ($input['tags'] ?? [])), 'strlen')), 0, 20),
            'assigned'       => !empty($input['assigned']),
            'replied'        => !empty($input['replied']),
            'mailbox_id'     => (int) ($input['mailbox_id'] ?? 0),
        ];
    }

    /** The ticket at the arrival of the e-mail. user_id: 0 nobody, -10 "the current user", else a person. */
    public static function state(array $mail)
    {
        return [
            'status'     => 1,
            'user_id'    => $mail['assigned'] ? -10 : 0,
            'mailbox_id' => $mail['mailbox_id'],
            'folder'     => '',
            'tags'       => $mail['tags'],
            'notes'      => [],
            'sent'       => [],
            'reply_at'   => $mail['replied'] ? 0 : null,
            'deleted'    => '',
        ];
    }

    /**
     * @param array $rules ['id', 'name', 'active', 'conditions' (lanes), 'actions' (decoded list)], in the order of
     *                     Workflows
     * @param array $mail  self::mail()
     * @param bool  $includeInactive rules not enabled yet are simulated too (the usual case before enabling a pack)
     * @return array ['events' => [...], 'skipped' => [...], 'final' => state, 'processed' => bool, 'uncertain' => bool,
     *                'checks' => [t…]]
     */
    public function run(array $rules, array $mail, $includeInactive = true)
    {
        $rules = array_slice(array_values($rules), 0, self::MAX_RULES);
        $state = self::state($mail);
        $events = [];
        $last = [];
        $done = [];
        $uncertain = false;

        $checks = [0];
        foreach ($rules as $rule) {
            foreach (self::conditions($rule) as $condition) {
                if (FlowBuilder::isDelay($condition)) {
                    $checks[] = Timer::seconds(Timer::check($condition['value'])['value']) + 60;
                }
            }
        }
        $checks = array_values(array_unique($checks));
        sort($checks);
        $checks = array_slice($checks, 0, self::MAX_CHECKS);

        foreach ($checks as $t) {
            foreach ($rules as $rule) {
                $id = (int) $rule['id'];
                if (isset($done[$id]) || $state['deleted'] !== '') {
                    continue;
                }
                if (!$rule['active'] && !$includeInactive) {
                    $last[$id] = ['rule' => $rule, 'result' => false, 'reason' => 'sim_inactive', 'conditions' => []];
                    continue;
                }
                // arrival: rules without date condition; scheduler: rules with one
                if (self::hasDate($rule) === ($t === 0)) {
                    continue;
                }
                $checked = $this->check($rule, $mail, $state, $t, $t === 0);
                $last[$id] = ['rule' => $rule, 'result' => $checked['result'], 'reason' => $checked['result'] === null ? 'sim_uncertain' : 'sim_no_match', 'conditions' => $checked['conditions']];
                if ($checked['result'] !== true) {
                    continue;
                }
                $done[$id] = true;
                unset($last[$id]);
                $actions = [];
                foreach (array_values((array) $rule['actions']) as $i => $action) {
                    $actions[] = ['index' => $i, 'type' => (string) ($action['type'] ?? ''), 'kind' => FlowBuilder::actionKind($action), 'action' => $action]
                        + $this->apply($action, $state, $mail, $t);
                }
                $events[] = ['t' => $t, 'workflow_id' => $id, 'name' => (string) $rule['name'], 'active' => (bool) $rule['active'],
                    'conditions' => $checked['conditions'], 'actions' => $actions, 'state' => $state];
            }
        }

        $skipped = [];
        foreach ($rules as $rule) {
            $id = (int) $rule['id'];
            if (isset($last[$id])) {
                $uncertain = $uncertain || $last[$id]['result'] === null;
                $skipped[] = ['workflow_id' => $id, 'name' => (string) $rule['name'], 'active' => (bool) $rule['active'],
                    'reason' => $last[$id]['reason'], 'conditions' => $last[$id]['conditions']];
            } elseif (!isset($done[$id]) && $state['deleted'] !== '') {
                $skipped[] = ['workflow_id' => $id, 'name' => (string) $rule['name'], 'active' => (bool) $rule['active'], 'reason' => 'sim_deleted', 'conditions' => []];
            }
        }

        return ['events' => $events, 'skipped' => $skipped, 'final' => $state, 'processed' => (bool) $events, 'uncertain' => $uncertain, 'checks' => $checks];
    }

    /** Lanes of Workflows: every lane must be true (one true condition is enough in a lane). */
    protected function check(array $rule, array $mail, array $state, $t, $event)
    {
        $rows = [];
        $result = true;
        foreach ((array) $rule['conditions'] as $lane) {
            $lane = array_values(array_filter((array) $lane, 'is_array'));
            $laneResult = false;
            foreach ($lane as $condition) {
                $answer = ConditionEvaluator::evaluate($condition, $mail, $state, $t, $event);
                $rows[] = ['type' => (string) ($condition['type'] ?? ''), 'operator' => (string) ($condition['operator'] ?? ''),
                    'value' => $condition['value'] ?? null, 'result' => $answer['result'], 'note' => $answer['note']];
                if ($answer['result'] === true) {
                    $laneResult = true;
                } elseif ($answer['result'] === null && $laneResult === false) {
                    $laneResult = null;
                }
            }
            if ($lane && $laneResult === false) {
                $result = false;
            } elseif ($lane && $laneResult === null && $result === true) {
                $result = null;
            }
        }

        return ['result' => $result, 'conditions' => $rows];
    }

    /**
     * Applies an action to the simulated ticket. status: applied (state changed), recorded (would be sent; nothing
     * leaves this class) or unsupported (an action of another module: shown, not simulated).
     */
    protected function apply(array $action, array &$state, array $mail, $t)
    {
        $type = (string) ($action['type'] ?? '');
        $value = $action['value'] ?? null;
        $before = $state;
        $status = 'applied';
        switch (FlowBuilder::actionKind($action)) {
            case 'status-active':
            case 'status-pending':
            case 'status-closed':
            case 'status-spam':
                $state['status'] = (int) $value;
                break;
            case 'assign':
                $state['user_id'] = (string) $value === '-1' ? 0 : (int) $value;
                break;
            case 'move':
                $state['mailbox_id'] = (int) $value;
                break;
            case 'folder':
                $parsed = FolderPath::parse($value);
                $state['folder'] = $parsed['error'] === null ? implode('/', $parsed['segments']) : (is_scalar($value) ? (string) $value : '');
                break;
            case 'note':
                $state['notes'][] = mb_substr(trim(strip_tags((string) (is_array($value) ? ($value['body'] ?? '') : $value))), 0, 200);
                break;
            case 'send':
                $to = $type === 'forward' ? (string) (is_array($value) ? ($value['to'] ?? '') : '') : ($type === 'notification' ? '' : $mail['customer_email']);
                $state['sent'][] = ['type' => $type, 'to' => mb_substr($to, 0, 191)];
                if ($type === 'reply' || $type === 'email_customer') {
                    $state['reply_at'] = $t;
                }
                $status = 'recorded';
                break;
            case 'delete':
                $state['deleted'] = $type === 'delete_forever' ? 'forever' : 'trash';
                break;
            case 'tag':
                foreach ((array) $value as $tag) {
                    if (is_scalar($tag) && trim((string) $tag) !== '' && !in_array((string) $tag, $state['tags'], true)) {
                        $state['tags'][] = (string) $tag;
                    }
                }
                break;
            default:
                $status = 'unsupported';
        }

        return ['status' => $status, 'changes' => self::changes($before, $state)];
    }

    /** field => [before, after], only what changed. */
    public static function changes(array $before, array $after)
    {
        $out = [];
        foreach ($after as $field => $value) {
            if ($field !== 'reply_at' && ($before[$field] ?? null) !== $value) {
                $out[$field] = [$before[$field] ?? null, $value];
            }
        }

        return $out;
    }

    protected static function conditions(array $rule)
    {
        $out = [];
        foreach ((array) $rule['conditions'] as $lane) {
            foreach ((array) $lane as $condition) {
                if (is_array($condition)) {
                    $out[] = $condition;
                }
            }
        }

        return $out;
    }

    /** A rule with a date condition is checked by Workflows' scheduler, not on the arrival of the e-mail. */
    protected static function hasDate(array $rule)
    {
        foreach (self::conditions($rule) as $condition) {
            if (Timer::isTimer($condition['type'] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
