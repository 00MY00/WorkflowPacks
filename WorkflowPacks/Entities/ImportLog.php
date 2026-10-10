<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = 'workflowpacks_imports';

    protected $fillable = ['pack_key', 'pack_version', 'user_id', 'summary'];
}
