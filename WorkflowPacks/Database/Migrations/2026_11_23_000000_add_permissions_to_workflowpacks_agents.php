<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\WorkflowPacks\Entities\Agent;

/**
 * Rights of a connected agent as a matrix (3.17): each action on its own flows and on the other flows of its mailboxes.
 * The agents already there keep exactly the rights they had (converted from the former check boxes).
 */
class AddPermissionsToWorkflowpacksAgents extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_agents') && !Schema::hasColumn('workflowpacks_agents', 'permissions')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->text('permissions')->nullable();
            });
            foreach (\DB::table('workflowpacks_agents')->get() as $row) {
                \DB::table('workflowpacks_agents')->where('id', $row->id)->update(['permissions' => json_encode(Agent::legacyRights((array) $row))]);
            }
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_agents', 'permissions')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->dropColumn('permissions');
            });
        }
    }
}
