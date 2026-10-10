<?php

use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\RuleCompiler;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;

$compiler = function (?callable $refCheck = null) {
    return new RuleCompiler(require __DIR__.'/fixtures/catalog.php', config_workflows(), ['automatic' => 1, 'manual' => 2], $refCheck);
};
$rule = function (array $over = []) {
    return $over + [
        'key' => 'r', 'name' => 'Règle {{mailbox.name}}', 'description' => '', 'type' => 'automatic',
        'settings' => ['apply_to_prev' => false, 'max_executions' => 1], 'requires' => [],
        'conditions' => ['all' => [['type' => 'new_or_reply', 'operator' => 'new'], ['type' => 'to', 'operator' => 'regex', 'value' => '/{{mailbox.email}}/i']]],
        'actions' => [['type' => 'note', 'value' => ['body' => 'Boîte {{mailbox.name}}']], ['type' => 'status', 'value' => '3']],
    ];
};
$vars = function () {
    return VariableResolver::forMailbox(4, 'sales@example.test', 'Ventes & co', [], []);
};

test('Compilation : attributs Workflows (types, groupes, actions, valeur JSON des notes)', function () use ($compiler, $rule, $vars) {
    $r = $compiler()->compile($rule(), $vars(), 4);
    check($r['problems']->isEmpty(), implode("\n", $r['problems']->lines()));
    $a = $r['attributes'];
    same('Règle Ventes & co', $a['name']);
    same(1, $a['type']);
    same(1, $a['max_executions']);
    same([[['type' => 'new_or_reply', 'operator' => 'new']], [['type' => 'to', 'operator' => 'regex', 'value' => '/sales@example\.test/i']]], $a['conditions']);
    same('note', $a['actions'][0][0]['type']);
    check(is_string($a['actions'][0][0]['value']), 'valeur de note stockée en JSON (Workflow::performActions json_decode)');
    same(['body' => 'Boîte Ventes &amp; co'], json_decode($a['actions'][0][0]['value'], true));
    same([], $r['sensitive']);
});

test('Compilation : type, opérateur ou valeur inconnus de Workflows refusés avec la liste disponible', function () use ($compiler, $rule, $vars) {
    $r = $compiler()->compile($rule(['actions' => [['type' => 'add_tags', 'value' => ['x']]]]), $vars(), 4);
    same(['WP-CAT-03'], codes($r['problems']));
    check(strpos($r['problems']->all()[0]['message'], 'Types disponibles') !== false, 'liste des types');
    same(null, $r['attributes']);

    $r = $compiler()->compile($rule(['conditions' => ['all' => [['type' => 'subject', 'operator' => 'longer', 'value' => 'x']]]]), $vars(), 4);
    same(['WP-CAT-02'], codes($r['problems']));

    $r = $compiler()->compile($rule(['actions' => [['type' => 'status', 'value' => '9']]]), $vars(), 4);
    same(['WP-CAT-04'], codes($r['problems']));
});

test('Compilation : nom trop long après remplacement (75 caractères dans Workflows)', function () use ($compiler, $rule) {
    $vars = VariableResolver::forMailbox(4, 'x@example.test', str_repeat('n', 70), [], []);
    same(['WP-RULE-14'], codes($compiler()->compile($rule(), $vars, 4)['problems']));
});

test('Compilation : actions sensibles signalées (envoi, transfert, suppression, changement de boîte)', function () use ($compiler, $rule, $vars) {
    $r = $compiler(function () {
        return null;
    })->compile($rule(['actions' => [
        ['type' => 'forward', 'value' => ['to' => 'a@example.test', 'body' => '']],
        ['type' => 'delete'],
        ['type' => 'move', 'value' => 2],
        ['type' => 'notification', 'value' => ['assignee']],
    ]]), $vars(), 4);
    check($r['problems']->isEmpty(), implode("\n", $r['problems']->lines()));
    same(['forward', 'delete', 'move', 'send'], $r['sensitive']);
});

test('Compilation : références locales vérifiées (utilisateur, boîte), valeurs spéciales acceptées', function () use ($compiler, $rule, $vars) {
    $seen = [];
    $check = function ($kind, $value, $mailboxId) use (&$seen) {
        $seen[] = $kind.':'.$value.'@'.$mailboxId;

        return (string) $value === '99' ? 'utilisateur #99 introuvable' : null;
    };
    $r = $compiler($check)->compile($rule(['actions' => [
        ['type' => 'assign', 'value' => '-1'],
        ['type' => 'assign', 'value' => '99'],
        ['type' => 'notification', 'value' => ['last_user', '5']],
    ]]), $vars(), 4);
    same(['WP-REF-01'], codes($r['problems']));
    same(['user:99@4', 'user:5@4'], $seen);
});

test('Empreinte : indépendante de l\'ordre des clés, des types "1"/1 et du JSON des notes', function () {
    $json = config_workflows()['json_value_actions'];
    $a = ['name' => 'R', 'type' => 1, 'max_executions' => 1, 'conditions' => [[['type' => 'status', 'operator' => 'equal', 'value' => 1]]],
        'actions' => [[['type' => 'note', 'value' => '{"body":"x"}']]], 'active' => true];
    $b = ['actions' => [[['value' => '{"body": "x"}', 'type' => 'note']]], 'conditions' => [[['operator' => 'equal', 'value' => '1', 'type' => 'status']]],
        'max_executions' => '1', 'type' => '1', 'name' => 'R', 'active' => false];
    same(Fingerprint::of($a, $json), Fingerprint::of($b, $json), 'l\'état actif ne compte pas');
    $c = $b;
    $c['conditions'][0][0]['value'] = '2';
    check(Fingerprint::of($a, $json) !== Fingerprint::of($c, $json), 'une valeur modifiée change l\'empreinte');
    same(['conditions'], array_keys(Fingerprint::diff($a, $c, $json)));
});

test('Pack d\'exemple : compile pour deux boîtes avec leurs propres variables', function () use ($compiler) {
    $pack = (new PackValidator())->validateJson(file_get_contents(__DIR__.'/../../WorkflowPacks/Resources/packs/tri-emails-multilingue.json'))['pack'];
    $params = ['security_email' => 'secu@example.test', 'newsletter_sender' => 'news@example.test'];
    foreach ([[1, 'support@example.test', 'Support'], [2, 'factures@example.test', 'Factures']] as $mb) {
        $vars = VariableResolver::forMailbox($mb[0], $mb[1], $mb[2], $pack['params'], $params);
        foreach ($pack['workflows'] as $rule) {
            $r = $compiler(function () {
                return null;
            })->compile($rule, $vars, $mb[0]);
            check($r['problems']->isEmpty(), $rule['key'].' : '.implode("\n", $r['problems']->lines()));
            foreach ($r['attributes']['actions'][0] as $action) {
                check(!in_array($action['type'], ['delete', 'delete_forever'], true), 'aucune suppression dans le pack');
            }
        }
    }
    // without the optional newsletter sender, only the newsletter rule is blocked, with an explanation
    $vars = VariableResolver::forMailbox(1, 'support@example.test', 'Support', $pack['params'], ['security_email' => 'secu@example.test', 'newsletter_sender' => null]);
    foreach ($pack['workflows'] as $rule) {
        $r = $compiler(function () {
            return null;
        })->compile($rule, $vars, 1);
        same($rule['key'] === 'newsletters' ? ['WP-VAR-03'] : [], codes($r['problems']), $rule['key']);
    }
});
