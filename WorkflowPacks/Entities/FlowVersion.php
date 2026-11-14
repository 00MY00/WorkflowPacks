<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

/** A published version of a flow: frozen, the tickets already in it keep it. */
class FlowVersion extends Model
{
    protected $table = 'workflowpacks_flow_versions';

    protected $fillable = ['flow_id', 'number', 'data', 'published_by'];

    protected $casts = ['data' => 'array'];
}
