<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Packs of flows (3.3): a flow remembers the pack and the key it was imported from, so that a new import updates it. */
class AddPackToWorkflowpacksFlows extends Migration
{
    public function up()
    {
        if (Schema::hasTable('workflowpacks_flows') && !Schema::hasColumn('workflowpacks_flows', 'pack_key')) {
            Schema::table('workflowpacks_flows', function (Blueprint $table) {
                $table->string('pack_key', 100)->nullable();
                $table->string('flow_key', 100)->nullable();
                $table->index(['pack_key', 'flow_key']);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_flows', 'pack_key')) {
            Schema::table('workflowpacks_flows', function (Blueprint $table) {
                $table->dropIndex(['pack_key', 'flow_key']);
                $table->dropColumn(['pack_key', 'flow_key']);
            });
        }
    }
}
