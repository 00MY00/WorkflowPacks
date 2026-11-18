<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Right of a connected agent (3.13): switch on its own flow once a dry run of that very draft passed. */
class AddCanActivateToWorkflowpacksAgents extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_agents') && !Schema::hasColumn('workflowpacks_agents', 'can_activate')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->boolean('can_activate')->default(false);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_agents', 'can_activate')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->dropColumn('can_activate');
            });
        }
    }
}
