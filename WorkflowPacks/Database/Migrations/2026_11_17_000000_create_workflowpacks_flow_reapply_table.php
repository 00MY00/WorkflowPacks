<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flow applied to the tickets already there (3.5): the tickets in progress moved to the new version, or the existing
 * tickets of a period run through the flow, in batches spread over time. The cursor resumes where it stopped.
 */
class CreateWorkflowpacksFlowReapplyTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflowpacks_flow_reapply')) {
            Schema::create('workflowpacks_flow_reapply', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('flow_id');
                $table->unsignedInteger('version_id');
                $table->string('scope', 20);
                $table->text('options')->nullable();
                $table->unsignedInteger('cursor')->default(0);
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('done')->default(0);
                $table->unsignedInteger('started')->default(0);
                $table->unsignedSmallInteger('batch')->default(50);
                $table->unsignedSmallInteger('every')->default(1);
                $table->string('state', 20);
                $table->timestamp('next_at')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['state', 'next_at']);
                $table->index('flow_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_flow_reapply');
    }
}
