<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logical resources of the packs (format v2): pack.key + resource.key + type -> local record chosen by the user.
 * A local record belongs to a pack only through this mapping: a record with the same name is never adopted, renamed,
 * replaced or deleted. created_by_pack stays false while no resource type can be created safely.
 */
class CreateWorkflowpacksResourcesTable extends Migration
{
    public function up()
    {
        Schema::create('workflowpacks_resources', function (Blueprint $table) {
            $table->increments('id');
            $table->string('pack_key', 100);
            $table->string('resource_key', 100);
            $table->string('resource_type', 30);
            $table->unsignedInteger('local_id');
            $table->boolean('created_by_pack')->default(false);
            $table->string('last_seen_name', 191)->default('');
            $table->string('fingerprint', 64)->default('');
            $table->timestamps();
            $table->unique(['pack_key', 'resource_key', 'resource_type'], 'workflowpacks_resources_identity');
        });
    }

    public function down()
    {
        Schema::dropIfExists('workflowpacks_resources');
    }
}
