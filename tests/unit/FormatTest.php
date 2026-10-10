<?php

use Modules\WorkflowPacks\Services\Pack\ConditionTranslator as T;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;

$s = function ($v) {
    return ['type' => 'subject', 'operator' => 'contains', 'value' => $v];
};

// ------------------------------------------------------------------------------------------ AND / OR translation

test('ET/OU : all de conditions et de groupes any -> groupes ET de conditions OU', function () use ($s) {
    $p = new Problems();
    $native = T::toNative(['all' => [$s('a'), ['any' => [$s('b'), $s('c')]], $s('d')]], 'c', $p);
    same([[$s('a')], [$s('b'), $s('c')], [$s('d')]], $native);
    check($p->isEmpty(), 'aucune erreur');
});

test('ET/OU : any seul -> un seul groupe', function () use ($s) {
    $p = new Problems();
    same([[$s('a'), $s('b')]], T::toNative(['any' => [$s('a'), $s('b')]], 'c', $p));
});

test('ET/OU : un ET dans un OU est refusé avec explication (non représentable)', function () use ($s) {
    $p = new Problems();
    same(null, T::toNative(['any' => [$s('a'), ['all' => [$s('b'), $s('c')]]]], 'c', $p));
    same(['WP-COND-05'], codes($p));
    check(strpos($p->all()[0]['message'], 'ET de groupes OU') !== false, 'message explicite');
});

test('ET/OU : all dans all, groupe vide, clé inconnue, objet à deux clés refusés', function () use ($s) {
    foreach ([
        'WP-COND-04' => ['all' => [['all' => [$s('a')]]]],
        'WP-COND-02' => ['all' => [['any' => []]]],
        'WP-COND-07' => ['all' => [['type' => 'subject', 'operator' => 'contains', 'value' => 'x', 'negate' => true]]],
        'WP-COND-01' => ['all' => [$s('a')], 'any' => [$s('b')]],
    ] as $code => $tree) {
        $p = new Problems();
        same(null, T::toNative($tree, 'c', $p), $code);
        same($code, codes($p)[0]);
    }
});

test('ET/OU : retour du format Workflows vers le pack (export) sans changer la logique', function () use ($s) {
    $native = [[$s('a')], [$s('b'), $s('c')]];
    $tree = T::fromNative($native);
    same(['all' => [$s('a'), ['any' => [$s('b'), $s('c')]]]], $tree);
    $p = new Problems();
    same($native, T::toNative($tree, 'c', $p));
    same(['any' => [$s('a'), $s('b')]], T::fromNative([[$s('a'), $s('b')]]));
});

// ------------------------------------------------------------------------------------------------- variables

test('Variables : {{mailbox.id}} seul garde le type entier, inclus dans un texte devient du texte', function () {
    $r = VariableResolver::forMailbox(7, 'support@example.test', 'Support', [], []);
    $p = new Problems();
    same(7, $r->resolveString('{{mailbox.id}}', 'text', 'x', $p));
    same(7, $r->resolveString('{{ mailbox.id }}', 'text', 'x', $p));
    same('Boîte 7', $r->resolveString('Boîte {{mailbox.id}}', 'text', 'x', $p));
    check($p->isEmpty(), 'aucune erreur');
});

test('Variables : dans une regex l\'adresse est insérée comme texte littéral', function () {
    $r = VariableResolver::forMailbox(1, 'a.b+c@example.test', 'X', [], []);
    $p = new Problems();
    $regex = $r->resolveString('/^{{mailbox.email}}$/i', 'regex', 'x', $p);
    same('/^a\.b\+c@example\.test$/i', $regex);
    same(1, preg_match($regex, 'A.B+C@example.test'));
    same(0, preg_match($regex, 'aXb+c@example.test'), 'le point ne doit plus être un joker');
    $hash = $r->resolveString('#{{mailbox.email}}#', 'regex', 'x', $p);
    same('#a\.b\+c@example\.test#', $hash);
});

test('Variables : échappement HTML dans les corps de note / réponse', function () {
    $r = VariableResolver::forMailbox(1, 'x@example.test', 'R&D <Support>', [], []);
    $p = new Problems();
    same('Boîte R&amp;D &lt;Support&gt;', $r->resolveString('Boîte {{mailbox.name}}', 'html', 'x', $p));
});

test('Variables : aucune expression évaluée, variable inconnue ou manquante refusée', function () {
    $params = [['key' => 'notify', 'type' => 'email']];
    $r = VariableResolver::forMailbox(1, 'x@example.test', 'X', $params, ['notify' => null]);
    foreach ([
        '{{ 1+1 }}'                  => 'WP-VAR-01',
        '{{mailbox.email|upper}}'    => 'WP-VAR-01',
        '{{ system("id") }}'         => 'WP-VAR-01',
        '<?php echo 1; ?> {{'        => 'WP-VAR-01',
        '{{mailbox.password}}'       => 'WP-VAR-02',
        '{{param.notify}}'           => 'WP-VAR-03',
    ] as $value => $code) {
        $p = new Problems();
        $r->resolveString($value, 'text', 'x', $p);
        same($code, codes($p)[0] ?? '', $value);
    }
    $p = new Problems();
    $r->resolveString('{{mailbox.email}}/', 'regex', 'x', $p);
    same(['WP-VAR-04'], codes($p), 'délimiteur de regex en variable');
});

test('Variables : paramètres du pack, types entier conservés', function () {
    $params = [['key' => 'agent', 'type' => 'user'], ['key' => 'mail', 'type' => 'email']];
    $r = VariableResolver::forMailbox(1, 'x@example.test', 'X', $params, ['agent' => '12', 'mail' => 'n@example.test']);
    $p = new Problems();
    same(12, $r->resolveString('{{param.agent}}', 'text', 'x', $p));
    same('n@example.test', $r->resolveString('{{param.mail}}', 'text', 'x', $p));
});

// ------------------------------------------------------------------------------------------------- validator

$validator = function () {
    return new PackValidator(['file_kb' => 64]);
};
$minimal = function (array $rule = []) {
    return [
        'format' => 'freescout-workflow-pack', 'version' => 1, 'key' => 'demo', 'name' => 'Démo',
        'workflows' => [$rule + ['key' => 'r1', 'name' => 'R1', 'type' => 'automatic',
            'conditions' => ['all' => [['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]],
            'actions' => [['type' => 'status', 'value' => '3']]]],
    ];
};

test('Validation : le pack d\'exemple est valide', function () use ($validator) {
    $json = file_get_contents(__DIR__.'/../../WorkflowPacks/Resources/packs/tri-emails-multilingue.json');
    $r = (new PackValidator())->validateJson($json);
    check($r['problems']->isEmpty(), implode("\n", $r['problems']->lines()));
    same(8, count($r['pack']['workflows']));
});

test('Validation : format, version de schéma, JSON, taille', function () use ($validator, $minimal) {
    $v = $validator();
    same(['WP-FILE-03'], codes($v->validateJson('{oops')['problems']));
    same(['WP-FILE-02'], codes($v->validateJson(str_repeat(' ', 70 * 1024))['problems']));
    $pack = $minimal();
    $pack['format'] = 'autre';
    same(['WP-PACK-03'], codes($v->validate($pack)['problems']));
    $pack = $minimal();
    $pack['version'] = 2;
    same(['WP-PACK-05'], codes($v->validate($pack)['problems']));
    $deep = str_repeat('[', 40).str_repeat(']', 40);
    same(['WP-FILE-03'], codes($v->validateJson($deep)['problems']), 'profondeur JSON limitée');
});

test('Validation : clés inconnues, doublons, regex et variables refusés', function () use ($validator, $minimal) {
    $v = $validator();
    $pack = $minimal(['enabled' => true]);
    same(['WP-RULE-03'], codes($v->validate($pack)['problems']));

    $pack = $minimal();
    $pack['workflows'][] = $pack['workflows'][0];
    same(['WP-RULE-02'], codes($v->validate($pack)['problems']));

    $pack = $minimal(['conditions' => ['all' => [['type' => 'subject', 'operator' => 'regex', 'value' => 'facture']]]]);
    same(['WP-REGEX-01'], codes($v->validate($pack)['problems']), 'regex sans délimiteur');

    $pack = $minimal(['conditions' => ['all' => [['type' => 'subject', 'operator' => 'regex', 'value' => '/(facture/i']]]]);
    same(['WP-REGEX-01'], codes($v->validate($pack)['problems']), 'regex invalide');

    $pack = $minimal(['name' => 'R {{param.absent}}']);
    same(['WP-VAR-02'], codes($v->validate($pack)['problems']), 'paramètre non déclaré');

    $pack = $minimal(['conditions' => null]);
    same(['WP-RULE-08'], codes($v->validate($pack)['problems']), 'règle automatique sans condition');

    $pack = $minimal(['type' => 'manual', 'conditions' => null]);
    check($v->validate($pack)['problems']->isEmpty(), 'règle manuelle sans condition acceptée');

    $pack = $minimal(['settings' => ['max_executions' => 0]]);
    same(['WP-RULE-07'], codes($v->validate($pack)['problems']));
});

test('Validation : paramètres (type, défaut, référence locale sans défaut)', function () use ($validator, $minimal) {
    $v = $validator();
    $pack = $minimal();
    $pack['params'] = [
        ['key' => 'mail', 'type' => 'email', 'default' => 'pas-une-adresse'],
        ['key' => 'agent', 'type' => 'user', 'default' => 3],
        ['key' => 'x', 'type' => 'php'],
    ];
    same(['WP-PARAM-08', 'WP-PARAM-09', 'WP-PARAM-06'], codes($v->validate($pack)['problems']));
});
