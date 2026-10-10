<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * Mailbox selector of a pack v2: the portable intention "which mailboxes", evaluated LOCALLY at import on the
 * mailboxes the user may manage (never a list of addresses frozen in the file).
 *
 *   {"select": "all"}
 *   {"select": "match", "combine": "any"|"all", "rules": [{"field": "email"|"name", "operator": "contains", "value": "@example.com"}]}
 *
 * Comparison is case-insensitive and literal (no regex, no variable). No Laravel dependency.
 */
class MailboxSelector
{
    const FIELDS = ['email', 'name'];
    const OPERATORS = ['contains', 'not_contains', 'equal', 'not_equal', 'starts', 'ends'];
    const MAX_RULES = 20;

    /** Normalized selector, or null when refused (problems added). null input = no selector (pack v1 behaviour). */
    public static function validate($selector, $path, Problems $problems)
    {
        if ($selector === null) {
            return null;
        }
        if (!is_array($selector) || ConditionTranslator::isList($selector)) {
            $problems->add($path, 'WP-SEL-01', 'sel_object');

            return null;
        }
        $extra = array_diff(array_keys($selector), ['select', 'combine', 'rules']);
        if ($extra) {
            $problems->add($path, 'WP-SEL-02', 'cond_unknown_keys', ['keys' => implode(', ', $extra)]);

            return null;
        }
        $select = $selector['select'] ?? null;
        if ($select === 'all') {
            if (isset($selector['rules']) || isset($selector['combine'])) {
                $problems->add($path, 'WP-SEL-03', 'sel_all_no_rules');

                return null;
            }

            return ['select' => 'all'];
        }
        if ($select !== 'match') {
            $problems->add($path.'.select', 'WP-SEL-04', 'sel_select');

            return null;
        }
        $combine = $selector['combine'] ?? 'any';
        if (!in_array($combine, ['any', 'all'], true)) {
            $problems->add($path.'.combine', 'WP-SEL-05', 'sel_combine');

            return null;
        }
        $rules = $selector['rules'] ?? null;
        if (!is_array($rules) || !ConditionTranslator::isList($rules) || !$rules || count($rules) > self::MAX_RULES) {
            $problems->add($path.'.rules', 'WP-SEL-06', 'sel_rules', ['max' => self::MAX_RULES]);

            return null;
        }
        $out = [];
        foreach ($rules as $i => $rule) {
            $p = $path.'.rules['.$i.']';
            if (!is_array($rule) || array_diff(array_keys($rule), ['field', 'operator', 'value'])) {
                $problems->add($p, 'WP-SEL-07', 'sel_rule');

                return null;
            }
            if (!in_array($rule['field'] ?? null, self::FIELDS, true)) {
                $problems->add($p.'.field', 'WP-SEL-08', 'sel_field', ['fields' => implode(', ', self::FIELDS)]);

                return null;
            }
            if (!in_array($rule['operator'] ?? null, self::OPERATORS, true)) {
                $problems->add($p.'.operator', 'WP-SEL-09', 'sel_operator', ['operators' => implode(', ', self::OPERATORS)]);

                return null;
            }
            $value = $rule['value'] ?? null;
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 191) {
                $problems->add($p.'.value', 'WP-SEL-10', 'sel_value');

                return null;
            }
            if (strpos($value, '{{') !== false) {
                $problems->add($p.'.value', 'WP-SEL-11', 'sel_no_variable');

                return null;
            }
            $out[] = ['field' => $rule['field'], 'operator' => $rule['operator'], 'value' => $value];
        }

        return ['select' => 'match', 'combine' => $combine, 'rules' => $out];
    }

    /** Does a mailbox (address + name) match the selector? null selector = every mailbox. */
    public static function matches($selector, $email, $name)
    {
        if ($selector === null || ($selector['select'] ?? '') === 'all') {
            return true;
        }
        $results = [];
        foreach ($selector['rules'] as $rule) {
            $text = mb_strtolower($rule['field'] === 'email' ? (string) $email : (string) $name);
            $value = mb_strtolower($rule['value']);
            switch ($rule['operator']) {
                case 'contains':
                    $ok = mb_strpos($text, $value) !== false;
                    break;
                case 'not_contains':
                    $ok = mb_strpos($text, $value) === false;
                    break;
                case 'equal':
                    $ok = $text === $value;
                    break;
                case 'not_equal':
                    $ok = $text !== $value;
                    break;
                case 'starts':
                    $ok = mb_substr($text, 0, mb_strlen($value)) === $value;
                    break;
                default: // ends
                    $ok = mb_strlen($value) <= mb_strlen($text) && mb_substr($text, -mb_strlen($value)) === $value;
            }
            $results[] = $ok;
        }

        return $selector['combine'] === 'all' ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    /** Readable sentence, e.g. « Boîtes dont l'adresse contient "@example.com" ». */
    public static function describe($selector)
    {
        if ($selector === null) {
            return Problems::t('sel_none');
        }
        if ($selector['select'] === 'all') {
            return Problems::t('sel_desc_all');
        }
        $parts = array_map(function ($rule) {
            return Problems::t('sel_desc_rule', [
                'field'    => Problems::t('sel_field_'.$rule['field']),
                'operator' => Problems::t('sel_op_'.$rule['operator']),
                'value'    => $rule['value'],
            ]);
        }, $selector['rules']);

        return Problems::t('sel_desc_match', ['rules' => implode(' '.Problems::t($selector['combine'] === 'all' ? 'sel_and' : 'sel_or').' ', $parts)]);
    }
}
