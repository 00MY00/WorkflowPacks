<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic clean-up of the managed e-mail folders (1.9.0): the e-mails of "[WP] <folder>" older than a delay are moved
 * to the trash (or deleted) on the mail server, in every mailbox where that folder exists.
 */
class CreateWorkflowpacksRetentionTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflowpacks_retention')) {
            Schema::create('workflowpacks_retention', function (Blueprint $table) {
                $table->increments('id');
                $table->string('folder', 191)->unique();          // as written in the rules: "Newsletters/Promotions"
                $table->unsignedInteger('number');
                $table->string('metric', 1);                      // i (minutes) | h (hours) | d (days)
                $table->string('mode', 10)->default('trash');     // trash | delete
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_retention');
    }
}
