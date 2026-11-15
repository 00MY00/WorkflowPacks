<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

/** A flow of the internal engine: its blocks and links (draft), the published version that runs. */
class Flow extends Model
{
    protected $table = 'workflowpacks_flows';

    protected $fillable = ['name', 'mailbox_ids', 'active', 'once', 'draft', 'version_id', 'created_by', 'agent_id', 'pack_key', 'flow_key'];

    protected $casts = ['mailbox_ids' => 'array', 'draft' => 'array', 'active' => 'boolean', 'once' => 'boolean'];

    protected static function boot()
    {
        parent::boot();
        // switched on / off, published, deleted: the index of the triggers is rebuilt (Services\Engine\Listener)
        $forget = function () {
            \Modules\WorkflowPacks\Services\Engine\Listener::forget();
        };
        static::saved($forget);
        static::deleted($forget);
    }

    public function version()
    {
        return $this->belongsTo(FlowVersion::class, 'version_id');
    }

    /** Applies to this mailbox (no mailbox chosen: every mailbox). */
    public function covers($mailboxId)
    {
        $ids = array_map('intval', (array) $this->mailbox_ids);

        return !$ids || in_array((int) $mailboxId, $ids, true);
    }
}
