<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * Pack conditions ("all" = AND, "any" = OR) <-> conditions of the Workflows module.
 *
 * Workflows stores a list of AND groups, each group being a list of OR conditions:
 *   [[c1, c2], [c3]]  means  (c1 OR c2) AND c3
 * (Workflow::checkConditions(): the outer loop needs every group, the inner loop stops at the first true condition).
 *
 * Accepted pack shapes, exactly the ones Workflows can represent:
 *   {"all": [c1, {"any": [c2, c3]}, c4]}   -> [[c1], [c2, c3], [c4]]
 *   {"any": [c1, c2]}                      -> [[c1, c2]]
 * Anything else (AND inside an OR, nested groups, empty groups) is refused with an explanation: the logic is never
 * changed silently.
 */
class ConditionTranslator
{
    const CONDITION_KEYS = ['type', 'operator', 'value'];

    /**
     * @return array|null native conditions, or null when the tree is refused (problems added)
     */
    public static function toNative($tree, $path, Problems $problems, array $limits = [])
    {
        $maxGroups = (int) ($limits['groups'] ?? 20);
        $maxConditions = (int) ($limits['conditions'] ?? 50);

        if ($tree === null || $tree === []) {
            return [];
        }
        if (!is_array($tree) || self::isList($tree) || count($tree) !== 1 || !(isset($tree['all']) || isset($tree['any']))) {
            $problems->add($path, 'WP-COND-01', 'les conditions doivent être un objet avec une seule clé « all » (ET) ou « any » (OU).');

            return null;
        }
        $native = [];
        $ok = true;

        if (isset($tree['any'])) {
            $group = self::orGroup($tree['any'], $path.'.any', $problems, $maxConditions);
            if ($group === null) {
                return null;
            }

            return [$group];
        }

        $items = $tree['all'];
        if (!is_array($items) || !self::isList($items) || !$items) {
            $problems->add($path.'.all', 'WP-COND-02', '« all » doit être une liste non vide.');

            return null;
        }
        if (count($items) > $maxGroups) {
            $problems->add($path.'.all', 'WP-COND-03', 'trop de groupes ET ('.count($items).', maximum '.$maxGroups.').');

            return null;
        }
        foreach ($items as $i => $item) {
            $p = $path.'.all['.$i.']';
            if (is_array($item) && isset($item['any']) && count($item) === 1) {
                $group = self::orGroup($item['any'], $p.'.any', $problems, $maxConditions);
                if ($group === null) {
                    $ok = false;
                } else {
                    $native[] = $group;
                }
            } elseif (is_array($item) && isset($item['all'])) {
                $problems->add($p, 'WP-COND-04', 'un « all » dans un « all » n\'est pas accepté : placez ces conditions directement dans la liste « all » principale.');
                $ok = false;
            } else {
                $condition = self::condition($item, $p, $problems);
                if ($condition === null) {
                    $ok = false;
                } else {
                    $native[] = [$condition];
                }
            }
        }

        return $ok ? $native : null;
    }

    protected static function orGroup($items, $path, Problems $problems, $max)
    {
        if (!is_array($items) || !self::isList($items) || !$items) {
            $problems->add($path, 'WP-COND-02', '« any » doit être une liste non vide.');

            return null;
        }
        if (count($items) > $max) {
            $problems->add($path, 'WP-COND-03', 'trop de conditions dans ce groupe OU ('.count($items).', maximum '.$max.').');

            return null;
        }
        $group = [];
        foreach ($items as $i => $item) {
            $p = $path.'['.$i.']';
            if (is_array($item) && (isset($item['all']) || isset($item['any']))) {
                $problems->add($p, 'WP-COND-05', 'Workflows ne sait pas évaluer un groupe à l\'intérieur d\'un « any » (OU) : '
                    .'seule la forme « ET de groupes OU » est représentable. Réécrivez la règle (ou séparez-la en plusieurs règles).');

                return null;
            }
            $condition = self::condition($item, $p, $problems);
            if ($condition === null) {
                return null;
            }
            $group[] = $condition;
        }

        return $group;
    }

    protected static function condition($item, $path, Problems $problems)
    {
        if (!is_array($item) || self::isList($item)) {
            $problems->add($path, 'WP-COND-06', 'une condition doit être un objet {"type", "operator", "value"}.');

            return null;
        }
        $extra = array_diff(array_keys($item), self::CONDITION_KEYS);
        if ($extra) {
            $problems->add($path, 'WP-COND-07', 'clé(s) non prise(s) en charge : '.implode(', ', $extra).'.');

            return null;
        }
        if (!isset($item['type']) || !is_string($item['type']) || $item['type'] === '') {
            $problems->add($path, 'WP-COND-08', '« type » manquant.');

            return null;
        }
        if (!isset($item['operator']) || !is_string($item['operator']) || $item['operator'] === '') {
            $problems->add($path, 'WP-COND-09', '« operator » manquant (Workflows ignore une condition sans opérateur).');

            return null;
        }
        $condition = ['type' => $item['type'], 'operator' => $item['operator']];
        if (array_key_exists('value', $item)) {
            $condition['value'] = $item['value'];
        }

        return $condition;
    }

    /** Native conditions -> pack tree (used by the export). */
    public static function fromNative($native)
    {
        if (!is_array($native) || !$native) {
            return null;
        }
        $groups = [];
        foreach ($native as $group) {
            if (!is_array($group)) {
                continue;
            }
            $conditions = [];
            foreach ($group as $condition) {
                if (is_array($condition) && !empty($condition['type'])) {
                    $c = ['type' => (string) $condition['type'], 'operator' => (string) ($condition['operator'] ?? '')];
                    if (array_key_exists('value', $condition)) {
                        $c['value'] = $condition['value'];
                    }
                    $conditions[] = $c;
                }
            }
            if ($conditions) {
                $groups[] = $conditions;
            }
        }
        if (!$groups) {
            return null;
        }
        if (count($groups) === 1 && count($groups[0]) > 1) {
            return ['any' => $groups[0]];
        }

        return ['all' => array_map(function ($g) {
            return count($g) === 1 ? $g[0] : ['any' => $g];
        }, $groups)];
    }

    public static function isList(array $array)
    {
        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }
}
