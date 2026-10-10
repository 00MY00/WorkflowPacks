<?php

use Modules\WorkflowPacks\Services\Pack\MailboxSelector as S;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Pack\RuleCompiler;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;
use Modules\WorkflowPacks\Services\Plan\Importer;

$v2 = function (array $over = [], array $rule = []) {
    return $over + [
        'format' => 'freescout-workflow-pack', 'version' => 2, 'key' => 'demo', 'name' => 'Démo',
        'workflows' => [$rule + ['key' => 'r1', 'name' => 'R1',
            'conditions' => ['all' => [['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]],
            'actions' => [['type' => 'status', 'value' => '3']]]],
    ];
};

// ------------------------------------------------------------------------------------------------ selectors

test('Sélecteurs : toutes les boîtes, adresse contient / ne contient pas, nom, ET / OU', function () {
    $p = new Problems();
    $all = S::validate(['select' => 'all'], 's', $p);
    check(S::matches($all, 'perso@gmail.test', 'Perso'), 'all');
    $domain = S::validate(['select' => 'match', 'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '@Example.com']]], 's', $p);
    check($p->isEmpty(), implode("\n", $p->lines()));
    foreach (['contact@example.com' => true, 'support@EXAMPLE.com' => true, 'security@example.com' => true, 'perso@gmail.test' => false] as $email => $expected) {
        same($expected, S::matches($domain, $email, ''), $email);
    }
    $and = S::validate(['select' => 'match', 'combine' => 'all', 'rules' => [
        ['field' => 'email', 'operator' => 'ends', 'value' => '@example.com'],
        ['field' => 'name', 'operator' => 'not_contains', 'value' => 'test'],
    ]], 's', $p);
    same(true, S::matches($and, 'a@example.com', 'Support'));
    same(false, S::matches($and, 'a@example.com', 'Boîte de TEST'));
    same(false, S::matches($and, 'a@example.org', 'Support'));
    same(true, S::matches(null, 'x@y.test', 'n'), 'sans sélecteur (v1) : toutes les boîtes choisies');
    same('Boîtes dont l\'adresse contient « @Example.com »', S::describe($domain));
    same('Toutes les boîtes', S::describe($all));
});

test('Sélecteurs : formes invalides refusées (variable, champ, opérateur, critères vides)', function () {
    foreach ([
        'WP-SEL-11' => ['select' => 'match', 'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '{{mailbox.email}}']]],
        'WP-SEL-08' => ['select' => 'match', 'rules' => [['field' => 'id', 'operator' => 'contains', 'value' => '1']]],
        'WP-SEL-09' => ['select' => 'match', 'rules' => [['field' => 'email', 'operator' => 'regex', 'value' => '/x/']]],
        'WP-SEL-06' => ['select' => 'match', 'rules' => []],
        'WP-SEL-03' => ['select' => 'all', 'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => 'x']]],
        'WP-SEL-04' => ['select' => 'some'],
    ] as $code => $selector) {
        $p = new Problems();
        same(null, S::validate($selector, 's', $p), $code);
        same($code, codes($p)[0] ?? '', $code);
    }
});

// ---------------------------------------------------------------------------------------------- format v2

test('Format v2 : le pack de démonstration est valide, sélecteurs et ressources normalisés', function () {
    $r = (new PackValidator())->validateJson(file_get_contents(__DIR__.'/../../WorkflowPacks/Resources/packs/demo-portable-v2.json'));
    check($r['problems']->isEmpty(), implode("\n", $r['problems']->lines()));
    $pack = $r['pack'];
    same(2, $pack['version']);
    same('match', $pack['mailboxes']['select']);
    same(['billing-user', 'security-user'], array_column($pack['resources'], 'key'));
    same('id', $pack['resources'][0]['use']);
    same(['select' => 'all'], $pack['workflows'][1]['mailboxes']);
    same(['x-visual' => ['layout' => 'columns']], $pack['workflows'][0]['extensions'], 'extension x-visual conservée');
});

test('Format v2 : clés v2 refusées dans un pack v1 (aucune lecture silencieuse)', function () use ($v2) {
    $v = new PackValidator();
    $pack = $v2(['version' => 1, 'mailboxes' => ['select' => 'all']]);
    same(['WP-PACK-12'], codes($v->validate($pack)['problems']));
    $pack = $v2(['version' => 1], ['mailboxes' => ['select' => 'all']]);
    same(['WP-PACK-12'], codes($v->validate($pack)['problems']));
    $pack = $v2(['version' => 1, 'resources' => [['key' => 'a', 'type' => 'user']]]);
    same(['WP-PACK-12'], codes($v->validate($pack)['problems']));
});

test('Format v2 : ressources (type dossier refusé avec explication, clé, doublon, variable non déclarée)', function () use ($v2) {
    $v = new PackValidator();
    $r = $v->validate($v2(['resources' => [['key' => 'security-folder', 'type' => 'folder', 'name' => 'Alertes sécurité']]]));
    same(['WP-RES-06'], codes($r['problems']));
    check(strpos($r['problems']->all()[0]['message'], 'déplacer vers un dossier') !== false, 'explication');
    same(['WP-RES-05'], codes($v->validate($v2(['resources' => [['key' => 'a', 'type' => 'user'], ['key' => 'a', 'type' => 'tag']]]))['problems']));
    same(['WP-RES-04'], codes($v->validate($v2(['resources' => [['key' => 'Bad Key', 'type' => 'user']]]))['problems']));
    $rule = ['actions' => [['type' => 'assign', 'value' => '{{resource.nope}}']]];
    same(['WP-VAR-02'], codes($v->validate($v2([], $rule))['problems']));
    $ok = $v->validate($v2(['resources' => [['key' => 'billing-user', 'type' => 'user']]], ['actions' => [['type' => 'assign', 'value' => '{{resource.billing-user}}']]]));
    check($ok['problems']->isEmpty(), implode("\n", $ok['problems']->lines()));
});

test('Format v2 : {{resource.clé}} remplacé par l\'identifiant local (entier) ; non associée = bloquée', function () use ($v2) {
    $pack = (new PackValidator())->validate($v2(['resources' => [['key' => 'billing-user', 'type' => 'user']]], ['actions' => [
        ['type' => 'assign', 'value' => '{{resource.billing-user}}'],
        ['type' => 'notification', 'value' => ['{{resource.billing-user}}']],
    ]]))['pack'];
    $compiler = new RuleCompiler(require __DIR__.'/fixtures/catalog.php', config_workflows(), ['automatic' => 1, 'manual' => 2], function () {
        return null;
    });
    $vars = VariableResolver::forMailbox(1, 'a@example.com', 'A', [], [])->withResources(['billing-user' => ['value' => 17, 'int' => true]]);
    $r = $compiler->compile($pack['workflows'][0], $vars, 1);
    check($r['problems']->isEmpty(), implode("\n", $r['problems']->lines()));
    same(17, $r['attributes']['actions'][0][0]['value']);
    same([17], $r['attributes']['actions'][0][1]['value']);
    $unmapped = VariableResolver::forMailbox(1, 'a@example.com', 'A', [], [])->withResources(['billing-user' => ['value' => null, 'int' => true]]);
    same(['WP-VAR-03', 'WP-VAR-03'], codes($compiler->compile($pack['workflows'][0], $unmapped, 1)['problems']), 'les deux utilisations sont signalées');
});

test('Aller-retour : v1 et v2 -> modèle -> JSON -> validation, sans perte (sélecteurs, ressources, x-visual)', function () {
    $v = new PackValidator();
    foreach (['tri-emails-multilingue.json', 'demo-portable-v2.json'] as $file) {
        $first = $v->validateJson(file_get_contents(__DIR__.'/../../WorkflowPacks/Resources/packs/'.$file))['pack'];
        $json = json_encode(Importer::template($first), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $second = $v->validateJson($json);
        check($second['problems']->isEmpty(), $file.' : '.implode("\n", $second['problems']->lines()));
        same($first, $second['pack'], $file);
    }
    $v1 = json_decode(json_encode(Importer::template($v->validateJson(file_get_contents(__DIR__.'/../../WorkflowPacks/Resources/packs/tri-emails-multilingue.json'))['pack'])), true);
    same(1, $v1['version'], 'un pack v1 reste v1');
    check(!isset($v1['mailboxes']) && !isset($v1['resources']), 'aucune clé v2 ajoutée à un pack v1');
});
