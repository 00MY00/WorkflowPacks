<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * Replacement of {{variables}} in the values of a pack, for one target mailbox, at import time.
 *
 * Only plain names are accepted: {{mailbox.id}}, {{mailbox.email}}, {{mailbox.name}}, {{param.<key>}}.
 * Nothing is evaluated: no PHP, no JavaScript, no expression, no filter. Anything else between {{ }} is an error.
 *
 * Contexts:
 *   text   the value is inserted as is; a value made of a single numeric variable keeps its integer type
 *   regex  the value is inserted as literal text (preg_quote() with the delimiter of the pattern)
 *   html   the value is HTML-escaped (bodies of notes, replies and forwards)
 */
class VariableResolver
{
    const PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*\}\}/';

    /** @var array name => ['value' => mixed, 'int' => bool] (value null = not provided) */
    protected $vars;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
    }

    /**
     * Variables of the target mailbox + values of the pack parameters.
     *
     * @param array $params  declarations of the pack (key, type, …)
     * @param array $values  key => value entered at import (already checked)
     */
    public static function forMailbox($id, $email, $name, array $params, array $values)
    {
        $vars = [
            'mailbox.id'    => ['value' => (int) $id, 'int' => true],
            'mailbox.email' => ['value' => (string) $email, 'int' => false],
            'mailbox.name'  => ['value' => (string) $name, 'int' => false],
        ];
        foreach ($params as $param) {
            $key = $param['key'];
            $value = array_key_exists($key, $values) ? $values[$key] : null;
            $int = in_array($param['type'] ?? 'string', ['integer', 'user', 'mailbox'], true);
            if ($value !== null && $int) {
                $value = (int) $value;
            }
            $vars['param.'.$key] = ['value' => $value, 'int' => $int];
        }

        return new self($vars);
    }

    /**
     * Names used in a string, or problems when the syntax is wrong ("{{" not followed by a plain name).
     *
     * @return string[]
     */
    public static function names($string, $path, Problems $problems)
    {
        if (!is_string($string) || strpos($string, '{{') === false && strpos($string, '}}') === false) {
            return [];
        }
        preg_match_all(self::PATTERN, $string, $m);
        $rest = preg_replace(self::PATTERN, '', $string);
        if (strpos($rest, '{{') !== false || strpos($rest, '}}') !== false) {
            $problems->add($path, 'WP-VAR-01', 'var_syntax');
        }

        return array_values(array_unique($m[1]));
    }

    /** Every variable name used anywhere in a value (string, list or object). */
    public static function namesIn($value, $path, Problems $problems)
    {
        if (is_string($value)) {
            return self::names($value, $path, $problems);
        }
        $names = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $names = array_merge($names, self::namesIn($v, $path.'.'.$k, $problems));
            }
        }

        return array_values(array_unique($names));
    }

    public static function knownName($name, array $paramKeys)
    {
        if (in_array($name, ['mailbox.id', 'mailbox.email', 'mailbox.name'], true)) {
            return true;
        }

        return strpos($name, 'param.') === 0 && in_array(substr($name, 6), $paramKeys, true);
    }

    /** Resolves a string in a context; returns an int when the string is exactly one integer variable (text context). */
    public function resolveString($string, $context, $path, Problems $problems)
    {
        if (!is_string($string) || strpos($string, '{{') === false) {
            return $string;
        }
        $names = self::names($string, $path, $problems);
        foreach ($names as $name) {
            if (!array_key_exists($name, $this->vars)) {
                $problems->add($path, 'WP-VAR-02', 'var_unknown', ['var' => $name]);

                return $string;
            }
            if ($this->vars[$name]['value'] === null) {
                $problems->add($path, 'WP-VAR-03', 'var_missing', ['var' => $name]);

                return $string;
            }
        }

        if ($context === 'text' && preg_match('/^'.substr(self::PATTERN, 1, -1).'$/', $string, $m) && $this->vars[$m[1]]['int']) {
            return (int) $this->vars[$m[1]]['value'];
        }

        $delimiter = null;
        if ($context === 'regex') {
            if (strpos($string, '{{') === 0) {
                $problems->add($path, 'WP-VAR-04', 'var_regex_delimiter');

                return $string;
            }
            $delimiter = $string[0];
        }

        $vars = $this->vars;

        return preg_replace_callback(self::PATTERN, function ($m) use ($vars, $context, $delimiter) {
            $value = (string) $vars[$m[1]]['value'];
            if ($context === 'regex') {
                return preg_quote($value, $delimiter);
            }
            if ($context === 'html') {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }

            return $value;
        }, $string);
    }

    /** Resolves every string of a value (lists / objects) in the text context. */
    public function resolveValue($value, $path, Problems $problems, $context = 'text')
    {
        if (is_string($value)) {
            return $this->resolveString($value, $context, $path, $problems);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->resolveValue($v, $path.'.'.$k, $problems, $context);
            }

            return $out;
        }

        return $value;
    }
}
