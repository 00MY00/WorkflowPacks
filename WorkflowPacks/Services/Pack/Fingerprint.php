<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * Fingerprint of the part of a workflow managed by a pack: name, type, max_executions, conditions and actions.
 * The active state, "apply to previous" and the order are left to the administrator and are not part of it.
 *
 * Values are compared in a canonical form (keys sorted, scalars as strings, JSON action values decoded), so that
 * "1" saved by the Workflows form and 1 written by the import are the same value.
 */
class Fingerprint
{
    const FIELDS = ['name', 'type', 'max_executions', 'conditions', 'actions'];

    public static function of(array $attributes, array $jsonActions = [])
    {
        return hash('sha256', json_encode(self::canonical($attributes, $jsonActions)));
    }

    public static function canonical(array $attributes, array $jsonActions = [])
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $attributes[$field] ?? null;
            if ($field === 'actions') {
                $value = self::decodeActions($value, $jsonActions);
            }
            $out[$field] = self::normalize($value);
        }

        return $out;
    }

    /** Actions as one flat list, JSON values decoded (Workflows executes every list in order). */
    public static function decodeActions($actions, array $jsonActions)
    {
        if (is_string($actions)) {
            $actions = json_decode($actions, true);
        }
        $flat = [];
        foreach ((array) $actions as $group) {
            foreach ((array) $group as $action) {
                if (is_array($action) && isset($action['type']) && in_array($action['type'], $jsonActions, true)
                    && isset($action['value']) && is_string($action['value'])) {
                    $decoded = json_decode($action['value'], true);
                    if (is_array($decoded)) {
                        $action['value'] = $decoded;
                    }
                }
                $flat[] = $action;
            }
        }

        return $flat;
    }

    protected static function normalize($value)
    {
        if (is_array($value)) {
            $list = ConditionTranslator::isList($value);
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize($v);
            }
            if (!$list) {
                ksort($out, SORT_STRING);
            }

            return $out;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Fields that differ between two versions, with both sides as readable JSON.
     *
     * @return array field => ['before' => string, 'after' => string]
     */
    public static function diff(array $before, array $after, array $jsonActions = [])
    {
        $a = self::canonical($before, $jsonActions);
        $b = self::canonical($after, $jsonActions);
        $diff = [];
        foreach (self::FIELDS as $field) {
            if ($a[$field] !== $b[$field]) {
                $diff[$field] = [
                    'before' => self::pretty($a[$field]),
                    'after'  => self::pretty($b[$field]),
                ];
            }
        }

        return $diff;
    }

    protected static function pretty($value)
    {
        return is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;
    }
}
