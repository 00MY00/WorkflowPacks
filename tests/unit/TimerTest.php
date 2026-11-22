<?php

use Modules\WorkflowPacks\Services\Support\Timer;

test('Minuteur : délai { number, metric } contrôlé et normalisé (minutes, heures, jours)', function () {
    same(['value' => ['number' => 30, 'metric' => 'd'], 'error' => null], Timer::check(['number' => '30', 'metric' => 'd']));
    same(['number' => 90, 'metric' => 'i'], Timer::check(['number' => 90, 'metric' => 'i'])['value']);
    foreach ([null, 'x', ['number' => 3], ['metric' => 'd']] as $bad) {
        same('timer_shape', Timer::check($bad)['error'], json_encode($bad));
    }
    foreach ([0, -1, 1.5, '3j', 100001] as $bad) {
        same('timer_number', Timer::check(['number' => $bad, 'metric' => 'd'])['error'], json_encode($bad));
    }
    same('timer_metric', Timer::check(['number' => 3, 'metric' => 'w'])['error']);
    same(3 * 86400, Timer::seconds(['number' => 3, 'metric' => 'd']));
    same(2 * 3600, Timer::seconds(['number' => 2, 'metric' => 'h']));
    same('30 jour(s)', Timer::label(['number' => 30, 'metric' => 'd']));
});
