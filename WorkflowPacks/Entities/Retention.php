<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class Retention extends Model
{
    protected $table = 'workflowpacks_retention';

    protected $fillable = ['folder', 'number', 'metric', 'mode', 'updated_by'];
}
