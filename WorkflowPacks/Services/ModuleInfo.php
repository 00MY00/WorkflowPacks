<?php

namespace Modules\WorkflowPacks\Services;

/**
 * The module itself: installed version, its tables, and who updates it.
 *
 * Updates are not done by this module any more (2.0.0): module.json declares latestVersionUrl / latestVersionZipUrl,
 * so FreeScout's own Manage › Modules page offers them, and the UpdateGuard module (when installed) updates it
 * safely — SHA-256, backup, health check (workflowpacks:health), automatic rollback.
 */
class ModuleInfo
{
    const MODULE = 'WorkflowPacks';
    const ALIAS = 'workflowpacks';
    const TABLES = ['workflowpacks_packs', 'workflowpacks_links', 'workflowpacks_imports', 'workflowpacks_resources', 'workflowpacks_layouts',
        'workflowpacks_backups', 'workflowpacks_retention', 'workflowpacks_activity', 'workflowpacks_ticket_folders'];
    const UPDATEGUARD_URL = 'https://github.com/00MY00/UpdateGuard';

    protected static $version = null;

    public static function version()
    {
        if (self::$version === null) {
            $json = json_decode((string) @file_get_contents(base_path('Modules/'.self::MODULE.'/module.json')), true);
            self::$version = is_array($json) && !empty($json['version']) ? (string) $json['version'] : '0.0.0';
        }

        return self::$version;
    }

    /** UpdateGuard installed and active: it updates this module (and its page can be linked). */
    public static function updateGuard()
    {
        try {
            return (bool) \App\Module::isActive('updateguard');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Page of this module in UpdateGuard, or its dashboard, or null when UpdateGuard is not there. */
    public static function updateGuardUrl()
    {
        if (!self::updateGuard()) {
            return null;
        }
        try {
            if (\Route::has('updateguard.module')) {
                return route('updateguard.module', ['name' => self::MODULE]);
            }
            if (\Route::has('updateguard.dashboard')) {
                return route('updateguard.dashboard');
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** FreeScout's page of the modules (its own "Update" button). */
    public static function modulesUrl()
    {
        try {
            return \Route::has('modules') ? route('modules') : url('/modules/list');
        } catch (\Throwable $e) {
            return url('/modules/list');
        }
    }
}
