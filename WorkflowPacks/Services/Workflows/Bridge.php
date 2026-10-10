<?php

namespace Modules\WorkflowPacks\Services\Workflows;

/**
 * Everything WorkflowPacks uses from the official Workflows module, in one place. Only public members found in the
 * module's Workflow model are used (see docs/analyse.md):
 *   constants TYPE_AUTOMATIC / TYPE_MANUAL, static conditionsConfig($mailbox_id) / actionsConfig($mailbox_id)
 *   (filtered by "workflows.conditions_config" / "workflows.actions_config", so Tags & co. are included),
 *   static canEditWorkflows($user, $mailbox_id), validate(), checkComplete(), setSortOrderLast(),
 *   the Eloquent model itself (table, casts "conditions"/"actions" => array).
 * Each one is checked by the compatibility checker before use; nothing of the module is modified.
 */
class Bridge
{
    protected static $catalogs = [];

    public static function config($key = null)
    {
        $config = (array) config('workflowpacks.workflows');

        return $key === null ? $config : ($config[$key] ?? null);
    }

    public static function modelClass()
    {
        return (string) self::config('model');
    }

    public static function classAvailable()
    {
        return class_exists(self::modelClass());
    }

    public static function moduleActive($alias)
    {
        try {
            return (bool) \App\Module::isActive(strtolower($alias));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Version written in Modules/Workflows/module.json, or ''. */
    public static function version()
    {
        try {
            $module = \Module::find('Workflows');
            if ($module) {
                return (string) $module->get('version');
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    /** @return \Illuminate\Database\Eloquent\Model */
    public static function newModel()
    {
        $class = self::modelClass();

        return new $class();
    }

    public static function query()
    {
        $class = self::modelClass();

        return $class::query();
    }

    public static function table()
    {
        return self::newModel()->getTable();
    }

    /** ['automatic' => value of TYPE_AUTOMATIC, 'manual' => value of TYPE_MANUAL] */
    public static function types()
    {
        $class = self::modelClass();

        return ['automatic' => constant($class.'::TYPE_AUTOMATIC'), 'manual' => constant($class.'::TYPE_MANUAL')];
    }

    public static function typeName($value)
    {
        $name = array_search((int) $value, array_map('intval', self::types()), true);

        return $name === false ? 'automatic' : $name;
    }

    /**
     * Catalog of conditions and actions offered by Workflows (and the modules that extend it) for a mailbox:
     * ['conditions' => [type => ['title', 'group', 'operators' => [keys]|null, 'values' => [keys]|null]], 'actions' => …]
     * "values": null = free value, [] = no value, list = values offered by the interface.
     */
    public static function catalog($mailboxId)
    {
        $mailboxId = (int) $mailboxId;
        if (isset(self::$catalogs[$mailboxId])) {
            return self::$catalogs[$mailboxId];
        }
        $class = self::modelClass();

        return self::$catalogs[$mailboxId] = [
            'conditions' => self::normalizeCatalog($class::conditionsConfig($mailboxId)),
            'actions'    => self::normalizeCatalog($class::actionsConfig($mailboxId)),
        ];
    }

    public static function forgetCatalogs()
    {
        self::$catalogs = [];
    }

    protected static function normalizeCatalog($config)
    {
        $out = [];
        foreach ((array) $config as $groupKey => $group) {
            foreach ((array) ($group['items'] ?? []) as $type => $item) {
                $out[(string) $type] = [
                    'title'     => strip_tags((string) ($item['title'] ?? $type)),
                    'group'     => strip_tags((string) ($group['title'] ?? $groupKey)),
                    'operators' => isset($item['operators']) && is_array($item['operators']) ? array_map('strval', array_keys($item['operators'])) : null,
                    'values'    => array_key_exists('values', $item) && is_array($item['values']) ? array_map('strval', array_keys($item['values'])) : null,
                    // readable labels for the editor of the diagram (keys above stay the reference)
                    'operator_labels' => self::labels($item['operators'] ?? null),
                    'value_labels'    => self::labels($item['values'] ?? null),
                ];
            }
        }

        return $out;
    }

    /** key => label (text only), or [] */
    protected static function labels($list)
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $key => $label) {
            $out[(string) $key] = is_scalar($label) ? strip_tags((string) $label) : (string) $key;
        }

        return $out;
    }

    /** Workflows' own permission rule (admin, or access to the mailbox + "manage workflows" permission). */
    public static function canEdit($user, $mailboxId)
    {
        if (!$user) {
            return false;
        }
        $class = self::modelClass();
        if (class_exists($class) && method_exists($class, 'canEditWorkflows')) {
            try {
                return (bool) $class::canEditWorkflows($user, (int) $mailboxId);
            } catch (\Throwable $e) {
                return false;
            }
        }

        // method missing in this version: administrators only (reported by the compatibility check)
        return $user->isAdmin();
    }

    /** Mailboxes in which the user may manage workflows. */
    public static function editableMailboxes($user)
    {
        if (!$user) {
            return collect();
        }
        $mailboxes = $user->isAdmin() ? \App\Mailbox::orderBy('name')->get() : $user->mailboxesCanView();

        return collect($mailboxes)->filter(function ($mailbox) use ($user) {
            return self::canEdit($user, $mailbox->id);
        })->sortBy('name')->values();
    }

    /**
     * Errors reported by Workflows' own validation (Workflow::validate()) for unsaved attributes, or [] when the
     * method does not exist.
     */
    public static function officialErrors(array $attributes, $mailboxId)
    {
        $model = self::newModel();
        if (!method_exists($model, 'validate')) {
            return [];
        }
        $model->mailbox_id = (int) $mailboxId;
        foreach ($attributes as $k => $v) {
            $model->$k = $v;
        }
        try {
            return (array) $model->validate();
        } catch (\Throwable $e) {
            return ['exception' => [$e->getMessage()]];
        }
    }

    /** Raw attributes of a workflow as stored (conditions/actions decoded by the model's casts). */
    public static function attributesOf($workflow)
    {
        return [
            'name'           => (string) $workflow->name,
            'type'           => (int) $workflow->type,
            'apply_to_prev'  => (bool) $workflow->apply_to_prev,
            'max_executions' => (int) $workflow->max_executions,
            'conditions'     => is_array($workflow->conditions) ? $workflow->conditions : (json_decode((string) $workflow->conditions, true) ?: []),
            'actions'        => is_array($workflow->actions) ? $workflow->actions : (json_decode((string) $workflow->actions, true) ?: []),
        ];
    }
}
