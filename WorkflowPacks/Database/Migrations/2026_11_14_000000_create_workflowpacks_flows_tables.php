<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal flow engine (3.0, docs/moteur-autonome.md): flows (each block an object), their published versions (frozen),
 * the tickets going through them (runs, with their wake-up time) and what happened on each block (events).
 */
class CreateWorkflowpacksFlowsTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflowpacks_flows')) {
            Schema::create('workflowpacks_flows', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 191);
                $table->text('mailbox_ids')->nullable();            // JSON list; empty: every mailbox
                $table->boolean('active')->default(false);
                $table->boolean('once')->default(true);             // once per ticket
                $table->longText('draft')->nullable();              // JSON {blocks, links}: what the editor shows
                $table->unsignedInteger('version_id')->nullable();  // published version that runs
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('workflowpacks_flow_versions')) {
            Schema::create('workflowpacks_flow_versions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('flow_id');
                $table->unsignedInteger('number');
                $table->longText('data');                           // JSON {blocks, links}, never changed
                $table->unsignedInteger('published_by')->nullable();
                $table->timestamps();
                $table->index('flow_id');
            });
        }
        if (!Schema::hasTable('workflowpacks_flow_runs')) {
            Schema::create('workflowpacks_flow_runs', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('flow_id');
                $table->unsignedInteger('version_id');
                $table->unsignedInteger('conversation_id');
                $table->string('block', 40);                        // current block
                $table->string('state', 12);                        // running | waiting | done | failed | cancelled
                $table->timestamp('wake_at')->nullable();
                $table->string('resume', 40)->nullable();           // output to follow on wake-up (null: run the block again)
                $table->string('wait_event', 60)->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->text('vars')->nullable();                   // JSON: variables set by the blocks
                $table->timestamp('claimed_at')->nullable();
                $table->timestamps();
                $table->index(['state', 'wake_at']);
                $table->index(['conversation_id', 'state']);
                $table->index('flow_id');
            });
        }
        if (!Schema::hasTable('workflowpacks_flow_events')) {
            Schema::create('workflowpacks_flow_events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('run_id');
                $table->unsignedInteger('flow_id');
                $table->unsignedInteger('conversation_id');
                $table->string('block', 40);
                $table->string('output', 40)->default('');
                $table->string('summary', 255)->default('');        // the decision, in short (never an e-mail body)
                $table->text('changes')->nullable();                // JSON [[field, before, after]]
                $table->unsignedTinyInteger('level')->default(1);   // 1 info, 2 warning, 3 error
                $table->timestamp('created_at')->nullable();
                $table->index('run_id');
                $table->index(['conversation_id', 'created_at']);
                $table->index('created_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_flow_events');
        Schema::dropIfExists('workflowpacks_flow_runs');
        Schema::dropIfExists('workflowpacks_flow_versions');
        Schema::dropIfExists('workflowpacks_flows');
    }
}
