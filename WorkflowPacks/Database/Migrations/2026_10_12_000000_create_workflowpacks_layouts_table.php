<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Positions of the blocks of the diagram (Workflow Packs › Diagram), saved live, one layout per user and per view,
 * plus the display choices (layout, style of the links, packs shown).
 */
class CreateWorkflowpacksLayoutsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('workflowpacks_layouts')) {
            Schema::create('workflowpacks_layouts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('view', 50)->default('main');
                $table->longText('positions');
                $table->text('settings')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'view'], 'workflowpacks_layouts_identity');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_layouts');
    }
}
