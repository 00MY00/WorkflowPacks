<?php

namespace Modules\WorkflowPacks\Services\Support;

/**
 * Delays — no Laravel dependency: { "number": 30, "metric": "d" } with the metric i (minutes), h (hours) or d (days).
 * Checked and normalized here, with a readable form. Used by the "Wait" block of the flows and by the automatic
 * clean-up of the e-mail folders (Services/Imap/Retention).
 */
class Timer
{
    const METRICS = ['i', 'h', 'd'];
    const MAX = 100000;


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
            return Texts::t($key, $params);
        };

        return $translate('timer_'.($value['metric'] ?? 'd'), ['count' => (int) ($value['number'] ?? 0)]);
    }
}
