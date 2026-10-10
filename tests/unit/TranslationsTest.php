<?php

use Modules\WorkflowPacks\Services\Pack\Problems;

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

foreach (['texts.php' => 'fr', 'messages.php' => 'en'] as $file => $reference) {
    test('Traductions : '.$file.' complet dans les 7 langues (mêmes clés, mêmes paramètres)', function () use ($file, $reference) {
        $all = wp_lang_files($file);
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
    same('trop de règles (3, maximum 2).', Problems::t('pack_rules_too_many', ['count' => 3, 'max' => 2]));
    same('variable inconnue {{param.x}}.', Problems::t('var_unknown', ['var' => 'param.x']));
    $en = require __DIR__.'/../../WorkflowPacks/Resources/lang/en/texts.php';
    Problems::$translator = function ($key, array $params) use ($en) {
        return isset($en[$key]) ? Problems::replace($en[$key], $params) : null;
    };
    same('too many rules (3, maximum 2).', Problems::t('pack_rules_too_many', ['count' => 3, 'max' => 2]));
    Problems::$translator = null;
});
