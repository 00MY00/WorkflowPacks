<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Home page (3.6.2): a flow remembers the name of the pack it came from, to list the flows by pack under that name. */
class AddPackNameToWorkflowpacksFlows extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_flows') && !Schema::hasColumn('workflowpacks_flows', 'pack_name')) {
            Schema::table('workflowpacks_flows', function (Blueprint $table) {
                $table->string('pack_name', 191)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_flows', 'pack_name')) {
            Schema::table('workflowpacks_flows', function (Blueprint $table) {
                $table->dropColumn('pack_name');
            });
        }
    }
}
