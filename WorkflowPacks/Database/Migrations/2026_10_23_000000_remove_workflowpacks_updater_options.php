<?php

use Illuminate\Database\Migrations\Migration;

/**
 * 2.0.0: the module no longer updates itself (FreeScout's Modules page, or UpdateGuard). Options of the former
 * built-in updater removed (automatic update switch, last check, pending "Update now" request).
 */
class RemoveWorkflowpacksUpdaterOptions extends Migration
{
    const OPTIONS = ['workflowpacks.auto_update', 'workflowpacks.update_status', 'workflowpacks.update_requested'];

    public function up()
    {
        try {
            \DB::table('options')->whereIn('name', self::OPTIONS)->delete();
        } catch (\Throwable $e) {
            // no options table: nothing to remove
        }
    }

    public function down()
    {
        // the former updater writes them again if it is put back
    }
}
