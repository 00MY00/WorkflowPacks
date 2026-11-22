<?php

namespace Modules\WorkflowPacks\Services\Compat;

use Modules\WorkflowPacks\Services\Support\Texts;

/**
 * State of the module on this instance: FreeScout and PHP versions, tables of the module, and the optional modules it
 * works with (RefreshGlobal: e-mail tags; WebPush: push notifications; Refresh: look; Tags). Every check has a code
 * shown in the diagnostic, the console and the logs. No other module is required.
 *
 * State: ok | warning (works, with a remark) | blocking (the module cannot work: the reason is shown).
 */
class Checker
{
    const STATES = ['ok' => 0, 'warning' => 1, 'blocking' => 2];

    protected static $cached = null;

    public static function forget()
    {
        self::$cached = null;
    }

    /** @return array ['state' => …, 'checks' => [[code, state, label, detail]], 'versions' => […]] */
    public static function run($fresh = false)
    {
        if (self::$cached !== null && !$fresh) {
            return self::$cached;
        }
        $checks = [];
        $add = function ($code, $state, $label, $detail = '') use (&$checks) {
            $checks[] = ['code' => $code, 'state' => $state, 'label' => $label, 'detail' => $detail];
        };

        $app = (string) config('app.version');
        $add('WP-CORE-01', version_compare($app, '1.8.0', '>=') ? 'ok' : 'warning', 'FreeScout '.$app, Texts::t('chk_core_detail'));
        $add('WP-PHP-01', version_compare(PHP_VERSION, '7.1.0', '>=') ? 'ok' : 'blocking', 'PHP '.PHP_VERSION, Texts::t('chk_php_detail'));

        $missing = array_values(array_filter(\Modules\WorkflowPacks\Services\ModuleInfo::TABLES, function ($t) {
            return !\Schema::hasTable($t);
        }));
        $add('WP-DB-01', $missing ? 'blocking' : 'ok', Texts::t('chk_db'), $missing ? Texts::t('chk_db_missing', ['tables' => implode(', ', $missing)]) : '');

        // optional modules: remarks only
        foreach (['refreshglobal' => 'WP-RG-01', 'webpush' => 'WP-PUSH-01', 'tags' => 'WP-TAGS-01', 'refresh' => 'WP-REF-01'] as $alias => $code) {
            $on = self::active($alias);
            $add($code, 'ok', Texts::t('chk_'.$alias), Texts::t($on ? 'chk_module_on' : 'chk_module_off'));
        }

        $state = 'ok';
        foreach ($checks as $check) {
            if (self::STATES[$check['state']] > self::STATES[$state]) {
                $state = $check['state'];
            }
            if ($check['state'] === 'blocking') {
                \Log::warning('[WorkflowPacks] ['.$check['code'].'] '.$check['label'].' : '.$check['detail']);
            }
        }

        return self::$cached = [
            'state'    => $state,
            'checks'   => $checks,
            'versions' => [
                'freescout'     => $app,
                'php'           => PHP_VERSION,
                'workflowpacks' => \Modules\WorkflowPacks\Services\ModuleInfo::version(),
            ],
        ];
    }

    public static function blocking()
    {
        return self::run()['state'] === 'blocking';
    }

    /** Lines describing the blocking checks. */
    public static function blockingReasons()
    {
        $out = [];
        foreach (self::run()['checks'] as $check) {
            if ($check['state'] === 'blocking') {
                $out[] = '['.$check['code'].'] '.$check['label'].' — '.$check['detail'];
            }
        }

        return $out;
    }

    /** The Refresh module (altmenorg/freescout-refresh) is active: pages use its look. */
    public static function refreshActive()
    {
        return self::active('refresh');
    }

    protected static function active($alias)
    {
        try {
            return (bool) \App\Module::isActive($alias);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
