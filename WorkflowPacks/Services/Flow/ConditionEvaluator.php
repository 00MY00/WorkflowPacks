<?php

namespace Modules\WorkflowPacks\Services\Flow;

use Modules\WorkflowPacks\Services\MailTags;
use Modules\WorkflowPacks\Services\Pack\Timer;

/**
 * Evaluates one condition of Workflows on a simulated e-mail — no Laravel dependency (tests/unit).
 *
 * It follows the behaviour of Workflows documented in docs/analyse.md: text compared in lower case (except regex,
 * passed to preg_match as written), status / assignee by value, "New / Reply" only on the event of the arrival,
 * date conditions against the simulated time. Workflows' own engine (paid module) is not available: a type that is
 * not described here is answered null ("cannot be simulated"), never guessed.
 */
class ConditionEvaluator
{
    /** Text fields of the simulated e-mail, by condition type. */
    const TEXT = ['subject' => 'subject', 'body' => 'body', 'customer_email' => 'customer_email', 'customer_name' => 'customer_name', 'to' => 'to', 'cc' => 'cc'];

    /**
     * @param array $condition ['type', 'operator', 'value']
     * @param array $mail      the simulated e-mail (Simulator::mail())
     * @param array $state     the ticket now (Simulator::state())
     * @param int   $t         simulated time, in seconds since the arrival
     * @param bool  $event     true at the arrival (an event of Workflows), false for the scheduler's checks
     * @return array ['result' => true|false|null, 'note' => '' | key of a note]
     */
    public static function evaluate(array $condition, array $mail, array $state, $t, $event)
    {
        $type = (string) ($condition['type'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        $value = $condition['value'] ?? null;

        if (isset(self::TEXT[$type])) {
            $text = $type === 'body' ? strip_tags((string) $mail['body']) : (string) $mail[self::TEXT[$type]];

            return self::text($operator, $value, $text);
        }
        switch ($type) {
            case 'new_or_reply':
                // Workflows only starts it on its events: the scheduler's checks never match it
                return self::ok($event && $operator === 'new');
            case 'status':
                return self::compare($operator, (string) $state['status'] === (string) $value);
            case 'user':
                $assignee = (string) ($state['user_id'] ?: '-1');

                return self::compare($operator, $assignee === (string) $value);
            case TicketConditions::ASSIGNED:
                return self::known(TicketConditions::answer($operator, !in_array((int) $state['user_id'], [0, -1], true)), $operator);
            case TicketConditions::REPLIED:
                return self::known(TicketConditions::answer($operator, $state['reply_at'] !== null), $operator);
            case MailTags::TYPE:
                return self::ok(MailTags::check($operator, (string) $value, (array) $mail['tags']));
        }
        if (Timer::isTimer($type)) {
            return self::date($type, $operator, $value, $state, $t);
        }

        return ['result' => null, 'note' => 'sim_unknown_condition'];
    }

    protected static function text($operator, $value, $text)
    {
        $value = (string) $value;
        if ($operator === 'regex') {
            $matched = @preg_match($value, $text);

            return $matched === false ? ['result' => false, 'note' => 'sim_bad_regex'] : self::ok($matched === 1);
        }
        $text = mb_strtolower($text);
        $value = mb_strtolower($value);
        switch ($operator) {
            case 'equal':
                return self::ok($text === $value);
            case 'not_equal':
                return self::ok($text !== $value);
            case 'contains':
                return self::ok($value !== '' && mb_strpos($text, $value) !== false);
            case 'not_contains':
                return self::ok($value === '' || mb_strpos($text, $value) === false);
            case 'starts':
                return self::ok($value !== '' && mb_strpos($text, $value) === 0);
            case 'ends':
                return self::ok($value !== '' && mb_substr($text, -mb_strlen($value)) === $value);
        }

        return ['result' => null, 'note' => 'sim_unknown_operator'];
    }

    /**
     * Age of the ticket for a date condition. created / customer_reply: since the arrival; user_reply: since the
     * last reply of the simulation (none: false, the date is missing); waiting: since the arrival while nobody replied.
     */
    protected static function date($type, $operator, $value, array $state, $t)
    {
        $checked = Timer::check($value);
        if ($checked['error'] !== null) {
            return ['result' => false, 'note' => 'sim_bad_delay'];
        }
        $limit = Timer::seconds($checked['value']);
        if ($type === 'user_reply') {
            if ($state['reply_at'] === null) {
                return ['result' => false, 'note' => 'sim_no_reply'];
            }
            $age = $t - $state['reply_at'];
        } elseif ($type === 'waiting') {
            $age = $state['reply_at'] === null ? $t : 0;
        } else {
            $age = $t;
        }
        switch ($operator) {
            case 'in_last':
            case 'not_longer':
                return self::ok($age <= $limit);
            case 'not_in_last':
            case 'longer':
                return self::ok($age > $limit);
        }

        return ['result' => null, 'note' => 'sim_unknown_operator'];
    }

    protected static function compare($operator, $equal)
    {
        if ($operator === 'equal') {
            return self::ok($equal);
        }

        return $operator === 'not_equal' ? self::ok(!$equal) : ['result' => null, 'note' => 'sim_unknown_operator'];
    }

    protected static function known($result, $operator)
    {
        return in_array($operator, TicketConditions::OPERATORS, true) ? self::ok($result) : ['result' => null, 'note' => 'sim_unknown_operator'];
    }

    protected static function ok($result)
    {
        return ['result' => (bool) $result, 'note' => ''];
    }
}
