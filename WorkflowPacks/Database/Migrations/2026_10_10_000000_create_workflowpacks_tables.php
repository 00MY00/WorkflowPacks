<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking tables of WorkflowPacks. The rules themselves stay in the table of the Workflows module.
 *
 *  workflowpacks_packs    one row per imported pack (original template, without the values entered at import)
 *  workflowpacks_links    pack.key + workflow.key + mailbox -> id of the workflow created, its template and the
 *                         fingerprint of what was written (detects manual changes made later)
 *  workflowpacks_imports  history of the imports (who, when, what)
 */
class CreateWorkflowpacksTables extends Migration
{
    public function up()
    {
        Schema::create('workflowpacks_packs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('pack_key', 100)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('pack_version', 50)->default('');
            $table->unsignedInteger('schema_version')->default(1);
            $table->longText('source');
            $table->timestamps();
        });

        Schema::create('workflowpacks_links', function (Blueprint $table) {
            $table->increments('id');
            $table->string('pack_key', 100);
            $table->string('workflow_key', 100);
            $table->unsignedInteger('mailbox_id');
            $table->unsignedInteger('workflow_id')->nullable();
            $table->longText('template');
            $table->text('params')->nullable();
            $table->string('fingerprint', 64);
            $table->string('pack_version', 50)->default('');
            $table->unsignedInteger('imported_by')->nullable();
            $table->timestamps();
            $table->unique(['pack_key', 'workflow_key', 'mailbox_id'], 'workflowpacks_links_identity');
            $table->index('workflow_id');
        });

        Schema::create('workflowpacks_imports', function (Blueprint $table) {
            $table->increments('id');
            $table->string('pack_key', 100);
            $table->string('pack_version', 50)->default('');
            $table->unsignedInteger('user_id')->nullable();
            $table->text('summary');
            $table->timestamps();
            $table->index('pack_key');
        });
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_imports');
        Schema::dropIfExists('workflowpacks_links');
        Schema::dropIfExists('workflowpacks_packs');
    }
}
