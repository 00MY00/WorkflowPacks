<?php

namespace Modules\WorkflowPacks\Services\Compat;

use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Checks that the official Workflows module is installed, active and shaped as WorkflowPacks expects, before any
 * import or export. Every check has a code shown in the interface, the console and the logs.
 *
 * State: ok | warning (works, with a remark) | blocking (import and export refused, with the reason).
 */
class Checker
{
    const STATES = ['ok' => 0, 'warning' => 1, 'blocking' => 2];

    protected static $cached = null;

    public static function forget()
    {
        self::$cached = null;
        Bridge::forgetCatalogs();
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
        $add('WP-CORE-01', version_compare($app, '1.8.0', '>=') ? 'ok' : 'warning', 'FreeScout '.$app, Problems::t('chk_core_detail'));
        $add('WP-PHP-01', version_compare(PHP_VERSION, '7.1.0', '>=') ? 'ok' : 'blocking', 'PHP '.PHP_VERSION, Problems::t('chk_php_detail'));

        $tables = \Modules\WorkflowPacks\Services\ModuleInfo::TABLES;
        $missing = array_values(array_filter($tables, function ($t) {
            return !\Schema::hasTable($t);
        }));
        $add('WP-DB-01', $missing ? 'blocking' : 'ok', Problems::t('chk_db'), $missing ? Problems::t('chk_db_missing', ['tables' => implode(', ', $missing)]) : '');

        $alias = (string) Bridge::config('module_alias');
        $active = Bridge::moduleActive($alias);
        $add('WP-WF-01', $active ? 'ok' : 'blocking', Problems::t('chk_wf_active'), $active ? Problems::t('chk_wf_version', ['version' => Bridge::version() ?: Problems::t('chk_unknown')]) : Problems::t('chk_wf_install'));

        $class = Bridge::modelClass();
        $classOk = $active && Bridge::classAvailable();
        if ($active) {
            $add('WP-WF-02', $classOk ? 'ok' : 'blocking', Problems::t('chk_class', ['class' => $class]), $classOk ? '' : Problems::t('chk_class_missing'));
        }

        if ($classOk) {
            $ref = new \ReflectionClass($class);
            $constants = $ref->getConstants();
            $hasTypes = isset($constants['TYPE_AUTOMATIC'], $constants['TYPE_MANUAL']);
            $add('WP-WF-03', $hasTypes ? 'ok' : 'blocking', Problems::t('chk_types'), $hasTypes ? 'TYPE_AUTOMATIC='.$constants['TYPE_AUTOMATIC'].', TYPE_MANUAL='.$constants['TYPE_MANUAL'] : Problems::t('chk_types_missing'));

            $catalogOk = $ref->hasMethod('conditionsConfig') && $ref->getMethod('conditionsConfig')->isStatic()
                && $ref->hasMethod('actionsConfig') && $ref->getMethod('actionsConfig')->isStatic();
            $add('WP-WF-04', $catalogOk ? 'ok' : 'blocking', Problems::t('chk_catalog'), $catalogOk ? 'Workflow::conditionsConfig() / actionsConfig()' : Problems::t('chk_catalog_missing'));

            $perm = $ref->hasMethod('canEditWorkflows');
            $add('WP-WF-05', $perm ? 'ok' : 'warning', Problems::t('chk_perm'), $perm ? 'Workflow::canEditWorkflows()' : Problems::t('chk_perm_missing'));

            $validate = $ref->hasMethod('validate') && $ref->hasMethod('checkComplete');
            $add('WP-WF-06', $validate ? 'ok' : 'warning', Problems::t('chk_validate'), $validate ? 'Workflow::validate() / checkComplete()' : Problems::t('chk_validate_missing'));

            $tableOk = false;
            try {
                $model = Bridge::newModel();
                $table = $model->getTable();
                $tableOk = \Schema::hasTable($table);
                $add('WP-WF-07', $tableOk ? 'ok' : 'blocking', Problems::t('chk_table', ['table' => $table]), $tableOk ? '' : Problems::t('chk_table_missing'));
                if ($tableOk) {
                    $columns = \Schema::getColumnListing($table);
                    $need = array_diff((array) Bridge::config('columns'), $columns);
                    $add('WP-WF-08', $need ? 'blocking' : 'ok', Problems::t('chk_columns'), $need ? Problems::t('chk_columns_missing', ['columns' => implode(', ', $need)]) : implode(', ', array_intersect($columns, array_merge((array) Bridge::config('columns'), (array) Bridge::config('optional_columns')))));
                    $casts = $model->getCasts();
                    $castOk = in_array($casts['conditions'] ?? '', ['array', 'json'], true) && in_array($casts['actions'] ?? '', ['array', 'json'], true);
                    $add('WP-WF-09', $castOk ? 'ok' : 'blocking', Problems::t('chk_storage'), $castOk ? Problems::t('chk_storage_ok') : Problems::t('chk_storage_bad'));
                    if (!$need && $castOk) {
                        $sample = self::sampleFormat();
                        $add('WP-WF-10', $sample === '' ? 'ok' : 'blocking', Problems::t('chk_sample'), $sample === '' ? Problems::t('chk_sample_ok') : $sample);
                    }
                }
            } catch (\Throwable $e) {
                $add('WP-WF-07', 'blocking', Problems::t('chk_model'), Problems::t('chk_error', ['error' => $e->getMessage()]));
            }
        }

        $tags = Bridge::moduleActive('tags');
        $add('WP-TAGS-01', 'ok', Problems::t('chk_tags'), $tags ? Problems::t('chk_tags_on') : Problems::t('chk_tags_off'));
        $refresh = self::refreshActive();
        $add('WP-REF-01', 'ok', Problems::t('chk_refresh'), $refresh ? Problems::t('chk_refresh_on') : Problems::t('chk_refresh_off'));

        $state = 'ok';
        foreach ($checks as $check) {
            if (self::STATES[$check['state']] > self::STATES[$state]) {
                $state = $check['state'];
            }
        }
        foreach ($checks as $check) {
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
                'workflows'     => $classOk ? Bridge::version() : '',
                'workflowpacks' => \Modules\WorkflowPacks\Services\ModuleInfo::version(),
            ],
        ];
    }

    public static function blocking()
    {
        return self::run()['state'] === 'blocking';
    }

    /** Lines describing the blocking checks (shown instead of the import / export forms). */
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

    /** Checks that existing workflows are stored as expected (lists of lists of {type, operator}); '' when fine. */
    protected static function sampleFormat()
    {
        $rows = Bridge::query()->orderBy('id', 'desc')->limit(50)->get();
        foreach ($rows as $row) {
            $attrs = Bridge::attributesOf($row);
            foreach (['conditions', 'actions'] as $field) {
                foreach ((array) $attrs[$field] as $group) {
                    if (!is_array($group)) {
                        return Problems::t('chk_sample_groups', ['id' => $row->id, 'field' => $field]);
                    }
                    foreach ($group as $item) {
                        if (!is_array($item) || !array_key_exists('type', $item)) {
                            return Problems::t('chk_sample_type', ['id' => $row->id, 'field' => $field]);
                        }
                    }
                }
            }
        }

        return '';
    }

    /** The Refresh module (altmenorg/freescout-refresh) is active: pages use its look. */
    public static function refreshActive()
    {
        try {
            return \App\Module::isActive('refresh');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
