<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

/** One ticket in one flow: the block it is on, waiting or not, until when. */
class FlowRun extends Model
{
    const RUNNING = 'running';
    const WAITING = 'waiting';
    const DONE = 'done';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';

    /** how the ticket entered the flow */
    const ORIGIN_NEW_MAIL = 'new_mail';
    const ORIGIN_REAPPLY = 'reapply';
    const ORIGIN_AGENT = 'agent';
    const ORIGIN_TEMPORARY = 'temporary';

    protected $table = 'workflowpacks_flow_runs';

    protected $fillable = ['flow_id', 'version_id', 'conversation_id', 'block', 'state', 'wake_at', 'resume', 'wait_event', 'attempts', 'vars', 'claimed_at',
        'origin', 'flow_name'];

    protected $casts = ['vars' => 'array'];

    protected $dates = ['wake_at', 'claimed_at'];
}
