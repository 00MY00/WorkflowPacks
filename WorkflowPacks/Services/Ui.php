<?php

namespace Modules\WorkflowPacks\Services;

use Modules\WorkflowPacks\Services\Pack\ConditionTranslator;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/** Helpers of the views. Every value is escaped here; variables are highlighted after escaping. */
class Ui
{
    /** Escaped value (text or JSON), {{variables}} highlighted. */
    public static function value($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 300).'…';
        }
        $html = e($text);

        return preg_replace('/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/', '<span class="wp-var" title="'.e(__('workflowpacks::messages.variable')).'">{{$1}}</span>', $html);
    }

    /** Groups of a pack condition tree for the schematic view: list of OR lists. */
    public static function groups($tree)
    {
        if (!is_array($tree)) {
            return [];
        }
        if (isset($tree['any'])) {
            return [(array) $tree['any']];
        }
        $groups = [];
        foreach ((array) ($tree['all'] ?? []) as $item) {
            $groups[] = isset($item['any']) ? (array) $item['any'] : [$item];
        }

        return $groups;
    }

    /** Native attributes compiled for a mailbox -> pack-shaped rule, for the schematic view. */
    public static function ruleFromAttributes(array $attributes, array $base)
    {
        $actions = [];
        foreach (Fingerprint::decodeActions($attributes['actions'], (array) Bridge::config('json_value_actions')) as $a) {
            $actions[] = $a;
        }

        return [
            'key'        => $base['key'],
            'name'       => $attributes['name'],
            'type'       => $base['type'],
            'settings'   => $base['settings'],
            'requires'   => $base['requires'] ?? [],
            'conditions' => ConditionTranslator::fromNative($attributes['conditions']),
            'actions'    => $actions,
        ];
    }

    /** Kind of sensitive action (send / forward / delete / move) or ''. */
    public static function sensitive($type)
    {
        return (string) (Bridge::config('sensitive_actions')[$type] ?? '');
    }

    public static function hasVariables($rule)
    {
        $p = new \Modules\WorkflowPacks\Services\Pack\Problems();

        return (bool) VariableResolver::namesIn($rule, '', $p);
    }

    /** Label of a catalog entry when Workflows is available, else the identifier. */
    public static function label($kind, $type, $mailboxId = null)
    {
        try {
            if ($mailboxId) {
                $catalog = Bridge::catalog($mailboxId);
                if (isset($catalog[$kind][$type]['title'])) {
                    return $catalog[$kind][$type]['title'];
                }
            }
        } catch (\Throwable $e) {
        }

        return $type;
    }
}
