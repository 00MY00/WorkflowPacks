<?php

namespace Modules\WorkflowPacks\Services\Resources;

use Modules\WorkflowPacks\Entities\Resource;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Logical resources of a pack v2 ({{resource.key}}) -> local records of this installation.
 *
 *  - identity = pack.key + resource.key + type (table workflowpacks_resources), never the name;
 *  - a local record is used only when the user chose it (or chose it at a previous import): a record with the same
 *    name is only SUGGESTED, never adopted silently;
 *  - only records the user may use are offered and accepted (mailboxes: "manage workflows" permission);
 *  - nothing is ever created, renamed or deleted: no resource type offers a verified creation API yet
 *    (config workflowpacks.resources.*.create), so "on_missing": "create" falls back to asking.
 */
class ResourceResolver
{
    /** null when the type can be used here, else the reason (translated). */
    public static function unavailable($type, $key)
    {
        $config = (array) config('workflowpacks.resources.'.$type);
        if (!$config) {
            return Problems::t('res_bad_type', ['type' => $type, 'types' => implode(', ', array_keys((array) config('workflowpacks.resources')))]);
        }
        if (($config['source'] ?? '') === 'model') {
            $class = (string) $config['model'];
            if (!Bridge::moduleActive($config['module']) || !class_exists($class)) {
                return Problems::t('res_module_missing', ['key' => $key, 'type' => $type, 'module' => $config['module']]);
            }
            try {
                $model = new $class();
                if (!\Schema::hasTable($model->getTable()) || !\Schema::hasColumn($model->getTable(), $config['label'])) {
                    return Problems::t('res_module_missing', ['key' => $key, 'type' => $type, 'module' => $config['module']]);
                }
            } catch (\Throwable $e) {
                return Problems::t('res_module_missing', ['key' => $key, 'type' => $type, 'module' => $config['module']]);
            }
        }

        return null;
    }

    /** Local records the user may choose: id => label. */
    public static function candidates($type, $user)
    {
        $out = [];
        if ($type === 'mailbox') {
            foreach (Bridge::editableMailboxes($user) as $mailbox) {
                $out[(int) $mailbox->id] = $mailbox->name.' <'.$mailbox->email.'>';
            }
        } elseif ($type === 'user') {
            foreach (self::users($user) as $u) {
                $out[(int) $u->id] = trim($u->getFullName()).' <'.$u->email.'>';
            }
        } elseif (self::unavailable($type, '') === null) {
            $config = (array) config('workflowpacks.resources.'.$type);
            $class = (string) $config['model'];
            foreach ($class::query()->orderBy($config['label'])->limit(1000)->get() as $record) {
                $out[(int) $record->id] = (string) $record->{$config['label']};
            }
        }

        return $out;
    }

    /** Users offered for a "user" resource: all for an administrator, else those sharing a manageable mailbox. */
    protected static function users($user)
    {
        $query = \App\User::where('status', '!=', \App\User::STATUS_DELETED);
        if (!$user->isAdmin()) {
            $mailboxIds = Bridge::editableMailboxes($user)->pluck('id')->all() ?: [0];
            $query->whereIn('id', \DB::table('mailbox_user')->whereIn('mailbox_id', $mailboxIds)->pluck('user_id')->all() ?: [0]);
        }

        return $query->orderBy('first_name')->limit(1000)->get();
    }

    /** Candidates whose label equals the display name of the resource (suggestion only). */
    public static function sameName($type, $name, array $candidates)
    {
        $name = mb_strtolower(trim((string) $name));
        $out = [];
        foreach ($candidates as $id => $label) {
            $plain = mb_strtolower(trim(preg_replace('/\s*<[^>]*>$/', '', $label)));
            if ($plain === $name) {
                $out[] = $id;
            }
        }

        return $out;
    }

    public static function mapping($packKey, $resourceKey, $type)
    {
        return Resource::where('pack_key', $packKey)->where('resource_key', $resourceKey)->where('resource_type', $type)->first();
    }

    /**
     * Resolution of every resource of a pack.
     *
     * @param array $input resource key => local id chosen in the form ('' = none)
     * @return array key => ['decl', 'status' => mapped|unmapped|unavailable, 'local_id', 'label', 'value', 'int',
     *                       'candidates', 'suggested', 'previous', 'notes']
     */
    public static function resolve(array $pack, array $input, $user, Problems $problems)
    {
        $out = [];
        foreach ($pack['resources'] as $decl) {
            $key = $decl['key'];
            $entry = ['decl' => $decl, 'status' => 'unmapped', 'local_id' => null, 'label' => '', 'value' => null,
                'int' => $decl['use'] === 'id', 'candidates' => [], 'suggested' => [], 'previous' => null, 'notes' => []];
            $why = self::unavailable($decl['type'], $key);
            if ($why !== null) {
                $entry['status'] = 'unavailable';
                $entry['notes'][] = $why;
                $out[$key] = $entry;
                continue;
            }
            $candidates = self::candidates($decl['type'], $user);
            $entry['candidates'] = $candidates;
            $entry['suggested'] = self::sameName($decl['type'], $decl['name'], $candidates);
            if ($decl['on_missing'] === 'create' && !config('workflowpacks.resources.'.$decl['type'].'.create')) {
                $entry['notes'][] = Problems::t('res_create_unsupported', ['key' => $key, 'type' => $decl['type']]);
            }
            $previous = self::mapping($pack['key'], $key, $decl['type']);
            $entry['previous'] = $previous && isset($candidates[(int) $previous->local_id]) ? (int) $previous->local_id : null;

            $chosen = array_key_exists($key, $input) ? (string) $input[$key] : ($entry['previous'] !== null ? (string) $entry['previous'] : '');
            if ($chosen !== '') {
                if (!preg_match('/^\d+$/', $chosen) || !isset($candidates[(int) $chosen])) {
                    // forged or outdated value: never trusted
                    $problems->add('resources.'.$key, 'WP-RES-09', 'res_not_found', ['key' => $key, 'id' => $chosen]);
                } else {
                    $id = (int) $chosen;
                    $entry['status'] = 'mapped';
                    $entry['local_id'] = $id;
                    $entry['label'] = $candidates[$id];
                    $entry['value'] = $decl['use'] === 'id' ? $id : preg_replace('/\s*<[^>]*>$/', '', $candidates[$id]);
                }
            }
            $out[$key] = $entry;
        }

        return $out;
    }
}
