<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI agents connected by the API (3.2): one row per access, the token kept only as its SHA-256 fingerprint. A flow
 * remembers the agent that created it (the only flows that agent may change).
 */
class CreateWorkflowpacksAgentsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflowpacks_agents')) {
            Schema::create('workflowpacks_agents', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 100);
                $table->char('token_hash', 64)->unique();       // sha256 of the token, never the token
                $table->string('token_hint', 12);               // its first characters, to recognise it
                $table->string('mode', 12);                     // once | continuous
                $table->text('mailbox_ids')->nullable();        // JSON list: the only mailboxes it may read
                $table->boolean('anonymise')->default(true);
                $table->boolean('can_publish')->default(false);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('workflowpacks_flows') && !Schema::hasColumn('workflowpacks_flows', 'agent_id')) {
            Schema::table('workflowpacks_flows', function (Blueprint $table) {
                $table->unsignedInteger('agent_id')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_flows', 'agent_id')) {
            Schema::table('workflowpacks_flows', function (Blueprint $table) {
                $table->dropColumn('agent_id');
            });
        }
        Schema::dropIfExists('workflowpacks_agents');
    }
}
