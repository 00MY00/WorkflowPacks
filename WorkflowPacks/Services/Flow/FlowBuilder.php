<?php

namespace Modules\WorkflowPacks\Services\Flow;

use Modules\WorkflowPacks\Services\Imap\FolderPath;
use Modules\WorkflowPacks\Services\Pack\Timer;

/**
 * Flow view: the rules of a mailbox shown as chains of blocks, one block per instruction — no Laravel dependency
 * (tested by tests/unit). The rules of Workflows stay the only truth: this is a reading of them, never stored.
 *
 *   mailbox → [condition] → [condition] … → [branch: assigned? replied?] → [delay] → [action] → [delay] → [action] …
 *
 * Each condition of the flow is a block of its own (status of the ticket, assignee, subject, customer, tag…; a lane
 * of OR conditions is one block "a OR b").
 *
 *  - rules with the same conditions (delays and branches aside) form ONE flow: "after 1 hour → folder",
 *    "after 2 days → closed" are two rules of Workflows, one chain here;
 *  - in a flow, the rules with the same branch conditions (wp_assigned / wp_replied) form one lane, ordered by delay
 *    (rules without delay first, at the arrival of the e-mail), then by their order in Workflows;
 *  - "New / Reply: new" alone in its lane is the start of the chain (the arrival), not a condition of the flow: the
 *    rule "on arrival → note" and the rules "after 1 hour", "after 2 days" are one chain;
 *  - a delay is a date condition "older than": created / waiting / last reply… not in the last X (Workflows checks it
 *    again and again from its scheduler). Several delays in one rule: one block, the longest one orders it.
 *
 * Block keys are stable (fc: / fb: / ft: / fa:), so that the simulation can light the path of a rule.
 */
class FlowBuilder
{
    /** Date conditions that mean "older than X": the delay blocks. */
    const DELAY_OPERATORS = ['not_in_last', 'longer'];

    const STATUS = ['1' => 'active', '2' => 'pending', '3' => 'closed', '4' => 'spam'];
    const SENDS = ['reply', 'email_customer', 'forward', 'notification'];

    /** @var FlowLabels|object labels of conditions, actions and delays (condition(), action(), delay(), all()) */
    protected $labels;

    public function __construct($labels)
    {
        $this->labels = $labels;
    }

    /**
     * @param int   $mailboxId
     * @param array $rules list of ['id', 'name', 'active', 'conditions' (Workflows' lanes), 'actions' (decoded list),
     *                     'origin', 'pack'], in the order of Workflows
     * @return array list of flows: ['key', 'conditions' => [labels], 'blocks' => [['key', 'family', 'label']],
     *               'lanes' => [['key', 'label', 'steps' => [...]]]]
     */
    public function build($mailboxId, array $rules)
    {
        $flows = [];
        foreach (array_values($rules) as $order => $rule) {
            $parts = self::split((array) $rule['conditions']);
            $flowHash = substr(md5(self::canon($parts['base'])), 0, 10);
            $flowKey = 'fc:'.$mailboxId.':'.$flowHash;
            if (!isset($flows[$flowKey])) {
                $flows[$flowKey] = [
                    'key'        => $flowKey,
                    'conditions' => $parts['base'] ? array_map([$this->labels, 'condition'], self::flat($parts['base'])) : [$this->labels->all()],
                    // one block per condition: the first one has the key of the flow
                    'blocks'     => $parts['base'] ? array_map(function ($item, $i) use ($flowKey) {
                        return ['key' => $i ? $flowKey.':'.$i : $flowKey, 'family' => self::conditionFamily($item), 'label' => $this->labels->condition($item)];
                    }, self::flat($parts['base']), array_keys($parts['base'])) : [['key' => $flowKey, 'family' => 'all', 'label' => $this->labels->all()]],
                    // Workflows' lanes, for the next steps of the diagram (a new rule of the same flow)
                    'base'       => $parts['base'],
                    'lanes'      => [],
                ];
            }
            $laneHash = $parts['branch'] ? substr(md5(self::canon($parts['branch'])), 0, 8) : '';
            if (!isset($flows[$flowKey]['lanes'][$laneHash])) {
                $flows[$flowKey]['lanes'][$laneHash] = [
                    'key'   => $laneHash === '' ? null : 'fb:'.$mailboxId.':'.$flowHash.':'.$laneHash,
                    'label' => $parts['branch'] ? implode(' · ', array_map([$this->labels, 'condition'], $parts['branch'])) : '',
                    'branch' => $parts['branch'],
                    'steps' => [],
                ];
            }
            $id = (int) $rule['id'];
            $flows[$flowKey]['lanes'][$laneHash]['steps'][] = [
                'workflow_id' => $id,
                'name'        => (string) $rule['name'],
                'active'      => (bool) $rule['active'],
                'origin'      => $rule['origin'] ?? 'local',
                'pack'        => $rule['pack'] ?? null,
                'order'       => $order,
                'delay'       => $parts['delays'] ? [
                    'key'     => 'ft:'.$id,
                    'seconds' => $parts['seconds'],
                    'label'   => implode(' · ', array_map([$this->labels, 'delay'], $parts['delays'])),
                    'conditions' => $parts['delays'],
                ] : null,
                'actions'     => array_map(function ($action, $i) use ($id) {
                    return ['key' => 'fa:'.$id.':'.$i, 'kind' => self::actionKind($action)] + $this->labels->action($action);
                }, array_values((array) $rule['actions']), array_keys(array_values((array) $rule['actions']))),
            ];
        }

        // lanes: rules at the arrival first, then by delay, then in the order of Workflows
        foreach ($flows as $k => $flow) {
            foreach ($flow['lanes'] as $h => $lane) {
                usort($lane['steps'], function ($a, $b) {
                    $da = $a['delay'] ? $a['delay']['seconds'] : 0;
                    $db = $b['delay'] ? $b['delay']['seconds'] : 0;

                    return $da === $db ? $a['order'] - $b['order'] : ($da < $db ? -1 : 1);
                });
                $flows[$k]['lanes'][$h] = $lane;
            }
            $flows[$k]['lanes'] = array_values($flows[$k]['lanes']);
        }

        return array_values($flows);
    }

    /**
     * Keys of the blocks a rule goes through, from the mailbox: what the simulation lights.
     *
     * @return array workflow id => [keys]
     */
    public static function paths($mailboxId, array $flows)
    {
        $out = [];
        foreach ($flows as $flow) {
            foreach ($flow['lanes'] as $lane) {
                foreach ($lane['steps'] as $step) {
                    $keys = array_merge(['mb:'.$mailboxId], array_column($flow['blocks'], 'key'));
                    if ($lane['key']) {
                        $keys[] = $lane['key'];
                    }
                    if ($step['delay']) {
                        $keys[] = $step['delay']['key'];
                    }
                    foreach ($step['actions'] as $action) {
                        $keys[] = $action['key'];
                    }
                    $out[$step['workflow_id']] = $keys;
                }
            }
        }

        return $out;
    }

    /**
     * Workflows' lanes split in: base conditions (the flow), branch conditions (the lane), delays.
     * A lane of several OR conditions always stays in the base: only a lane with ONE condition is a branch or a delay.
     */
    public static function split(array $lanes)
    {
        $out = ['base' => [], 'branch' => [], 'delays' => [], 'seconds' => 0];
        foreach ($lanes as $lane) {
            $lane = array_values(array_filter((array) $lane, 'is_array'));
            if (count($lane) === 1 && self::isDelay($lane[0])) {
                $out['delays'][] = $lane[0];
                $out['seconds'] = max($out['seconds'], Timer::seconds(Timer::check($lane[0]['value'])['value']));
            } elseif (count($lane) === 1 && ($lane[0]['type'] ?? '') === 'new_or_reply' && ($lane[0]['operator'] ?? '') === 'new') {
                continue;
            } elseif (count($lane) === 1 && in_array($lane[0]['type'] ?? '', TicketConditions::TYPES, true)) {
                $out['branch'][] = $lane[0];
            } elseif ($lane) {
                $out['base'][] = $lane;
            }
        }
        usort($out['branch'], function ($a, $b) {
            return strcmp($a['type'], $b['type']);
        });

        return $out;
    }

    public static function isDelay(array $condition)
    {
        return Timer::isTimer($condition['type'] ?? '') && in_array($condition['operator'] ?? '', self::DELAY_OPERATORS, true)
            && Timer::check($condition['value'] ?? null)['error'] === null;
    }

    /**
     * What a condition looks at, for its block: status-<name>, person, text, customer, new, tag, date, reply or other.
     * A lane of OR conditions ("__or") takes the family of its first condition.
     */
    public static function conditionFamily(array $condition)
    {
        if (($condition['type'] ?? '') === '__or') {
            return self::conditionFamily($condition['items'][0] ?? []);
        }
        $type = (string) ($condition['type'] ?? '');
        if ($type === 'status') {
            $name = self::STATUS[(string) ($condition['value'] ?? '')] ?? null;

            return $name ? 'status-'.$name : 'status';
        }
        $map = ['user' => 'person', TicketConditions::ASSIGNED => 'person', TicketConditions::REPLIED => 'reply', 'subject' => 'text', 'body' => 'text',
            'to' => 'text', 'cc' => 'text', 'headers' => 'text', 'customer_email' => 'customer', 'customer_name' => 'customer', 'new_or_reply' => 'new'];
        if (isset($map[$type])) {
            return $map[$type];
        }
        if (Timer::isTimer($type)) {
            return 'date';
        }

        return stripos($type, 'tag') !== false ? 'tag' : 'other';
    }

    /** What an action does, for its block: status, folder, person, mailbox, tag, note, send, delete or other. */
    public static function actionKind(array $action)
    {
        $type = (string) ($action['type'] ?? '');
        if ($type === 'status') {
            return isset(self::STATUS[(string) ($action['value'] ?? '')]) ? 'status-'.self::STATUS[(string) $action['value']] : 'status';
        }
        if ($type === FolderPath::ACTION) {
            return 'folder';
        }
        $map = ['assign' => 'assign', 'move' => 'move', 'note' => 'note', 'delete' => 'delete', 'delete_forever' => 'delete'];
        if (isset($map[$type])) {
            return $map[$type];
        }
        if (in_array($type, self::SENDS, true)) {
            return 'send';
        }

        return stripos($type, 'tag') !== false ? 'tag' : 'other';
    }

    protected static function flat(array $lanes)
    {
        $out = [];
        foreach ($lanes as $lane) {
            if (count($lane) === 1) {
                $out[] = $lane[0];
            } else {
                // a lane of OR conditions: one line "a OR b"
                $out[] = ['type' => '__or', 'items' => $lane];
            }
        }

        return $out;
    }

    /** Same conditions in any order give the same text. */
    protected static function canon(array $value)
    {
        $sort = function ($v) use (&$sort) {
            if (!is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (array_keys($v) !== range(0, count($v) - 1)) {
                ksort($v);
            } else {
                usort($v, function ($a, $b) {
                    return strcmp(json_encode($a), json_encode($b));
                });
            }

            return $v;
        };

        return json_encode($sort($value));
    }
}
