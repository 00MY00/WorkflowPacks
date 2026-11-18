<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Right of a connected agent (3.16): delete flows (with a confirmation, after a backup). */
class AddCanDeleteToWorkflowpacksAgents extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_agents') && !Schema::hasColumn('workflowpacks_agents', 'can_delete')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->boolean('can_delete')->default(false);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_agents', 'can_delete')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->dropColumn('can_delete');
            });
        }
    }
}
