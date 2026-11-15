<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

/** A flow applied to the tickets already there, batch after batch (Services/Engine/Reapply). */
class FlowReapply extends Model
{
    const RUNNING = 'running';
    const PAUSED = 'paused';
    const STOPPED = 'stopped';
    const DONE = 'done';

    /** tickets in progress moved to the current version / existing tickets run through the flow */
    const SCOPE_RUNNING = 'running';
    const SCOPE_EXISTING = 'existing';

    protected $table = 'workflowpacks_flow_reapply';

    protected $fillable = ['flow_id', 'version_id', 'scope', 'options', 'cursor', 'total', 'done', 'started', 'batch', 'every',
        'state', 'next_at', 'created_by'];

    protected $casts = ['options' => 'array'];

    protected $dates = ['next_at'];

    public function active()
    {
        return in_array($this->state, [self::RUNNING, self::PAUSED], true);
    }
}
