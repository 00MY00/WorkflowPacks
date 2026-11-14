<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

/** What happened to a ticket on a block: output taken, decision, changes (history of the flows, path of an e-mail). */
class FlowEvent extends Model
{
    const UPDATED_AT = null;

    protected $table = 'workflowpacks_flow_events';

    protected $fillable = ['run_id', 'flow_id', 'conversation_id', 'block', 'output', 'summary', 'changes', 'level'];

    protected $casts = ['changes' => 'array'];
}
