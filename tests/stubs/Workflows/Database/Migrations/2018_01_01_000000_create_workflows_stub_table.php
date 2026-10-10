<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TEST STUB ONLY: columns found during the analysis of the Workflows module (docs/analyse.md). */
class CreateWorkflowsStubTable extends Migration
{
    public function up()
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id');
            $table->string('name', 75);
            $table->unsignedTinyInteger('type')->default(1);
            $table->boolean('apply_to_prev')->default(false);
            $table->boolean('complete')->default(true);
            $table->boolean('active')->default(false);
            $table->text('conditions')->nullable();
            $table->text('actions')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->unsignedInteger('max_executions')->default(1);
            $table->timestamps();
            $table->unique(['mailbox_id', 'name']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('workflows');
    }
}
