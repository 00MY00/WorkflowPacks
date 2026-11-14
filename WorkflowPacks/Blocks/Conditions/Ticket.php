<?php

namespace Modules\WorkflowPacks\Blocks\Conditions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/**
 * "If": tests a variable made by a block before (the ticket of the trigger: subject, sender, status…, or the result
 * of another block). The editor only offers the variables available here, the operators of their type and, for a
 * closed list, its values (Definition::variablesBefore).
 */
class Ticket extends AbstractBlock
{
    /** operators by type of variable */
    const OPERATORS = [
        'text'   => ['contains', 'not_contains', 'equal', 'not_equal', 'starts', 'ends', 'regex'],
        'choice' => ['is', 'is_not'],
        'bool'   => ['yes', 'no'],
        'number' => ['gte', 'lte', 'eq'],
    ];
    const REGEX_MAX = 2000;

    public static function type()
    {
        return 'condition.ticket';
    }

    public static function family()
    {
        return 'condition';
    }

    public static function fields()
    {
        return [
            'var'      => ['type' => 'variable', 'required' => true],
            'operator' => ['type' => 'operator', 'required' => true],
            'value'    => ['type' => 'value'],
        ];
    }

    public static function outputs()
    {
        return ['yes', 'no'];
    }

    protected static function titleParams(array $params)
    {
        return [
            'var'      => self::varLabel($params['var'] ?? ''),
            'operator' => __('workflowpacks::blocks.ops.'.($params['operator'] ?? '')),
            'value'    => self::short($params['value'] ?? '', 40),
        ];
    }

    public static function title(array $params)
    {
        $key = self::opFamily($params['operator'] ?? '') === 'bool' ? 'title_bool' : 'title';

        return self::text(self::type(), $key, self::titleParams($params));
    }

    public static function validate(array $params)
    {
        $problems = parent::validate($params);
        $op = (string) ($params['operator'] ?? '');
        $family = self::opFamily($op);
        if (!$family) {
            $problems['operator'] = __('workflowpacks::blocks.errors.choice');
        } elseif ($family !== 'bool' && (string) ($params['value'] ?? '') === '') {
            $problems['value'] = __('workflowpacks::blocks.errors.required');
        } elseif ($op === 'regex' && !self::regex((string) $params['value'])) {
            $problems['value'] = __('workflowpacks::blocks.errors.regex', ['max' => self::REGEX_MAX]);
        }

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        $var = (string) ($params['var'] ?? '');
        $op = (string) ($params['operator'] ?? '');
        $actual = $ctx->get($var);
        $yes = self::test($op, $actual, $params['value'] ?? null);
        $summary = __('workflowpacks::blocks.condition.ticket.decision', [
            'var'      => self::varLabel($var),
            'actual'   => self::short(is_bool($actual) ? __('workflowpacks::blocks.ops.'.($actual ? 'yes' : 'no')) : $actual),
            'operator' => __('workflowpacks::blocks.ops.'.$op),
            'value'    => self::opFamily($op) === 'bool' ? '' : self::short($params['value'] ?? ''),
            'output'   => __('workflowpacks::blocks.outputs.'.($yes ? 'yes' : 'no')),
        ]);

        return Result::next($yes ? 'yes' : 'no', $summary);
    }

    public static function test($op, $actual, $expected)
    {
        $a = mb_strtolower(is_scalar($actual) ? (string) $actual : '');
        $e = mb_strtolower(is_scalar($expected) ? (string) $expected : '');
        switch ($op) {
            case 'contains':     return $e !== '' && mb_strpos($a, $e) !== false;
            case 'not_contains': return $e === '' || mb_strpos($a, $e) === false;
            case 'equal':
            case 'is':           return $a === $e;
            case 'not_equal':
            case 'is_not':       return $a !== $e;
            case 'starts':       return $e !== '' && mb_strpos($a, $e) === 0;
            case 'ends':         return $e !== '' && mb_substr($a, -mb_strlen($e)) === $e;
            case 'regex':        return self::regex((string) $expected, is_scalar($actual) ? (string) $actual : '');
            case 'yes':          return (bool) $actual;
            case 'no':           return !$actual;
            case 'gte':          return is_numeric($actual) && (float) $actual >= (float) $expected;
            case 'lte':          return is_numeric($actual) && (float) $actual <= (float) $expected;
            case 'eq':           return is_numeric($actual) && (float) $actual == (float) $expected;
        }

        return false;
    }

    /**
     * Pattern written without delimiters, case-insensitive. A limited backtracking: a trap pattern cannot block the
     * server. $subject null: only checks the pattern.
     */
    protected static function regex($pattern, $subject = null)
    {
        if ($pattern === '' || mb_strlen($pattern) > self::REGEX_MAX) {
            return false;
        }
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');
        $result = @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', (string) $subject);
        ini_set('pcre.backtrack_limit', $limit);

        return $subject === null ? $result !== false : $result === 1;
    }

    protected static function opFamily($op)
    {
        foreach (self::OPERATORS as $family => $ops) {
            if (in_array($op, $ops, true)) {
                return $family;
            }
        }

        return null;
    }

    protected static function varLabel($key)
    {
        $label = __('workflowpacks::blocks.vars.'.$key);

        return $label === 'workflowpacks::blocks.vars.'.$key ? $key : $label;
    }
}
