<?php

namespace Modules\WorkflowPacks\Services\Graph;

use Modules\WorkflowPacks\Services\Pack\ConditionTranslator;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * A stored Workflows rule -> a rule in the pack format (conditions all/any, actions with decoded JSON values).
 * Shared by the export and the editor of the diagram, so that both read a rule the same way.
 */
class RuleCodec
{
    public static function fromWorkflow($workflow)
    {
        $jsonActions = (array) Bridge::config('json_value_actions');
        $attrs = Bridge::attributesOf($workflow);
        $actions = [];
        foreach (Fingerprint::decodeActions($attrs['actions'], []) as $action) {
            if (!is_array($action) || empty($action['type'])) {
                continue;
            }
            $a = ['type' => (string) $action['type']];
            if (isset($action['operator']) && $action['operator'] !== '') {
                $a['operator'] = $action['operator'];
            }
            if (array_key_exists('value', $action)) {
                $value = $action['value'];
                if (in_array($a['type'], $jsonActions, true) && is_string($value)) {
                    $decoded = json_decode($value, true);
                    $value = is_array($decoded) ? $decoded : $value;
                }
                $a['value'] = $value;
            }
            $actions[] = $a;
        }

        $name = (string) $workflow->name;
        $rule = [
            'key'      => self::slug($name),
            'name'     => $name,
            'type'     => Bridge::typeName($attrs['type']),
            'settings' => ['apply_to_prev' => (bool) $attrs['apply_to_prev'], 'max_executions' => max(1, (int) $attrs['max_executions'])],
        ];
        $conditions = ConditionTranslator::fromNative($attrs['conditions']);
        if ($conditions !== null) {
            $rule['conditions'] = $conditions;
        }
        $rule['actions'] = $actions;

        return $rule;
    }

    public static function slug($text)
    {
        $text = \Illuminate\Support\Str::slug((string) $text);

        return $text !== '' ? substr($text, 0, 90) : 'regle';
    }
}
