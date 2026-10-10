<?php

namespace Modules\WorkflowPacks\Services\Plan;

use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Pack\RuleCompiler;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * What an import would do, for every rule of the pack and every selected mailbox, without writing anything.
 *
 * Identity of a rule: pack.key + workflow.key + mailbox (table workflowpacks_links). Status of each pair:
 *   create         no link, name free in the mailbox
 *   conflict_name  no link, a rule that does not belong to the pack already has this name (never modified)
 *   unchanged      linked rule identical to what the pack gives
 *   update         linked rule not modified by hand since the last import, the pack changed it
 *   conflict       linked rule modified by hand since the last import (choice: keep local / replace / skip)
 *   missing        linked rule deleted from Workflows since the last import (choice: skip / recreate)
 *   blocked        dependency missing, unknown type, invalid value, rejected by Workflows' validation…
 *   denied         the user may not manage the workflows of this mailbox
 */
class Planner
{
    const CHOICES = [
        'create'        => ['create', 'skip'],
        'conflict_name' => ['skip', 'create_renamed'],
        'unchanged'     => ['unchanged'],
        'update'        => ['update', 'skip'],
        'conflict'      => ['keep_local', 'replace', 'skip'],
        'missing'       => ['skip', 'recreate'],
        'blocked'       => ['skip'],
        'denied'        => ['skip'],
    ];

    /**
     * @param array $pack        pack normalized by PackValidator
     * @param int[] $mailboxIds  target mailboxes
     * @param array $paramValues key => raw value entered in the form
     * @param \App\User $user
     */
    public function plan(array $pack, array $mailboxIds, array $paramValues, $user)
    {
        $problems = new Problems();
        $config = Bridge::config();
        $limits = (array) config('workflowpacks.limits');

        // ------------------------------------------------------------------------------------ mailboxes
        $mailboxIds = array_values(array_unique(array_map('intval', $mailboxIds)));
        if (!$mailboxIds) {
            $problems->add('mailboxes', 'WP-MB-01', 'mb_none');
        }
        if (count($mailboxIds) > (int) ($limits['mailboxes'] ?? 100)) {
            $problems->add('mailboxes', 'WP-MB-02', 'mb_too_many');
        }
        $mailboxes = [];
        foreach ($mailboxIds as $id) {
            $mailbox = \App\Mailbox::find($id);
            if (!$mailbox) {
                $problems->add('mailboxes', 'WP-MB-03', 'mb_not_found', ['id' => $id]);
                continue;
            }
            $mailboxes[$id] = [
                'id'      => $id,
                'name'    => (string) $mailbox->name,
                'email'   => (string) $mailbox->email,
                'allowed' => Bridge::canEdit($user, $id),
            ];
        }

        // ----------------------------------------------------------------------------------- parameters
        $params = $this->checkParams($pack['params'], $paramValues, $problems);

        // ------------------------------------------------------------------------------------------ rules
        $types = Bridge::types();
        $jsonActions = (array) $config['json_value_actions'];
        $refCheck = function ($kind, $value, $mailboxId) {
            return self::referenceError($kind, $value, $mailboxId);
        };
        $rules = [];
        foreach ($pack['workflows'] as $index => $rule) {
            $requires = array_values(array_unique(array_merge($pack['requires'], $rule['requires'])));
            $missingModules = array_values(array_filter($requires, function ($alias) {
                return !Bridge::moduleActive($alias);
            }));
            $items = [];
            foreach ($mailboxes as $id => $mailbox) {
                $item = [
                    'rule_key'   => $rule['key'],
                    'mailbox_id' => $id,
                    'status'     => 'blocked',
                    'reasons'    => [],
                    'attributes' => null,
                    'fingerprint' => '',
                    'current'    => null,
                    'link_id'    => null,
                    'diff'       => [],
                    'sensitive'  => [],
                ];
                if (!$mailbox['allowed']) {
                    $item['status'] = 'denied';
                    $item['reasons'][] = Problems::t('denied');
                    $items[$id] = $this->finish($item);
                    continue;
                }
                if ($missingModules) {
                    $item['reasons'][] = Problems::t('modules_missing', ['modules' => implode(', ', $missingModules)]);
                    $items[$id] = $this->finish($item);
                    continue;
                }
                if (!$problems->isEmpty()) {
                    $item['reasons'][] = Problems::t('fix_general_first');
                    $items[$id] = $this->finish($item);
                    continue;
                }
                $vars = VariableResolver::forMailbox($id, $mailbox['email'], $mailbox['name'], $pack['params'], $params);
                $compiler = new RuleCompiler(Bridge::catalog($id), $config, $types, $refCheck, $limits);
                $compiled = $compiler->compile($rule, $vars, $id, 'workflows['.$index.']');
                $item['sensitive'] = $compiled['sensitive'];
                if ($compiled['attributes'] === null) {
                    $item['reasons'] = array_merge($item['reasons'], $compiled['problems']->lines());
                    $items[$id] = $this->finish($item);
                    continue;
                }
                $attributes = $compiled['attributes'];
                $item['attributes'] = $attributes;
                $item['fingerprint'] = Fingerprint::of($attributes, $jsonActions);

                $official = Bridge::officialErrors($attributes, $id);
                if ($official) {
                    $item['reasons'][] = Problems::t('official_rejected', ['fields' => implode(', ', array_keys($official))]);
                    $items[$id] = $this->finish($item);
                    continue;
                }

                $items[$id] = $this->finish($this->state($item, $pack['key'], $rule, $attributes, $jsonActions));
            }
            $rules[$rule['key']] = ['index' => $index, 'rule' => $rule, 'requires' => $requires, 'missing_modules' => $missingModules, 'items' => $items];
        }

        $plan = [
            'pack'      => $pack,
            'mailboxes' => $mailboxes,
            'params'    => $params,
            'problems'  => $problems,
            'rules'     => $rules,
            'counts'    => $this->counts($rules),
        ];
        $plan['token'] = $this->token($plan);

        return $plan;
    }

    /** Status of a compiled rule in a mailbox (links, manual changes, name collisions). */
    protected function state(array $item, $packKey, array $rule, array $attributes, array $jsonActions)
    {
        $id = $item['mailbox_id'];
        $link = Link::where('pack_key', $packKey)->where('workflow_key', $rule['key'])->where('mailbox_id', $id)->first();
        if ($link) {
            $item['link_id'] = $link->id;
            $workflow = $link->workflow_id ? Bridge::query()->find($link->workflow_id) : null;
            if (!$workflow || (int) $workflow->mailbox_id !== (int) $id) {
                $item['status'] = 'missing';
                $item['reasons'][] = Problems::t('link_missing');
                $item = $this->nameCheck($item, $attributes['name'], null, 'create');

                return $item;
            }
            $current = Bridge::attributesOf($workflow);
            $currentFp = Fingerprint::of($current, $jsonActions);
            $item['current'] = [
                'id'            => (int) $workflow->id,
                'name'          => (string) $workflow->name,
                'active'        => (bool) $workflow->active,
                'apply_to_prev' => (bool) $workflow->apply_to_prev,
                'fingerprint'   => $currentFp,
            ];
            if ($currentFp === $item['fingerprint']) {
                $item['status'] = 'unchanged';
            } elseif ($currentFp === $link->fingerprint) {
                $item['status'] = 'update';
                $item['diff'] = Fingerprint::diff($current, $attributes, $jsonActions);
                $item = $this->nameCheck($item, $attributes['name'], $workflow->id, 'update');
            } else {
                $item['status'] = 'conflict';
                $item['reasons'][] = Problems::t('manual_change');
                $item['diff'] = Fingerprint::diff($current, $attributes, $jsonActions);
                $item = $this->nameCheck($item, $attributes['name'], $workflow->id, 'conflict');
            }

            return $item;
        }

        $item['status'] = 'create';

        return $this->nameCheck($item, $attributes['name'], null, 'create');
    }

    /** Workflows refuses two rules with the same name in a mailbox (WorkflowsController, Rule::unique). */
    protected function nameCheck(array $item, $name, $exceptId, $mode)
    {
        $query = Bridge::query()->where('mailbox_id', $item['mailbox_id'])->where('name', $name);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $other = $query->first();
        if (!$other) {
            return $item;
        }
        if ($mode === 'create' && $item['status'] === 'create') {
            $item['status'] = 'conflict_name';
            $item['reasons'][] = Problems::t('name_foreign', ['id' => $other->id]);
        } else {
            $item['reasons'][] = Problems::t('name_taken', ['id' => $other->id]);
            $item['name_taken'] = true;
            if ($item['status'] === 'update') {
                $item['status'] = 'blocked';
            }
        }

        return $item;
    }

    protected function finish(array $item)
    {
        $choices = self::CHOICES[$item['status']];
        if (!empty($item['name_taken'])) {
            $choices = array_values(array_diff($choices, ['replace', 'recreate']));
        }
        $item['choices'] = $choices;
        $item['default'] = $choices[0];

        return $item;
    }

    protected function counts(array $rules)
    {
        $counts = array_fill_keys(array_keys(self::CHOICES), 0);
        foreach ($rules as $rule) {
            foreach ($rule['items'] as $item) {
                $counts[$item['status']]++;
            }
        }

        return $counts;
    }

    /** Proof that the state seen in the preview did not change before the confirmation. */
    protected function token(array $plan)
    {
        $state = [
            'pack'      => hash('sha256', json_encode($plan['pack'])),
            'mailboxes' => array_keys($plan['mailboxes']),
            'params'    => $plan['params'],
            'items'     => [],
        ];
        foreach ($plan['rules'] as $key => $rule) {
            foreach ($rule['items'] as $id => $item) {
                $state['items'][] = [$key, $id, $item['status'], $item['fingerprint'], $item['current']['fingerprint'] ?? '', $item['current']['id'] ?? 0];
            }
        }

        return hash_hmac('sha256', json_encode($state), (string) config('app.key'));
    }

    /** Values entered for the pack parameters, checked (types, required, local references). */
    protected function checkParams(array $declared, array $input, Problems $problems)
    {
        $values = [];
        foreach ($declared as $param) {
            $key = $param['key'];
            $raw = $input[$key] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            if ($value === '' || $value === null) {
                $value = $param['default'];
            }
            if ($value === null || $value === '') {
                if ($param['required']) {
                    $problems->add('params.'.$key, 'WP-PARAM-10', 'param_required', ['label' => $param['label']]);
                }
                $values[$key] = null;
                continue;
            }
            $error = PackValidator::paramError($param['type'], $value);
            if ($error === null && in_array($param['type'], ['user', 'mailbox'], true)) {
                $error = self::referenceError($param['type'], $value, null);
            }
            if ($error !== null) {
                $problems->add('params.'.$key, 'WP-PARAM-11', 'param_invalid', ['label' => $param['label'], 'error' => $error]);
                $values[$key] = null;
                continue;
            }
            $values[$key] = in_array($param['type'], ['integer', 'user', 'mailbox'], true) ? (int) $value : (string) $value;
        }

        return $values;
    }

    /** Error when a value does not point to an existing user / mailbox of this instance, or null. */
    public static function referenceError($kind, $value, $mailboxId)
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) {
            return Problems::t($kind === 'user' ? 'ref_numeric_user' : 'ref_numeric_mailbox', ['value' => is_scalar($value) ? $value : '[…]']);
        }
        if ($kind === 'mailbox') {
            return \App\Mailbox::find((int) $value) ? null : Problems::t('ref_mailbox_missing', ['id' => $value]);
        }
        $user = \App\User::find((int) $value);
        if (!$user || (int) $user->status === \App\User::STATUS_DELETED) {
            return Problems::t('ref_user_missing', ['id' => $value]);
        }
        if ($mailboxId && !$user->isAdmin() && !$user->hasAccessToMailbox((int) $mailboxId)) {
            return Problems::t('ref_user_access', ['id' => $value, 'mailbox' => $mailboxId]);
        }

        return null;
    }
}
