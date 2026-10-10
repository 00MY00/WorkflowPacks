<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    protected $table = 'workflowpacks_resources';

    protected $fillable = ['pack_key', 'resource_key', 'resource_type', 'local_id', 'created_by_pack', 'last_seen_name', 'fingerprint'];
}
