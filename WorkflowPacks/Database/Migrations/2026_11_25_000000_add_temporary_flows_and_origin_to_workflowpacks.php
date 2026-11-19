<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.20: temporary flows (a list of tickets, handled once, then the flow deletes itself) and, for each ticket in a
 * flow, where it came from and the name of the flow (kept for the statistics after the flow is deleted).
 */
class AddTemporaryFlowsAndOriginToWorkflowpacks extends Migration
{
    public function up()
    {
        Schema::table('workflowpacks_flows', function (Blueprint $table) {
            if (!Schema::hasColumn('workflowpacks_flows', 'temporary')) {
                $table->boolean('temporary')->default(false);
                $table->longText('ticket_ids')->nullable();          // JSON list of ticket ids (temporary flows)
                $table->timestamp('expires_at')->nullable();         // deleted at the latest then (temporary flows)
                $table->string('origin', 40)->nullable();            // who asked for it: "untreated", "refreshglobal"…
            }
        });
        Schema::table('workflowpacks_flow_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('workflowpacks_flow_runs', 'origin')) {
                $table->string('origin', 20)->nullable();            // new_mail | reapply | agent | temporary
                $table->string('flow_name', 191)->nullable();        // kept when the flow is deleted
            }
        });
    }

    public function down()
    {
        Schema::table('workflowpacks_flow_runs', function (Blueprint $table) {
            $table->dropColumn(['origin', 'flow_name']);
        });
        Schema::table('workflowpacks_flows', function (Blueprint $table) {
            $table->dropColumn(['temporary', 'ticket_ids', 'expires_at', 'origin']);
        });
    }
}
