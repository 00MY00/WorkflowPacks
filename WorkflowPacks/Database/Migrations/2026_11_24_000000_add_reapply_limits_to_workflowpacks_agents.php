<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents applying a flow to the tickets already there (3.19): the limits an administrator gives each agent (statuses,
 * period, tags, assigned users, number of tickets), and which agent started a run.
 */
class AddReapplyLimitsToWorkflowpacksAgents extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_agents') && !Schema::hasColumn('workflowpacks_agents', 'reapply_limits')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->text('reapply_limits')->nullable();
            });
        }
        if (Schema::hasTable('workflowpacks_flow_reapply') && !Schema::hasColumn('workflowpacks_flow_reapply', 'agent_id')) {
            Schema::table('workflowpacks_flow_reapply', function (Blueprint $table) {
                $table->unsignedInteger('agent_id')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_agents', 'reapply_limits')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->dropColumn('reapply_limits');
            });
        }
        if (Schema::hasColumn('workflowpacks_flow_reapply', 'agent_id')) {
            Schema::table('workflowpacks_flow_reapply', function (Blueprint $table) {
                $table->dropColumn('agent_id');
            });
        }
    }
}
