<?php

/*
 * Unit tests of the parts of WorkflowPacks that do not need FreeScout (delays, e-mail folder names, translations).
 * Plain PHP >= 7.1, no Composer:
 *
 *   php tests/unit/run.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
mb_internal_encoding('UTF-8');

spl_autoload_register(function ($class) {
    $prefix = 'Modules\\WorkflowPacks\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__.'/../../WorkflowPacks/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$GLOBALS['wp_tests'] = [];
$GLOBALS['wp_assertions'] = 0;

function test($name, callable $fn)
{
    $GLOBALS['wp_tests'][$name] = $fn;
}

function check($condition, $message)
{
    $GLOBALS['wp_assertions']++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same($expected, $actual, $message = '')
{
    check($expected === $actual, ($message !== '' ? $message.' — ' : '').'attendu '.var_export($expected, true).', obtenu '.var_export($actual, true));
}

foreach (glob(__DIR__.'/*Test.php') as $file) {
    require $file;
}

$failed = 0;
foreach ($GLOBALS['wp_tests'] as $name => $fn) {
    try {
        $fn();
        echo "  PASS  $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name\n        ".$e->getMessage()."\n";
    }
}
echo "\n".count($GLOBALS['wp_tests']).' tests, '.$GLOBALS['wp_assertions'].' assertions, '.$failed." échec(s)\n";
exit($failed ? 1 : 0);
