<?php

namespace Modules\WorkflowPacks\Services\Flow;

use Modules\WorkflowPacks\Services\MailTags;
use Modules\WorkflowPacks\Services\Pack\Timer;

/**
 * "Test this rule": a fake e-mail made to meet the conditions of a rule — no Laravel dependency (tests/unit).
 *
 * Text conditions get their value (equal), the value inside a short text (contains), at the start / end; a regular
 * expression gets an example built from its first alternatives, then checked with preg_match. "Assigned", "replied",
 * the e-mail tags and the status set the matching options. In a lane of OR conditions, the first one that can be met
 * is used. Every condition is then checked again with the simulation's own evaluator: what still cannot be met (a
 * condition of another module, "reply", a given person…) is listed, never hidden. Delays are not about the e-mail:
 * the simulation waits for them.
 */
class SampleMail
{
    const DEFAULTS = ['subject' => 'Test', 'body' => 'Test', 'customer_email' => 'client@example.test', 'customer_name' => 'Client', 'to' => '', 'cc' => ''];

    /**
     * @param array  $lanes Workflows' conditions of the rule
     * @param string $to    address of the mailbox
     * @return array ['input' => fields of Simulator::mail() + status, 'unmet' => [conditions that cannot be met]]
     */
    public static function forRule(array $lanes, $to = '')
    {
        $input = self::DEFAULTS + ['tags' => [], 'assigned' => false, 'replied' => false, 'status' => 1];
        $input['to'] = (string) $to;
        $parts = [];       // field => pieces of text (AND: all of them)
        $equal = [];       // field => exact text
        $unmet = [];
        foreach ($lanes as $lane) {
            $lane = array_values(array_filter((array) $lane, 'is_array'));
            $done = false;
            foreach ($lane as $condition) {
                if (Timer::isTimer($condition['type'] ?? '')) {
                    $done = true;   // the simulation waits for the delay
                    break;
                }
                $try = [$input, $parts, $equal];
                if (self::meet($condition, $try[0], $try[1], $try[2])) {
                    list($input, $parts, $equal) = $try;
                    $done = true;
                    break;
                }
            }
            if (!$done && $lane) {
                $unmet[] = $lane;
            }
        }
        foreach (array_keys(ConditionEvaluator::TEXT) as $field) {
            if (isset($equal[$field])) {
                $input[$field] = $equal[$field];
            } elseif (!empty($parts[$field])) {
                $input[$field] = implode(' ', $parts[$field]);
            }
        }

        // checked again as the simulation will see it: only the lanes really met count
        $mail = Simulator::mail($input);
        $state = Simulator::state($mail);
        $out = [];
        foreach ($lanes as $lane) {
            $lane = array_values(array_filter((array) $lane, 'is_array'));
            $ok = !$lane;
            foreach ($lane as $condition) {
                if (Timer::isTimer($condition['type'] ?? '') || ConditionEvaluator::evaluate($condition, $mail, $state, 0, true)['result'] === true) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $out[] = $lane;
            }
        }

        return ['input' => $input, 'unmet' => $out];
    }

    /** Sets what one condition needs; false when it cannot be met by the e-mail. */
    protected static function meet(array $c, array &$input, array &$parts, array &$equal)
    {
        $type = (string) ($c['type'] ?? '');
        $op = (string) ($c['operator'] ?? '');
        $value = is_scalar($c['value'] ?? null) ? (string) $c['value'] : '';
        if (isset(ConditionEvaluator::TEXT[$type])) {
            if ($op === 'not_contains' || $op === 'not_equal') {
                return true;    // the default text does not contain it… checked afterwards
            }
            if ($op === 'regex') {
                $text = self::fromRegex($value);
                if ($text === null) {
                    return false;
                }
            } elseif ($value === '') {
                return false;
            } else {
                $text = $value;
            }
            if ($type === 'customer_email' && $op !== 'equal' && strpos($text, '@') === false) {
                $text = 'client.'.preg_replace('/[^A-Za-z0-9._-]+/', '', $text).'@example.test';
            } elseif ($type === 'customer_email' && $op === 'contains' && strpos($text, '@') === 0) {
                $text = 'client'.$text;
            }
            if ($op === 'equal') {
                if (isset($equal[$type]) && $equal[$type] !== $text) {
                    return false;
                }
                $equal[$type] = $text;
            } elseif ($op === 'starts') {
                $parts[$type] = $parts[$type] ?? [];
                array_unshift($parts[$type], $text);
            } else {
                $parts[$type][] = $text;
            }

            return true;
        }
        switch ($type) {
            case 'new_or_reply':
                return $op === 'new';
            case 'status':
                if ($op === 'equal' && in_array($value, ['1', '2', '3', '4'], true)) {
                    $input['status'] = (int) $value;

                    return true;
                }

                return $op === 'not_equal' && $value !== '1';
            case 'user':
                if ($value === '-1') {
                    $input['assigned'] = $op !== 'equal';

                    return true;
                }

                return $op === 'not_equal';
            case TicketConditions::ASSIGNED:
                $input['assigned'] = $op === 'yes';

                return in_array($op, TicketConditions::OPERATORS, true);
            case TicketConditions::REPLIED:
                $input['replied'] = $op === 'yes';

                return in_array($op, TicketConditions::OPERATORS, true);
            case MailTags::TYPE:
                if ($op === 'has' && $value !== '') {
                    $input['tags'][] = $value;
                }

                return in_array($op, MailTags::OPERATORS, true) && ($op === 'has_not' || $value !== '');
        }

        return false;
    }

    /**
     * A text matching a regular expression (PCRE, with delimiters), or null. Tries the first alternatives one by one
     * and keeps the first example that preg_match accepts.
     */
    public static function fromRegex($pattern)
    {
        if (strlen($pattern) < 2 || @preg_match($pattern, '') === false) {
            return null;
        }
        $delimiter = $pattern[0];
        $end = strrpos($pattern, $delimiter === '(' ? ')' : ($delimiter === '{' ? '}' : $delimiter));
        if (!$end) {
            return null;
        }
        $body = substr($pattern, 1, $end - 1);
        foreach (array_slice(self::alternatives($body), 0, 20) as $alternative) {
            $pos = 0;
            $text = trim(self::sequence($alternative, $pos));
            if ($text !== '' && @preg_match($pattern, $text) === 1) {
                return $text;
            }
        }

        return null;
    }

    /** Top-level alternatives of a pattern body (a|b|c), groups and classes kept whole. */
    protected static function alternatives($body)
    {
        $out = [];
        $depth = 0;
        $class = false;
        $current = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $ch = $body[$i];
            if ($ch === '\\' && $i + 1 < $n) {
                $current .= $ch.$body[++$i];
                continue;
            }
            if ($class) {
                $class = $ch !== ']';
            } elseif ($ch === '[') {
                $class = true;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === '|' && $depth === 0) {
                $out[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $out[] = $current;

        return $out;
    }

    /** Example text of a sequence of tokens, until the end or ")" of the current group. */
    protected static function sequence($s, &$i)
    {
        $out = '';
        $n = strlen($s);
        while ($i < $n && $s[$i] !== ')') {
            $token = self::token($s, $i);
            // quantifier of the token: ? and * -> none, + -> once, {n,m} -> n times
            $times = 1;
            if ($i < $n && ($s[$i] === '?' || $s[$i] === '*')) {
                $times = 0;
                $i++;
            } elseif ($i < $n && $s[$i] === '+') {
                $i++;
            } elseif ($i < $n && $s[$i] === '{' && preg_match('/^\{(\d+)(,\d*)?\}/', substr($s, $i), $m)) {
                $times = min(5, (int) $m[1]);
                $i += strlen($m[0]);
            }
            if ($i < $n && ($s[$i] === '?' || $s[$i] === '+')) {
                $i++;   // lazy / possessive
            }
            $out .= str_repeat($token, $times);
        }

        return $out;
    }

    protected static function token($s, &$i)
    {
        $ch = $s[$i++];
        $n = strlen($s);
        if ($ch === '\\') {
            $next = $i < $n ? $s[$i++] : '';
            $map = ['s' => ' ', 'd' => '1', 'w' => 'a', 'b' => '', 'B' => '', 'A' => '', 'z' => '', 'Z' => '', 'n' => ' ', 't' => ' ', 'S' => 'a', 'D' => 'a', 'W' => ' '];
            if ($next === 'x' && $i < $n && $s[$i] === '{' && preg_match('/^\{([0-9A-Fa-f]+)\}/', substr($s, $i), $m)) {
                $i += strlen($m[0]);

                return self::char(hexdec($m[1]));
            }

            return array_key_exists($next, $map) ? $map[$next] : $next;
        }
        if ($ch === '[') {
            $negated = $i < $n && $s[$i] === '^';
            $first = null;
            if ($negated) {
                $i++;
            }
            while ($i < $n && $s[$i] !== ']') {
                $c = $s[$i++];
                if ($c === '\\' && $i < $n) {
                    $e = $s[$i++];
                    $c = ['s' => ' ', 'd' => '1', 'w' => 'a'][$e] ?? $e;
                } elseif (ord($c) >= 0xC0) {
                    // a multibyte character (é, ч…)
                    while ($i < $n && (ord($s[$i]) & 0xC0) === 0x80) {
                        $c .= $s[$i++];
                    }
                }
                if ($first === null) {
                    $first = $c;
                }
            }
            $i++;

            return $negated ? 'x' : (string) $first;
        }
        if ($ch === '(') {
            if (substr($s, $i, 2) === '?=' || substr($s, $i, 2) === '?!' || substr($s, $i, 3) === '?<=' || substr($s, $i, 3) === '?<!') {
                self::skipGroup($s, $i);

                return '';
            }
            if ($i < $n && $s[$i] === '?') {
                // (?: … ) (?i) (?<name> … )
                $close = strcspn($s, ':)>', $i);
                $i += $close + 1;
                if ($s[$i - 1] === ')') {
                    return '';
                }
            }
            $start = $i;
            self::skipGroup($s, $i);
            $inner = substr($s, $start, $i - $start - 1);
            $alternatives = self::alternatives($inner);
            $pos = 0;

            return self::sequence($alternatives[0], $pos);
        }
        if ($ch === '.') {
            return 'a';
        }
        if ($ch === '^' || $ch === '$') {
            return '';
        }
        if (ord($ch) >= 0xC0) {
            while ($i < $n && (ord($s[$i]) & 0xC0) === 0x80) {
                $ch .= $s[$i++];
            }
        }

        return $ch;
    }

    /** Moves $i after the ")" that closes the group starting at $i. */
    protected static function skipGroup($s, &$i)
    {
        $depth = 1;
        $class = false;
        for ($n = strlen($s); $i < $n && $depth > 0; $i++) {
            $ch = $s[$i];
            if ($ch === '\\') {
                $i++;
            } elseif ($class) {
                $class = $ch !== ']';
            } elseif ($ch === '[') {
                $class = true;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
        }
    }

    protected static function char($code)
    {
        return function_exists('mb_chr') ? (string) mb_chr($code, 'UTF-8') : html_entity_decode('&#'.$code.';', ENT_QUOTES, 'UTF-8');
    }
}
