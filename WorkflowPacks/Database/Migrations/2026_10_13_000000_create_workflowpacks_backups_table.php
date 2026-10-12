<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backups made before a pack is removed (1.5.0): the pack as imported + every rule deleted, as it was, so that it can be
 * imported again or read. Stored in the database (not in storage/, which cron may own).
 */
class CreateWorkflowpacksBackupsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflowpacks_backups')) {
            Schema::create('workflowpacks_backups', function (Blueprint $table) {
                $table->increments('id');
                $table->string('pack_key', 100);
                $table->unsignedInteger('user_id')->nullable();
                $table->longText('content');
                $table->timestamps();
                $table->index('pack_key');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_backups');
    }
}
