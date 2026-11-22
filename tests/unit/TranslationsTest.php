<?php

use Modules\WorkflowPacks\Services\Support\Texts;

/*
 * Every language has the same keys and the same :placeholders as the French reference (texts.php) and as English
 * (messages.php). A missing key would show a raw identifier, a missing placeholder would hide a value.
 */
function wp_lang_files($file)
{
    $out = [];
    foreach (glob(__DIR__.'/../../WorkflowPacks/Resources/lang/*/'.$file) as $path) {
        $out[basename(dirname($path))] = require $path;
    }

    return $out;
}

function wp_placeholders($text)
{
    preg_match_all('/:([a-z_]+)/', (string) $text, $m);
    $names = array_unique($m[1]);
    sort($names);

    return $names;
}

/** A file of texts flattened: "condition.ticket.title" => text. */
function wp_flat(array $texts, $prefix = '')
{
    $out = [];
    foreach ($texts as $key => $value) {
        if (is_array($value)) {
            $out += wp_flat($value, $prefix.$key.'.');
        } else {
            $out[$prefix.$key] = $value;
        }
    }

    return $out;
}

// every file of texts (the reference: French for texts.php, written first; English elsewhere)
$wp_files = array_map('basename', glob(__DIR__.'/../../WorkflowPacks/Resources/lang/en/*.php'));
foreach ($wp_files as $file) {
    $reference = $file === 'texts.php' ? 'fr' : 'en';
    test('Traductions : '.$file.' complet dans les 7 langues (mêmes clés, mêmes paramètres)', function () use ($file, $reference) {
        $all = array_map('wp_flat', wp_lang_files($file));
        same(['de', 'en', 'es', 'fr', 'it', 'nl', 'pt-BR'], array_keys($all), 'langues');
        $ref = $all[$reference];
        foreach ($all as $locale => $strings) {
            same([], array_values(array_diff(array_keys($ref), array_keys($strings))), $locale.' : clés manquantes');
            same([], array_values(array_diff(array_keys($strings), array_keys($ref))), $locale.' : clés en trop');
            foreach ($ref as $key => $text) {
                same(wp_placeholders($text), wp_placeholders($strings[$key]), $locale.'.'.$key.' : paramètres');
            }
        }
    });
}

test('Traductions : remplacement des paramètres et traducteur branché', function () {
    same('manquantes : a, b — lancez php artisan migrate', Texts::t('chk_db_missing', ['tables' => 'a, b']));
    $en = require __DIR__.'/../../WorkflowPacks/Resources/lang/en/texts.php';
    Texts::$translator = function ($key, array $params) use ($en) {
        return isset($en[$key]) ? Texts::replace($en[$key], $params) : null;
    };
    same(str_replace(':tables', 'a, b', $en['chk_db_missing']), Texts::t('chk_db_missing', ['tables' => 'a, b']));
    Texts::$translator = null;
});
