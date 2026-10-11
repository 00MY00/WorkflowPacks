<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'workflowpacks_activity';

    protected $fillable = ['kind', 'status', 'mailbox_id', 'conversation_id', 'workflow_id', 'user_id', 'subject', 'message'];
}
