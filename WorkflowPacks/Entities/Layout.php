<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class Layout extends Model
{
    protected $table = 'workflowpacks_layouts';

    protected $fillable = ['user_id', 'view', 'positions', 'settings', 'sizes'];
}
