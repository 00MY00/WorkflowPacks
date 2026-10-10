<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    protected $table = 'workflowpacks_links';

    protected $fillable = ['pack_key', 'workflow_key', 'mailbox_id', 'workflow_id', 'template', 'params', 'fingerprint', 'pack_version', 'imported_by'];
}
