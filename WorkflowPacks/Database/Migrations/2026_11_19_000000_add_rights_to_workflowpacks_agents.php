<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rights of a connected agent (3.12): read only, addresses allowed to use its token, last address seen. */
class AddRightsToWorkflowpacksAgents extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_agents') && !Schema::hasColumn('workflowpacks_agents', 'read_only')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->boolean('read_only')->default(false);
                $table->text('allowed_ips')->nullable();
                $table->string('last_ip', 45)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_agents', 'read_only')) {
            Schema::table('workflowpacks_agents', function (Blueprint $table) {
                $table->dropColumn(['read_only', 'allowed_ips', 'last_ip']);
            });
        }
    }
}
