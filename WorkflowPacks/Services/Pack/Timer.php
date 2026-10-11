<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * Delays ("timer" rules) — no Laravel dependency.
 *
 * Workflows evaluates four date conditions itself, again and again from its scheduler (Workflow::$date_conditions):
 * created, waiting, user_reply, customer_reply, with the value { "number": 30, "metric": "d" } and the metric
 * i (minutes), h (hours) or d (days). A rule "created not in the last 30 days AND status closed -> delete" is such a
 * timer rule. This class checks and normalizes those values, and gives them a readable form.
 *
 * The same value is used for the automatic clean-up of the e-mail folders (Services/Imap/Retention).
 */
class Timer
{
    const CONDITIONS = ['created', 'waiting', 'user_reply', 'customer_reply'];
    const METRICS = ['i', 'h', 'd'];
    const MAX = 100000;

    public static function isTimer($type)
    {
        return in_array((string) $type, self::CONDITIONS, true);
    }

    /**
     * { number, metric } with number a whole number from 1 to MAX ("30" accepted), metric i | h | d.
     *
     * @return array ['value' => normalized value or null, 'error' => null or key of texts.php]
     */
    public static function check($value)
    {
        if (!is_array($value) || !array_key_exists('number', $value) || !array_key_exists('metric', $value)) {
            return ['value' => null, 'error' => 'timer_shape'];
        }
        $number = $value['number'];
        if (is_string($number) && preg_match('/^\d+$/', trim($number))) {
            $number = (int) trim($number);
        }
        if (!is_int($number) || $number < 1 || $number > self::MAX) {
            return ['value' => null, 'error' => 'timer_number'];
        }
        if (!in_array($value['metric'], self::METRICS, true)) {
            return ['value' => null, 'error' => 'timer_metric'];
        }

        return ['value' => ['number' => $number, 'metric' => $value['metric']], 'error' => null];
    }

    /** Delay in seconds (used by the clean-up of the folders). */
    public static function seconds(array $value)
    {
        $unit = ['i' => 60, 'h' => 3600, 'd' => 86400][$value['metric']] ?? 86400;

        return (int) $value['number'] * $unit;
    }

    /**
     * "30 days", in the language of the interface.
     *
     * @param callable|null $translate function (string $key, array $params): string (Laravel's __() inside FreeScout)
     */
    public static function label(array $value, ?callable $translate = null)
    {
        $translate = $translate ?: function ($key, array $params) {
            return Problems::t($key, $params);
        };

        return $translate('timer_'.($value['metric'] ?? 'd'), ['count' => (int) ($value['number'] ?? 0)]);
    }
}
