<?php

namespace Modules\WorkflowPacks\Services\Flow;

use Modules\WorkflowPacks\Services\Graph\GraphBuilder;
use Modules\WorkflowPacks\Services\History;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * "Simulate" of the diagram: a fake e-mail through the rules of one mailbox (Simulator, in memory), with the texts
 * of the blocks. Only for a mailbox whose workflows the user may manage. Nothing of the fake e-mail is kept: the
 * history only records that a simulation ran and its counts.
 */
class SimulationService
{
    /** @return array ['ok' => bool, 'error' => string, ...Simulator::run() with texts, 'paths' => FlowBuilder::paths()] */
    public function run($user, array $input)
    {
        $mailboxId = (int) ($input['mailbox_id'] ?? 0);
        $mailbox = $mailboxId ? \App\Mailbox::find($mailboxId) : null;
        if (!$mailbox || !Bridge::canEdit($user, $mailboxId)) {
            return ['ok' => false, 'error' => __('workflowpacks::messages.sim_denied')];
        }
        $input['to'] = trim((string) ($input['to'] ?? '')) !== '' ? $input['to'] : $mailbox->email;
        $mail = Simulator::mail($input);

        $jsonActions = (array) Bridge::config('json_value_actions');
        $rules = [];
        $manual = [];
        foreach (Bridge::query()->where('mailbox_id', $mailboxId)->orderBy('sort_order')->get() as $workflow) {
            $attributes = Bridge::attributesOf($workflow);
            // manual rules are started by an agent from a ticket, never by an e-mail
            if (Bridge::typeName($attributes['type']) === 'manual') {
                $manual[] = ['workflow_id' => (int) $workflow->id, 'name' => (string) $workflow->name, 'active' => (bool) $workflow->active, 'reason' => 'sim_manual', 'conditions' => []];
                continue;
            }
            $rules[] = GraphBuilder::flowRule($workflow, $attributes, $jsonActions);
        }
        $result = (new Simulator())->run($rules, $mail, !array_key_exists('include_inactive', $input) || !empty($input['include_inactive']));
        $result['skipped'] = array_merge($result['skipped'], $manual);

        $view = $this->texts($result, $mailboxId);
        $uncertain = count(array_filter($result['skipped'], function ($s) {
            return $s['reason'] === 'sim_uncertain';
        }));
        History::add('simulate', $result['processed'] ? 'ok' : 'skipped', ['user_id' => $user->id, 'mailbox_id' => $mailboxId,
            'subject' => __('workflowpacks::messages.sim_history_subject'),
            'message' => json_encode(['counts' => ['applied' => count($result['events']), 'uncertain' => $uncertain]])]);

        return ['ok' => true, 'error' => ''] + $view;
    }

    /** Texts of conditions, actions and states, as the side panel shows them. */
    protected function texts(array $result, $mailboxId)
    {
        $labels = GraphBuilder::labels($mailboxId);
        $t = function ($key, array $params = []) {
            return __('workflowpacks::messages.'.$key, $params);
        };
        $conditions = function (array $rows) use ($labels, $t) {
            return array_map(function ($row) use ($labels, $t) {
                return ['text' => $labels->condition($row), 'result' => $row['result'], 'note' => $row['note'] !== '' ? $t($row['note']) : ''];
            }, $rows);
        };
        foreach ($result['events'] as $i => $event) {
            $result['events'][$i]['conditions'] = $conditions($event['conditions']);
            $result['events'][$i]['when'] = $this->when($event['t']);
            foreach ($event['actions'] as $j => $action) {
                // the action itself stays here: only its texts are sent
                $result['events'][$i]['actions'][$j] += $labels->action($action['action']);
                unset($result['events'][$i]['actions'][$j]['action']);
                $result['events'][$i]['actions'][$j]['changes'] = $this->changes($action['changes'], $labels, $t);
            }
            $result['events'][$i]['state'] = $this->state($event['state'], $labels, $t);
        }
        foreach ($result['skipped'] as $i => $skipped) {
            $result['skipped'][$i]['conditions'] = $conditions($skipped['conditions']);
            $result['skipped'][$i]['reason_text'] = $t($skipped['reason']);
        }
        $result['final'] = $this->state($result['final'], $labels, $t);
        $result['horizon'] = $this->when((int) end($result['checks']));

        return $result;
    }

    /** "At the arrival", "+1 h", "+2 d 1 min" */
    protected function when($seconds)
    {
        if ($seconds <= 0) {
            return __('workflowpacks::messages.sim_at_arrival');
        }
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'i' => 60] as $unit => $size) {
            if ($seconds >= $size) {
                $parts[] = __('workflowpacks::messages.sim_unit_'.$unit, ['count' => intdiv($seconds, $size)]);
                $seconds %= $size;
            }
        }

        return '+'.implode(' ', $parts);
    }

    protected function state(array $state, FlowLabels $labels, callable $t)
    {
        return [
            'status'  => $this->field('status', $state['status'], $labels, $t),
            'user'    => $this->field('user_id', $state['user_id'], $labels, $t),
            'mailbox' => $this->field('mailbox_id', $state['mailbox_id'], $labels, $t),
            'folder'  => $state['folder'],
            'tags'    => implode(', ', $state['tags']),
            'notes'   => count($state['notes']),
            'sent'    => array_map(function ($s) use ($labels) {
                return $labels->action(['type' => $s['type'], 'value' => null])['label'].($s['to'] !== '' ? ' → '.$s['to'] : '');
            }, $state['sent']),
            'deleted' => $state['deleted'] !== '' ? $t('dest_'.($state['deleted'] === 'forever' ? 'delete_forever' : 'delete')) : '',
        ];
    }

    /** field => [label, before, after] */
    protected function changes(array $changes, FlowLabels $labels, callable $t)
    {
        $out = [];
        foreach ($changes as $field => $pair) {
            if (in_array($field, ['notes', 'sent'], true)) {
                $pair = [count((array) $pair[0]), count((array) $pair[1])];
            }
            $out[] = ['field' => $t('sim_field_'.$field), 'before' => $this->field($field, $pair[0], $labels, $t), 'after' => $this->field($field, $pair[1], $labels, $t)];
        }

        return $out;
    }

    protected function field($field, $value, FlowLabels $labels, callable $t)
    {
        switch ($field) {
            case 'status':
                $name = FlowBuilder::STATUS[(string) $value] ?? null;

                return $name ? $t('dest_'.$name) : (string) $value;
            case 'user_id':
                if ((int) $value === 0) {
                    return $t('dest_unassigned');
                }

                return (int) $value === -10 ? $t('sim_someone') : $labels->action(['type' => 'assign', 'value' => $value])['label'];
            case 'mailbox_id':
                return $labels->action(['type' => 'move', 'value' => $value])['label'];
            case 'tags':
                return implode(', ', (array) $value);
            case 'deleted':
                return $value === '' ? '—' : $t('dest_'.($value === 'forever' ? 'delete_forever' : 'delete'));
        }

        return is_scalar($value) ? ((string) $value !== '' ? (string) $value : '—') : json_encode($value);
    }
}
