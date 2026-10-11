<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    protected $table = 'workflowpacks_backups';

    protected $fillable = ['pack_key', 'user_id', 'content'];
}
