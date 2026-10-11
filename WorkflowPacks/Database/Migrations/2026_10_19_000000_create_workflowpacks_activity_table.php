<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** History of what the module did (1.11.0): e-mails filed, folders cleaned, packs imported or removed, rules changed. */
class CreateWorkflowpacksActivityTable extends Migration
{
    public function up()
    {
        Schema::create('workflowpacks_activity', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 30);                         // imap_file | imap_clean | import | remove | rules
            $table->string('status', 20);                       // queued | moved | already | not_found | failed | skipped | ok
            $table->unsignedInteger('mailbox_id')->nullable();
            $table->unsignedInteger('conversation_id')->nullable();
            $table->unsignedInteger('workflow_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('subject', 191)->default('');        // folder, rule or pack concerned
            $table->text('message')->nullable();                // details (reason of a failure…)
            $table->timestamps();
            $table->index('created_at');
            $table->index('mailbox_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_activity');
    }
}
