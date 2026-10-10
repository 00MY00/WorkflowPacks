<?php

namespace Modules\WorkflowPacks\Services\Compat;

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
        $add('WP-CORE-01', version_compare($app, '1.8.0', '>=') ? 'ok' : 'warning', 'FreeScout '.$app, 'version minimale testée : 1.8.0');
        $add('WP-PHP-01', version_compare(PHP_VERSION, '7.1.0', '>=') ? 'ok' : 'blocking', 'PHP '.PHP_VERSION, 'minimum de FreeScout : 7.1');

        $tables = ['workflowpacks_packs', 'workflowpacks_links', 'workflowpacks_imports'];
        $missing = array_values(array_filter($tables, function ($t) {
            return !\Schema::hasTable($t);
        }));
        $add('WP-DB-01', $missing ? 'blocking' : 'ok', 'Tables de Workflow Packs', $missing ? 'manquantes : '.implode(', ', $missing).' — lancez php artisan migrate' : '');

        $alias = (string) Bridge::config('module_alias');
        $active = Bridge::moduleActive($alias);
        $add('WP-WF-01', $active ? 'ok' : 'blocking', 'Module Workflows actif', $active ? 'version '.(Bridge::version() ?: 'inconnue') : 'installez et activez le module officiel Workflows (Gérer › Modules)');

        $class = Bridge::modelClass();
        $classOk = $active && Bridge::classAvailable();
        if ($active) {
            $add('WP-WF-02', $classOk ? 'ok' : 'blocking', 'Classe '.$class, $classOk ? '' : 'introuvable : fichiers du module absents ou version incompatible');
        }

        if ($classOk) {
            $ref = new \ReflectionClass($class);
            $constants = $ref->getConstants();
            $hasTypes = isset($constants['TYPE_AUTOMATIC'], $constants['TYPE_MANUAL']);
            $add('WP-WF-03', $hasTypes ? 'ok' : 'blocking', 'Types automatique / manuel', $hasTypes ? 'TYPE_AUTOMATIC='.$constants['TYPE_AUTOMATIC'].', TYPE_MANUAL='.$constants['TYPE_MANUAL'] : 'constantes TYPE_AUTOMATIC / TYPE_MANUAL absentes');

            $catalogOk = $ref->hasMethod('conditionsConfig') && $ref->getMethod('conditionsConfig')->isStatic()
                && $ref->hasMethod('actionsConfig') && $ref->getMethod('actionsConfig')->isStatic();
            $add('WP-WF-04', $catalogOk ? 'ok' : 'blocking', 'Catalogue des conditions et actions', $catalogOk ? 'Workflow::conditionsConfig() / actionsConfig()' : 'méthodes absentes : impossible de vérifier les types utilisés');

            $perm = $ref->hasMethod('canEditWorkflows');
            $add('WP-WF-05', $perm ? 'ok' : 'warning', 'Permission « gérer les workflows »', $perm ? 'Workflow::canEditWorkflows()' : 'méthode absente : import réservé aux administrateurs');

            $validate = $ref->hasMethod('validate') && $ref->hasMethod('checkComplete');
            $add('WP-WF-06', $validate ? 'ok' : 'warning', 'Validation officielle des règles', $validate ? 'Workflow::validate() / checkComplete()' : 'méthodes absentes : seule la validation de Workflow Packs est appliquée');

            $tableOk = false;
            try {
                $model = Bridge::newModel();
                $table = $model->getTable();
                $tableOk = \Schema::hasTable($table);
                $add('WP-WF-07', $tableOk ? 'ok' : 'blocking', 'Table '.$table, $tableOk ? '' : 'absente : migrations du module Workflows non exécutées');
                if ($tableOk) {
                    $columns = \Schema::getColumnListing($table);
                    $need = array_diff((array) Bridge::config('columns'), $columns);
                    $add('WP-WF-08', $need ? 'blocking' : 'ok', 'Colonnes de la table', $need ? 'manquantes : '.implode(', ', $need) : implode(', ', array_intersect($columns, array_merge((array) Bridge::config('columns'), (array) Bridge::config('optional_columns')))));
                    $casts = $model->getCasts();
                    $castOk = in_array($casts['conditions'] ?? '', ['array', 'json'], true) && in_array($casts['actions'] ?? '', ['array', 'json'], true);
                    $add('WP-WF-09', $castOk ? 'ok' : 'blocking', 'Format de stockage', $castOk ? 'conditions et actions converties en tableaux par le modèle' : 'conversion « array » absente : format de stockage inconnu');
                    if (!$need && $castOk) {
                        $sample = self::sampleFormat();
                        $add('WP-WF-10', $sample === '' ? 'ok' : 'blocking', 'Règles existantes au format attendu', $sample === '' ? 'liste de groupes ET contenant des conditions OU' : $sample);
                    }
                }
            } catch (\Throwable $e) {
                $add('WP-WF-07', 'blocking', 'Modèle Workflows', 'erreur : '.$e->getMessage());
            }
        }

        $tags = Bridge::moduleActive('tags');
        $add('WP-TAGS-01', 'ok', 'Module Tags', $tags ? 'actif : les règles qui en dépendent peuvent être importées' : 'inactif : les règles qui en dépendent seront bloquées ou ignorées sur demande');
        $refresh = self::refreshActive();
        $add('WP-REF-01', 'ok', 'Style Refresh', $refresh ? 'Refresh actif : pages affichées dans son style' : 'Refresh absent : style FreeScout standard');

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
                'workflowpacks' => \Modules\WorkflowPacks\Services\Update\Updater::currentVersion(),
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
                        return 'règle #'.$row->id.' : « '.$field.' » n\'est pas une liste de groupes (format différent de celui analysé)';
                    }
                    foreach ($group as $item) {
                        if (!is_array($item) || !array_key_exists('type', $item)) {
                            return 'règle #'.$row->id.' : élément de « '.$field.' » sans « type » (format différent de celui analysé)';
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
