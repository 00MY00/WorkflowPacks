<?php

use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\RuleCompiler;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;

/*
 * Which rules of the example pack match a new message. The evaluation follows the semantics documented in
 * docs/analyse.md for Workflow::checkConditions(): every AND group must have one true OR condition; texts are
 * compared in lower case except "regex", which is preg_match($pattern, $text) as written in the pack.
 */
function wp_matching_rules(array $message)
{
    static $compiled = null;
    if ($compiled === null) {
        $pack = (new PackValidator())->validateJson(file_get_contents(__DIR__.'/../../WorkflowPacks/Resources/packs/tri-emails-multilingue.json'))['pack'];
        $vars = VariableResolver::forMailbox(1, 'support@example.test', 'Support', $pack['params'],
            ['security_email' => 'secu@example.test', 'newsletter_sender' => 'news@shop.example.test']);
        $compiler = new RuleCompiler(require __DIR__.'/fixtures/catalog.php', config_workflows(), ['automatic' => 1, 'manual' => 2], function () {
            return null;
        });
        $compiled = [];
        foreach ($pack['workflows'] as $rule) {
            $compiled[$rule['key']] = $compiler->compile($rule, $vars, 1)['attributes'];
        }
    }
    $matching = [];
    foreach ($compiled as $key => $attributes) {
        $all = true;
        foreach ($attributes['conditions'] as $group) {
            $any = false;
            foreach ($group as $c) {
                $value = $c['value'] ?? null;
                switch ($c['type']) {
                    case 'new_or_reply':
                        $any = $c['operator'] === 'new' && $message['new'];
                        break;
                    case 'subject':
                    case 'customer_email':
                        $text = $c['type'] === 'subject' ? $message['subject'] : $message['from'];
                        if ($c['operator'] === 'regex') {
                            $any = (bool) preg_match($value, $text);
                        } elseif ($c['operator'] === 'equal') {
                            $any = mb_strtolower($text) === mb_strtolower($value);
                        } elseif ($c['operator'] === 'contains') {
                            $any = mb_strpos(mb_strtolower($text), mb_strtolower($value)) !== false;
                        }
                        break;
                }
                if ($any) {
                    break;
                }
            }
            if (!$any) {
                $all = false;
                break;
            }
        }
        if ($all) {
            $matching[] = $key;
        }
    }

    return $matching;
}

function wp_closes(array $rules)
{
    return (bool) array_intersect($rules, ['newsletters', 'tests-freescout']);
}

test('Pack d\'exemple : les 6 sujets de l\'énoncé sont reconnus, chacun par une seule règle', function () {
    $cases = [
        'Security alert'                                                             => ['alertes-securite'],
        '2-Step Verification turned on'                                              => ['alertes-securite'],
        'Google Verification Code'                                                   => ['codes-connexion'],
        'Чек + (1) подарок. 1 384,61 ₽'                                              => ['factures-recus'],
        '[Action Advised] Review Google Calendar events scheduled after Nov 1, 2026' => ['action-requise'],
        'FreeScout Email de test'                                                    => ['tests-freescout'],
    ];
    foreach ($cases as $subject => $expected) {
        same($expected, wp_matching_rules(['subject' => $subject, 'from' => 'noreply@sender.example.test', 'new' => true]), $subject);
    }
});

test('Pack d\'exemple : le reçu russe reste un document comptable et n\'est pas fermé', function () {
    $rules = wp_matching_rules(['subject' => 'Чек + (1) подарок. 1 384,61 ₽', 'from' => 'noreply@shop.example.test', 'new' => true]);
    same(['factures-recus'], $rules);
    check(!wp_closes($rules), 'jamais fermé');
    // narrow no-break space as thousands separator
    same(['factures-recus'], wp_matching_rules(['subject' => "Чек 1\u{202F}384,61 ₽", 'from' => 'x@example.test', 'new' => true]));
});

test('Pack d\'exemple : noreply, « cadeau » ou désabonnement ne suffisent pas à fermer', function () {
    foreach ([
        ['Un cadeau pour vous : -50 % ce week-end', 'noreply@promo.example.test'],
        ['Newsletter de septembre — se désabonner en bas de page', 'no-reply@blog.example.test'],
        ['Votre cadeau vous attend', 'news@other.example.test'],
    ] as $m) {
        same([], wp_matching_rules(['subject' => $m[0], 'from' => $m[1], 'new' => true]), $m[0]);
    }
    // only the sender entered at import is closed
    $rules = wp_matching_rules(['subject' => 'Les nouveautés du mois', 'from' => 'NEWS@shop.example.test', 'new' => true]);
    same(['newsletters'], $rules);
    check(wp_closes($rules), 'expéditeur explicitement renseigné');
});

test('Pack d\'exemple : autres catégories et faux positifs évités', function () {
    $cases = [
        'Payment failed for your subscription'          => ['echecs-paiement'],
        'Échec de paiement de votre abonnement'         => ['echecs-paiement'],
        'Server down: api.example.test'                 => ['alertes-techniques'],
        'Backup failed on db-01'                        => ['alertes-techniques'],
        'Votre facture n° 2026-118'                     => ['factures-recus'],
        'Your receipt from Example Store'               => ['factures-recus'],
        'Your recurring donation is confirmed'          => [],
        'Download your files'                           => [],
        'Réunion de mardi'                              => [],
    ];
    foreach ($cases as $subject => $expected) {
        same($expected, wp_matching_rules(['subject' => $subject, 'from' => 'x@example.test', 'new' => true]), $subject);
    }
});

test('Pack d\'exemple : seules les nouvelles conversations sont classées (pas les réponses)', function () {
    same([], wp_matching_rules(['subject' => 'Security alert', 'from' => 'x@example.test', 'new' => false]));
});
