<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rights of a connected agent on the flows it did not make (3.15): see them (the agents already there keep seeing them,
 * as before), publish and switch them on within its mailboxes.
 */
class AddFlowRightsToWorkflowpacksAgents extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_agents') && !Schema::hasColumn('workflowpacks_agents', 'can_see_all')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->boolean('can_see_all')->default(false);
                $table->boolean('can_publish_others')->default(false);
            });
            \DB::table('workflowpacks_agents')->update(['can_see_all' => true]);
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_agents', 'can_see_all')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->dropColumn(['can_see_all', 'can_publish_others']);
            });
        }
    }
}
