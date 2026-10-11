<?php

use Modules\WorkflowPacks\Services\Pack\RuleCompiler;
use Modules\WorkflowPacks\Services\Pack\Timer;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;

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
    check(Timer::isTimer('created') && Timer::isTimer('waiting') && !Timer::isTimer('subject'), 'conditions de date de Workflows');
});

test('Minuteur : règle « créée il y a plus de 30 jours ET fermée -> supprimer » compilée pour Workflows', function () {
    $compiler = new RuleCompiler(require __DIR__.'/fixtures/catalog.php', config_workflows(), ['automatic' => 1, 'manual' => 2]);
    $vars = VariableResolver::forMailbox(4, 'sales@example.test', 'Ventes', [], []);
    $rule = function ($value) {
        return ['key' => 'purge', 'name' => 'Purge des fermées', 'type' => 'automatic', 'settings' => ['apply_to_prev' => true, 'max_executions' => 1],
            'conditions' => ['all' => [['type' => 'created', 'operator' => 'not_in_last', 'value' => $value], ['type' => 'status', 'operator' => 'equal', 'value' => '3']]],
            'actions' => [['type' => 'delete']]];
    };
    $r = $compiler->compile($rule(['number' => '30', 'metric' => 'd']), $vars, 4);
    check($r['problems']->isEmpty(), implode("\n", $r['problems']->lines()));
    same(['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => 30, 'metric' => 'd']], $r['attributes']['conditions'][0][0], 'nombre entier, comme Workflows l\'attend');
    same(['WP-TIME-01'], codes($compiler->compile($rule(['number' => 30, 'metric' => 'w']), $vars, 4)['problems']));
    same(['WP-TIME-01'], codes($compiler->compile($rule('30 jours'), $vars, 4)['problems']));
});
