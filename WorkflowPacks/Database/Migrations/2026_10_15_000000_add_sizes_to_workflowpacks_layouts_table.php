<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Diagram (1.6.0): sizes of the blocks enlarged by hand (resize grip), per user, next to their positions. */
class AddSizesToWorkflowpacksLayoutsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('workflowpacks_layouts', 'sizes')) {
            Schema::table('workflowpacks_layouts', function (Blueprint $table) {
                $table->longText('sizes')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('workflowpacks_layouts', 'sizes')) {
            Schema::table('workflowpacks_layouts', function (Blueprint $table) {
                $table->dropColumn('sizes');
            });
        }
    }
}
