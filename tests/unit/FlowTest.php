<?php

use Modules\WorkflowPacks\Services\Flow\ConditionEvaluator;
use Modules\WorkflowPacks\Services\Flow\FlowBuilder;
use Modules\WorkflowPacks\Services\Flow\FlowLabels;
use Modules\WorkflowPacks\Services\Flow\Simulator;

function flow_labels()
{
    $catalog = require __DIR__.'/fixtures/catalog.php';

    return new FlowLabels($catalog, function ($key, array $params) {
        return $key.($params ? ':'.json_encode($params, JSON_UNESCAPED_UNICODE) : '');
    }, function ($id) {
        return 'user#'.$id;
    }, function ($id) {
        return 'mailbox#'.$id;
    });
}

/** Rules of the example: "invoice" mails filed after 1 hour, closed after 2 days; unassigned ones after 3 days. */
function flow_rules()
{
    $base = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'facture']]];
    $delay = function ($number, $metric) {
        return [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => $number, 'metric' => $metric]]];
    };

    return [
        ['id' => 10, 'name' => 'Clôture', 'active' => true, 'conditions' => array_merge($base, [$delay(2, 'd')]), 'actions' => [['type' => 'status', 'value' => '3']]],
        ['id' => 11, 'name' => 'Classement', 'active' => false, 'conditions' => array_merge($base, [$delay(1, 'h')]), 'actions' => [['type' => 'wp_imap_folder', 'value' => 'Factures']]],
        ['id' => 12, 'name' => 'Note', 'active' => true, 'conditions' => array_merge($base, [[['type' => 'new_or_reply', 'operator' => 'new']]]), 'actions' => [['type' => 'note', 'value' => ['body' => 'Reçue']]]],
        ['id' => 13, 'name' => 'Non assignées', 'active' => true, 'conditions' => array_merge($base, [[['type' => 'wp_assigned', 'operator' => 'no']], $delay(3, 'd')]),
            'actions' => [['type' => 'assign', 'value' => '5']]],
        ['id' => 14, 'name' => 'Autre', 'active' => true, 'conditions' => [[['type' => 'subject', 'operator' => 'contains', 'value' => 'devis'], ['type' => 'subject', 'operator' => 'contains', 'value' => 'offre']]],
            'actions' => [['type' => 'forward', 'value' => ['to' => 'ventes@example.test', 'body' => 'x']]]],
    ];
}

test('Flux : règles aux mêmes conditions = un flux, délais = blocs ordonnés, branche = voie', function () {
    $flows = (new FlowBuilder(flow_labels()))->build(4, flow_rules());
    same(2, count($flows), 'facture / devis-offre');
    $lanes = $flows[0]['lanes'];
    same(2, count($lanes), 'sans branche + « non assigné »');
    same(null, $lanes[0]['key']);
    same([12, 11, 10], array_column($lanes[0]['steps'], 'workflow_id'), 'arrivée, +1 h, +2 j');
    same(null, $lanes[0]['steps'][0]['delay']);
    same(3600, $lanes[0]['steps'][1]['delay']['seconds']);
    same('ft:11', $lanes[0]['steps'][1]['delay']['key']);
    same('folder', $lanes[0]['steps'][1]['actions'][0]['kind']);
    same('status-closed', $lanes[0]['steps'][2]['actions'][0]['kind']);
    same('Factures', $lanes[0]['steps'][1]['actions'][0]['label']);
    check(strpos($lanes[1]['key'], 'fb:4:') === 0 && strpos($lanes[1]['label'], 'wp_assigned') !== false, 'voie de la branche');
    same([13], array_column($lanes[1]['steps'], 'workflow_id'));
    check(strpos($flows[1]['conditions'][0], 'flow_or') !== false, 'voie OU sur une ligne');
    same([['key' => $flows[0]['key'], 'family' => 'text']], array_map(function ($b) { return ['key' => $b['key'], 'family' => $b['family']]; }, $flows[0]['blocks']));
    same('text', $flows[1]['blocks'][0]['family'], 'un bloc pour la voie OU');

    // one block per condition: subject, status of the ticket, assignee
    $many = (new FlowBuilder(flow_labels()))->build(4, [['id' => 7, 'name' => 'x', 'active' => true, 'actions' => [], 'conditions' => [
        [['type' => 'subject', 'operator' => 'contains', 'value' => 'a']], [['type' => 'status', 'operator' => 'equal', 'value' => '2']],
        [['type' => 'user', 'operator' => 'equal', 'value' => '5']], [['type' => 'customer_email', 'operator' => 'contains', 'value' => 'b']]]]]);
    same(['text', 'status-pending', 'person', 'customer'], array_column($many[0]['blocks'], 'family'));
    $key = $many[0]['key'];
    same(['mb:4', $key, $key.':1', $key.':2', $key.':3'], FlowBuilder::paths(4, $many)[7], 'the simulation lights every condition block');

    $paths = FlowBuilder::paths(4, $flows);
    same(['mb:4', $flows[0]['key'], 'ft:11', 'fa:11:0'], $paths[11]);
    same(['mb:4', $flows[0]['key'], $lanes[1]['key'], 'ft:13', 'fa:13:0'], $paths[13]);
    // same conditions in another order: same flow key
    $swapped = (new FlowBuilder(flow_labels()))->build(4, [['id' => 1, 'name' => 'a', 'active' => true, 'actions' => [],
        'conditions' => [[['type' => 'status', 'operator' => 'equal', 'value' => '1']], [['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]]],
        ['id' => 2, 'name' => 'b', 'active' => true, 'actions' => [],
        'conditions' => [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']], [['type' => 'status', 'operator' => 'equal', 'value' => '1']]]]]);
    same(1, count($swapped));
    same('flow_all', (new FlowBuilder(flow_labels()))->build(4, [['id' => 3, 'name' => 'c', 'active' => true, 'actions' => [], 'conditions' => []]])[0]['conditions'][0]);
});

test('Simulation : conditions de texte, statut, assignation, réponse, dates', function () {
    $mail = Simulator::mail(['subject' => 'Votre FACTURE', 'body' => '<p>Bonjour</p>', 'customer_email' => 'client@example.test']);
    $state = Simulator::state($mail);
    $eval = function ($type, $operator, $value = null, $t = 0, $event = true, $s = null) use ($mail, $state) {
        return ConditionEvaluator::evaluate(['type' => $type, 'operator' => $operator, 'value' => $value], $mail, $s ?: $state, $t, $event)['result'];
    };
    same(true, $eval('subject', 'contains', 'facture'), 'minuscules');
    same(false, $eval('subject', 'regex', '/facture/'), 'regex sensible à la casse');
    same(true, $eval('subject', 'regex', '/facture/i'));
    same(false, $eval('subject', 'regex', '/(/'), 'regex invalide : faux');
    same(true, $eval('body', 'equal', 'bonjour'), 'HTML retiré');
    same(true, $eval('subject', 'ends', 'facture'));
    same(true, $eval('status', 'equal', '1'));
    same(true, $eval('user', 'equal', '-1'), 'non assigné');
    same(true, $eval('wp_assigned', 'no'));
    same(false, $eval('wp_replied', 'yes'));
    same(true, $eval('new_or_reply', 'new'));
    same(false, $eval('new_or_reply', 'new', null, 3600, false), 'jamais par le planificateur');
    same(false, $eval('created', 'not_in_last', ['number' => 1, 'metric' => 'h'], 3600));
    same(true, $eval('created', 'not_in_last', ['number' => 1, 'metric' => 'h'], 3660));
    same(false, $eval('user_reply', 'not_in_last', ['number' => 1, 'metric' => 'h'], 9999), 'pas de réponse : pas de date');
    same(null, $eval('custom_field_9', 'equal', 'x'), 'type inconnu : non simulable');
});

test('Simulation : arrivée puis délais, état après chaque règle, rien n’est envoyé', function () {
    $mail = Simulator::mail(['subject' => 'Facture 42', 'customer_email' => 'client@example.test', 'mailbox_id' => 4]);
    $result = (new Simulator())->run(flow_rules(), $mail);
    same([12, 11, 10, 13], array_column($result['events'], 'workflow_id'), 'arrivée, +1 h, +2 j, +3 j');
    same([0, 3660, 172860, 259260], array_column($result['events'], 't'));
    same(['Reçue'], $result['events'][0]['state']['notes']);
    same('Factures', $result['events'][1]['state']['folder']);
    same(['status' => [1, 3]], $result['events'][2]['actions'][0]['changes']);
    same(3, $result['events'][2]['state']['status']);
    same(5, $result['final']['user_id']);
    same(true, $result['processed']);
    same(false, $result['uncertain']);
    same('sim_no_match', $result['skipped'][0]['reason'], 'devis / offre');
    same(14, $result['skipped'][0]['workflow_id']);

    // without the disabled rule
    $active = (new Simulator())->run(flow_rules(), $mail, false);
    same([12, 10, 13], array_column($active['events'], 'workflow_id'));

    // forward: recorded, never sent; a reply stops "not replied"
    $quote = (new Simulator())->run(flow_rules(), Simulator::mail(['subject' => 'Une offre', 'customer_email' => 'c@example.test']));
    same('recorded', $quote['events'][0]['actions'][0]['status']);
    same([['type' => 'forward', 'to' => 'ventes@example.test']], $quote['final']['sent']);
    $replied = (new Simulator())->run([['id' => 1, 'name' => 'Réponse', 'active' => true, 'conditions' => [], 'actions' => [['type' => 'reply', 'value' => ['body' => 'Merci']]]],
        ['id' => 2, 'name' => 'Relance', 'active' => true, 'conditions' => [[['type' => 'wp_replied', 'operator' => 'no']], [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => 1, 'metric' => 'd']]]],
            'actions' => [['type' => 'status', 'value' => '2']]]], Simulator::mail(['subject' => 'x']));
    same([1], array_column($replied['events'], 'workflow_id'), 'répondu : pas de relance');

    // a condition of another module: shown, never guessed
    $unknown = (new Simulator())->run([['id' => 1, 'name' => 'Champ', 'active' => true, 'conditions' => [[['type' => 'custom_field_9', 'operator' => 'equal', 'value' => 'x']]],
        'actions' => [['type' => 'status', 'value' => '3']]]], Simulator::mail([]));
    same(false, $unknown['processed']);
    same(true, $unknown['uncertain']);
    same('sim_uncertain', $unknown['skipped'][0]['reason']);

    // deleted: the next rules do not run
    $deleted = (new Simulator())->run([['id' => 1, 'name' => 'Suppr', 'active' => true, 'conditions' => [], 'actions' => [['type' => 'delete']]],
        ['id' => 2, 'name' => 'Après', 'active' => true, 'conditions' => [], 'actions' => [['type' => 'status', 'value' => '3']]]], Simulator::mail([]));
    same('trash', $deleted['final']['deleted']);
    same('sim_deleted', $deleted['skipped'][0]['reason']);
});

test('Simulation : textes du faux e-mail bornés', function () {
    $mail = Simulator::mail(['subject' => str_repeat('a', 400), 'body' => str_repeat('b', 30000), 'tags' => array_fill(0, 40, 'spam')]);
    same(255, mb_strlen($mail['subject']));
    same(Simulator::MAX_TEXT, mb_strlen($mail['body']));
    same(20, count($mail['tags']));
});

test('Tester une règle : un exemple pour chaque expression régulière du pack d’exemple', function () {
    $pack = json_decode(file_get_contents(__DIR__.'/../../WorkflowPacks/Resources/packs/tri-emails-multilingue.json'), true);
    $count = 0;
    array_walk_recursive($pack, function ($value, $key) use (&$count) {
        if ($key === 'value' && is_string($value) && preg_match('#^/.+/[a-z]*$#s', $value) && strpos($value, '{{') === false) {
            $text = \Modules\WorkflowPacks\Services\Flow\SampleMail::fromRegex($value);
            check($text !== null && preg_match($value, $text) === 1, 'exemple pour '.mb_substr($value, 0, 60));
            $count++;
        }
    });
    check($count >= 7, 'regex du pack trouvées : '.$count);
    same('Facture 11', \Modules\WorkflowPacks\Services\Flow\SampleMail::fromRegex('/^Facture \d{2}$/'));
    same(null, \Modules\WorkflowPacks\Services\Flow\SampleMail::fromRegex('/(/'));
});

test('Tester une règle : le faux e-mail remplit les conditions, ce qui ne peut pas l’être est listé', function () {
    $sample = \Modules\WorkflowPacks\Services\Flow\SampleMail::forRule([
        [['type' => 'new_or_reply', 'operator' => 'new']],
        [['type' => 'subject', 'operator' => 'contains', 'value' => 'facture']],
        [['type' => 'subject', 'operator' => 'starts', 'value' => '[Client]']],
        [['type' => 'customer_email', 'operator' => 'contains', 'value' => '@shop.example.test']],
        [['type' => 'body', 'operator' => 'regex', 'value' => '/commande n°\s?\d+/i'], ['type' => 'body', 'operator' => 'contains', 'value' => 'x']],
        [['type' => 'wp_assigned', 'operator' => 'no']],
        [['type' => 'wp_replied', 'operator' => 'yes']],
        [['type' => 'status', 'operator' => 'equal', 'value' => '2']],
        [['type' => 'wp_mail_tag', 'operator' => 'has', 'value' => 'inbox']],
        [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => 2, 'metric' => 'd']]],
    ], 'support@example.test');
    $in = $sample['input'];
    same('[Client] facture', $in['subject']);
    same('client@shop.example.test', $in['customer_email']);
    same(1, preg_match('/commande n°\s?\d+/i', $in['body']));
    same([false, true, 2, ['inbox']], [$in['assigned'], $in['replied'], $in['status'], $in['tags']]);
    same([], $sample['unmet']);

    // the simulation then applies the rule
    $rule = ['id' => 1, 'name' => 'R', 'active' => true, 'actions' => [['type' => 'status', 'value' => '3']], 'conditions' => [
        [['type' => 'subject', 'operator' => 'regex', 'value' => '/\b(invoice|facture)s?\b/i']], [['type' => 'wp_replied', 'operator' => 'no']]]];
    $made = \Modules\WorkflowPacks\Services\Flow\SampleMail::forRule($rule['conditions']);
    same([1], array_column((new Simulator())->run([$rule], Simulator::mail($made['input']))['events'], 'workflow_id'));

    $hard = \Modules\WorkflowPacks\Services\Flow\SampleMail::forRule([[['type' => 'custom_field_9', 'operator' => 'equal', 'value' => 'x']],
        [['type' => 'new_or_reply', 'operator' => 'reply']], [['type' => 'subject', 'operator' => 'contains', 'value' => 'ok']]]);
    same(2, count($hard['unmet']), 'autre module et « réponse » : signalés');
    same('ok', $hard['input']['subject']);
});
