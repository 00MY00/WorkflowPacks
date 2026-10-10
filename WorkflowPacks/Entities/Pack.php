<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class Pack extends Model
{
    protected $table = 'workflowpacks_packs';

    protected $fillable = ['pack_key', 'name', 'description', 'pack_version', 'schema_version', 'source'];
}
