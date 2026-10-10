<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * One rule of a pack + the variables of one mailbox -> the attributes written to the Workflows table.
 *
 * Checks every condition and action against the catalog of the installed Workflows module
 * (Workflow::conditionsConfig() / actionsConfig(), extended by other modules such as Tags): unknown type, operator
 * not offered for that type, value outside a closed list, invalid regex, local reference that does not exist.
 * No Laravel dependency: the catalog and the reference checker are given by the caller.
 */
class RuleCompiler
{
    /** @var array */
    protected $catalog;

    /** @var array config('workflowpacks.workflows') */
    protected $config;

    /** @var array ['automatic' => int, 'manual' => int] */
    protected $types;

    /** @var callable|null function ($kind 'user'|'mailbox', $value, $mailboxId): ?string error */
    protected $refCheck;

    /** @var array */
    protected $limits;

    public function __construct(array $catalog, array $config, array $types, ?callable $refCheck = null, array $limits = [])
    {
        $this->catalog = $catalog;
        $this->config = $config;
        $this->types = $types;
        $this->refCheck = $refCheck;
        $this->limits = $limits + ['regex' => 2000];
    }

    /**
     * @return array ['attributes' => array|null, 'problems' => Problems, 'sensitive' => string[]]
     */
    public function compile(array $rule, VariableResolver $vars, $mailboxId, $path = '')
    {
        $problems = new Problems();
        $path = $path !== '' ? $path : $rule['key'];

        $name = $vars->resolveString($rule['name'], 'text', $path.'.name', $problems);
        $name = trim((string) $name);
        $max = (int) ($this->config['name_max'] ?? 75);
        if ($name === '') {
            $problems->add($path.'.name', 'WP-RULE-13', 'name_empty');
        } elseif (mb_strlen($name) > $max) {
            $problems->add($path.'.name', 'WP-RULE-14', 'name_too_long', ['length' => mb_strlen($name), 'max' => $max]);
        }

        $tree = new Problems();
        $conditions = ConditionTranslator::toNative($rule['conditions'], $path.'.conditions', $tree);
        $problems->merge($tree);
        $nativeConditions = [];
        foreach ((array) $conditions as $g => $group) {
            $nativeGroup = [];
            foreach ($group as $c => $condition) {
                $p = $path.'.conditions['.$g.']['.$c.']';
                $nativeGroup[] = $this->condition($condition, $vars, $mailboxId, $p, $problems);
            }
            $nativeConditions[] = $nativeGroup;
        }

        $nativeActions = [];
        $sensitive = [];
        foreach ($rule['actions'] as $i => $action) {
            $p = $path.'.actions['.$i.']';
            $nativeActions[] = $this->action($action, $vars, $mailboxId, $p, $problems);
            if (isset($this->config['sensitive_actions'][$action['type']])) {
                $sensitive[] = $this->config['sensitive_actions'][$action['type']];
            }
        }

        $attributes = null;
        if ($problems->isEmpty()) {
            $attributes = [
                'name'           => $name,
                'type'           => $this->types[$rule['type']],
                'apply_to_prev'  => (bool) $rule['settings']['apply_to_prev'],
                'max_executions' => (int) $rule['settings']['max_executions'],
                'conditions'     => $nativeConditions,
                // one list, executed in order (Workflow::performActions() walks every list of every group)
                'actions'        => [$nativeActions],
            ];
        }

        return ['attributes' => $attributes, 'problems' => $problems, 'sensitive' => array_values(array_unique($sensitive))];
    }

    protected function condition(array $condition, VariableResolver $vars, $mailboxId, $path, Problems $problems)
    {
        $type = $condition['type'];
        $operator = $condition['operator'];
        $config = $this->catalog['conditions'][$type] ?? null;
        if ($config === null) {
            $problems->add($path, 'WP-CAT-01', 'cat_condition', ['type' => $type, 'types' => implode(', ', array_keys($this->catalog['conditions'] ?? []))]);

            return $condition;
        }
        if (is_array($config['operators']) && $config['operators'] && !in_array($operator, $config['operators'], true)) {
            $problems->add($path, 'WP-CAT-02', 'cat_operator', ['operator' => $operator, 'type' => $type, 'operators' => implode(', ', $config['operators'])]);

            return $condition;
        }
        if (!array_key_exists('value', $condition)) {
            return $condition;
        }
        $value = $vars->resolveValue($condition['value'], $path.'.value', $problems, $operator === 'regex' ? 'regex' : 'text');
        if ($operator === 'regex' && is_string($value) && strpos($value, '{{') === false) {
            $error = PackValidator::regexError($value, $this->limits['regex']);
            if ($error !== null) {
                $problems->add($path.'.value', 'WP-REGEX-01', 'regex_after_vars', ['error' => $error]);
            }
        }
        $this->checkValue('conditions', $type, $config, $value, $mailboxId, $path, $problems);
        $condition['value'] = $value;

        return $condition;
    }

    protected function action(array $action, VariableResolver $vars, $mailboxId, $path, Problems $problems)
    {
        $type = $action['type'];
        $config = $this->catalog['actions'][$type] ?? null;
        if ($config === null) {
            $problems->add($path, 'WP-CAT-03', 'cat_action', ['type' => $type, 'types' => implode(', ', array_keys($this->catalog['actions'] ?? []))]);

            return $action;
        }
        if (isset($action['operator']) && is_array($config['operators']) && $config['operators'] && !in_array($action['operator'], $config['operators'], true)) {
            $problems->add($path, 'WP-CAT-02', 'cat_operator', ['operator' => $action['operator'], 'type' => $type, 'operators' => implode(', ', $config['operators'])]);
        }
        if (!array_key_exists('value', $action)) {
            return $action;
        }
        $value = $action['value'];
        if (in_array($type, $this->config['json_value_actions'] ?? [], true)) {
            // stored as a JSON string by Workflows; written as an object in the pack
            if (!is_array($value) || ($value && ConditionTranslator::isList($value))) {
                $problems->add($path.'.value', 'WP-ACT-01', 'action_json_object', ['type' => $type]);

                return $action;
            }
            $resolved = [];
            foreach ($value as $field => $v) {
                $context = in_array($field, $this->config['html_fields'] ?? ['body'], true) ? 'html' : 'text';
                $resolved[$field] = is_string($v) ? $vars->resolveString($v, $context, $path.'.value.'.$field, $problems) : $vars->resolveValue($v, $path.'.value.'.$field, $problems);
                if (!is_string($resolved[$field]) && !is_array($resolved[$field])) {
                    $resolved[$field] = (string) $resolved[$field];
                }
            }
            $action['value'] = json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $action;
        }
        $value = $vars->resolveValue($value, $path.'.value', $problems);
        $this->checkValue('actions', $type, $config, $value, $mailboxId, $path, $problems);
        $action['value'] = $value;

        return $action;
    }

    protected function checkValue($kind, $type, array $config, $value, $mailboxId, $path, Problems $problems)
    {
        $values = is_array($value) ? $value : [$value];
        if (in_array($type, $this->config['strict_values'][$kind] ?? [], true) && is_array($config['values']) && $config['values']) {
            foreach ($values as $v) {
                if (!is_scalar($v) || !in_array((string) $v, array_map('strval', $config['values']), true)) {
                    $problems->add($path.'.value', 'WP-CAT-04', 'cat_value', ['value' => is_scalar($v) ? $v : json_encode($v), 'type' => $type, 'values' => implode(', ', $config['values'])]);
                }
            }
        }
        $ref = $this->config['local_refs'][$kind][$type] ?? null;
        if ($ref !== null && $this->refCheck) {
            foreach ($values as $v) {
                if (is_scalar($v) && in_array((string) $v, $this->config['user_specials'] ?? [], true) && $ref === 'user') {
                    continue;
                }
                $error = call_user_func($this->refCheck, $ref, $v, $mailboxId);
                if ($error !== null) {
                    $problems->add($path.'.value', 'WP-REF-01', 'raw', ['text' => $error]);
                }
            }
        }
    }
}
