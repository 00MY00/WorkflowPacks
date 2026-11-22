<?php

namespace Modules\WorkflowPacks\Services;

use Modules\WorkflowPacks\Entities\Backup;

/**
 * Separation from the Workflows module (4.0): the module no longer uses Workflows. What the packs of Workflows rules
 * left is removed only when an administrator confirms it (Http\Controllers\SeparationController), never by the update
 * itself, so that going back to the previous version stays possible until then:
 *
 *   1. a backup of everything first (packs as imported, their links, imports, resources, layouts of the diagram, the
 *      Workflows rules the packs created as they are now, the older backups of removed packs), kept in
 *      workflowpacks_backups and downloadable;
 *   2. the Workflows rules created by the packs (only those: the ones in workflowpacks_links, in the same mailbox)
 *      deleted, with Workflows' own model when the module is there (what its "Delete" does), else from its table;
 *   3. the old tables of the module dropped, the older backups of removed packs deleted (they are in the new backup).
 *
 * Rules created by hand in Workflows are never touched. Old tables left empty (nothing ever imported) do not ask for a
 * decision; they are kept so that the previous version still works if it is installed again.
 */
class Separation
{
    const LEGACY_TABLES = ['workflowpacks_links', 'workflowpacks_packs', 'workflowpacks_imports', 'workflowpacks_resources', 'workflowpacks_layouts'];
    const WORKFLOWS_MODEL = '\Modules\Workflows\Entities\Workflow';
    const WORKFLOWS_TABLE = 'workflows';
    const RUNS_TABLE = 'conversation_workflow';
    const BACKUP_KEY = 'separation-workflows';
    const OPTION_DONE = 'workflowpacks.separated_at';
    /** the last version that used Workflows, offered to stay on it */
    const PREVIOUS = '3.20.2';
    const REPO = 'https://github.com/00MY00/WorkflowPacks';

    protected static $pending = null;

    /** Something of the packs of Workflows rules is left (rows in the old tables, older backups): a decision is needed. */
    public static function pending($fresh = false)
    {
        if (self::$pending !== null && !$fresh) {
            return self::$pending;
        }
        try {
            foreach (self::LEGACY_TABLES as $table) {
                if (\Schema::hasTable($table) && \DB::table($table)->exists()) {
                    return self::$pending = true;
                }
            }

            return self::$pending = self::legacyBackups()->exists();
        } catch (\Throwable $e) {
            return self::$pending = false;
        }
    }

    /** Backups of removed packs of rules (not those of flows, not the backup of the separation). */
    protected static function legacyBackups()
    {
        return Backup::where('pack_key', 'not like', 'flow-%')->where('pack_key', '!=', self::BACKUP_KEY);
    }

    /**
     * What a confirmation would remove.
     *
     * @return array ['rules' => [[workflow_id, name, mailbox, pack_key, pack, active]], 'gone' => links whose rule no longer
     *               exists, 'packs' => [[key, name, version, rules]], 'tables' => [table => rows], 'backups' => count,
     *               'workflows' => the table of Workflows is there]
     */
    public static function plan()
    {
        $tables = [];
        foreach (self::LEGACY_TABLES as $table) {
            if (\Schema::hasTable($table)) {
                $tables[$table] = \DB::table($table)->count();
            }
        }
        $links = isset($tables['workflowpacks_links']) ? \DB::table('workflowpacks_links')->orderBy('id')->get() : collect();
        $rules = self::rules($links);
        $packNames = isset($tables['workflowpacks_packs']) ? \DB::table('workflowpacks_packs')->pluck('name', 'pack_key') : collect();
        $mailboxes = \App\Mailbox::pluck('name', 'id');
        $list = [];
        $gone = 0;
        foreach ($links as $link) {
            $rule = $link->workflow_id ? ($rules[(int) $link->workflow_id] ?? null) : null;
            if (!$rule) {
                $gone++;
                continue;
            }
            $list[] = ['workflow_id' => (int) $rule->id, 'name' => (string) $rule->name, 'active' => (bool) ($rule->active ?? false),
                'mailbox' => (string) ($mailboxes[$rule->mailbox_id] ?? '#'.$rule->mailbox_id),
                'pack_key' => (string) $link->pack_key, 'pack' => (string) ($packNames[$link->pack_key] ?? $link->pack_key)];
        }
        $packs = [];
        if (isset($tables['workflowpacks_packs'])) {
            foreach (\DB::table('workflowpacks_packs')->orderBy('name')->get() as $pack) {
                $packs[] = ['key' => (string) $pack->pack_key, 'name' => (string) $pack->name, 'version' => (string) $pack->pack_version,
                    'rules' => count(array_filter($list, function ($r) use ($pack) {
                        return $r['pack_key'] === (string) $pack->pack_key;
                    }))];
            }
        }

        return ['rules' => $list, 'gone' => $gone, 'packs' => $packs, 'tables' => $tables,
            'backups' => self::legacyBackups()->count(), 'workflows' => self::workflowsTable()];
    }

    /** Everything that a confirmation removes, as it is now (the content of the backup). */
    public static function backup()
    {
        $rows = function ($table) {
            return \Schema::hasTable($table) ? \DB::table($table)->orderBy('id')->get()->map(function ($row) {
                return (array) $row;
            })->all() : [];
        };
        $links = \Schema::hasTable('workflowpacks_links') ? \DB::table('workflowpacks_links')->orderBy('id')->get() : collect();
        $packs = array_map(function ($pack) {
            $pack['source'] = json_decode((string) ($pack['source'] ?? ''), true) ?: ($pack['source'] ?? null);

            return $pack;
        }, $rows('workflowpacks_packs'));

        return [
            'format'          => 'workflowpacks-separation',
            'version'         => 1,
            'created_at'      => \Carbon\Carbon::now()->toIso8601String(),
            'module_version'  => ModuleInfo::version(),
            'packs'           => $packs,
            'links'           => $rows('workflowpacks_links'),
            'imports'         => $rows('workflowpacks_imports'),
            'resources'       => $rows('workflowpacks_resources'),
            'layouts'         => $rows('workflowpacks_layouts'),
            // the Workflows rules the packs created, as they are now (every column of the table)
            'workflows_rules' => array_values(array_map(function ($rule) {
                return (array) $rule;
            }, self::rules($links)->all())),
            'older_backups'   => self::legacyBackups()->orderBy('id')->get()->map(function ($b) {
                return ['id' => (int) $b->id, 'pack_key' => $b->pack_key, 'created_at' => (string) $b->created_at,
                    'content' => json_decode((string) $b->content, true)];
            })->all(),
        ];
    }

    /**
     * Confirmed by an administrator: backup, rules of the packs deleted, old tables dropped.
     *
     * @return array ['ok' => bool, 'error' => '', 'backup_id', 'deleted' => rules, 'tables' => dropped]
     */
    public static function apply($user)
    {
        if (!self::pending(true)) {
            return ['ok' => false, 'error' => 'nothing', 'backup_id' => null, 'deleted' => 0, 'tables' => []];
        }
        $json = json_encode(self::backup(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json) || $json === '') {
            return ['ok' => false, 'error' => 'backup', 'backup_id' => null, 'deleted' => 0, 'tables' => []];
        }
        // 1. the backup, before anything is removed
        $backup = Backup::create(['pack_key' => self::BACKUP_KEY, 'user_id' => $user ? $user->id : null, 'content' => $json]);

        // 2. the rules created by the packs, and the older backups of removed packs (both in the backup)
        $deleted = 0;
        try {
            \DB::transaction(function () use (&$deleted, $backup) {
                $links = \Schema::hasTable('workflowpacks_links') ? \DB::table('workflowpacks_links')->get() : collect();
                $rules = self::rules($links);
                $ids = array_keys($rules->all());
                if ($ids) {
                    $deleted = self::deleteRules($ids);
                }
                self::legacyBackups()->where('id', '!=', $backup->id)->delete();
            });
        } catch (\Throwable $e) {
            \Log::error('[WorkflowPacks] [separation] '.$e->getMessage());

            return ['ok' => false, 'error' => 'rules', 'backup_id' => (int) $backup->id, 'deleted' => 0, 'tables' => []];
        }

        // 3. the old tables (after the deletions: dropping a table cannot be undone by a transaction)
        $dropped = [];
        foreach (self::LEGACY_TABLES as $table) {
            if (\Schema::hasTable($table)) {
                \Schema::drop($table);
                $dropped[] = $table;
            }
        }
        \App\Option::set(self::OPTION_DONE, \Carbon\Carbon::now()->toDateTimeString());
        self::$pending = false;
        History::add('separation', 'ok', ['user_id' => $user ? $user->id : null, 'subject' => 'Workflows',
            'message' => __('workflowpacks::separation.history', ['rules' => $deleted, 'tables' => count($dropped), 'backup' => $backup->id])]);

        return ['ok' => true, 'error' => '', 'backup_id' => (int) $backup->id, 'deleted' => $deleted, 'tables' => $dropped];
    }


    /** Address of a release of the module, and of its archive. */
    public static function releaseUrl($version = self::PREVIOUS)
    {
        return self::REPO.'/releases/tag/v'.$version;
    }

    public static function zipUrl($version = self::PREVIOUS)
    {
        return self::REPO.'/releases/download/v'.$version.'/WorkflowPacks.zip';
    }

    /**
     * The Workflows rules of these links that still exist, in the mailbox of their link: [id => row].
     *
     * @return \Illuminate\Support\Collection
     */
    protected static function rules($links)
    {
        if (!self::workflowsTable() || !count($links)) {
            return collect();
        }
        $mailboxOf = [];
        foreach ($links as $link) {
            if ($link->workflow_id) {
                $mailboxOf[(int) $link->workflow_id] = (int) $link->mailbox_id;
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($mailboxOf), 500) as $chunk) {
            foreach (\DB::table(self::WORKFLOWS_TABLE)->whereIn('id', $chunk)->get() as $rule) {
                // the rule of the link, in its mailbox (never a rule that would only share its id)
                if ((int) $rule->mailbox_id === $mailboxOf[(int) $rule->id]) {
                    $out[(int) $rule->id] = $rule;
                }
            }
        }
        ksort($out);

        return collect($out);
    }

    /** Deleted as Workflows' own "Delete" does when the module is there, else from its tables. @return int deleted */
    protected static function deleteRules(array $ids)
    {
        $class = self::WORKFLOWS_MODEL;
        $model = class_exists($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class);
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            if ($model) {
                foreach ($class::whereIn('id', $chunk)->get() as $rule) {
                    $rule->delete();
                    $deleted++;
                }
            } else {
                $deleted += \DB::table(self::WORKFLOWS_TABLE)->whereIn('id', $chunk)->delete();
            }
            if (\Schema::hasTable(self::RUNS_TABLE)) {
                \DB::table(self::RUNS_TABLE)->whereIn('workflow_id', $chunk)->delete();
            }
        }

        return $deleted;
    }

    protected static function workflowsTable()
    {
        try {
            return \Schema::hasTable(self::WORKFLOWS_TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
