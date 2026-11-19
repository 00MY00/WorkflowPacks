<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * A flow of the internal engine: its blocks and links (draft), the published version that runs. A temporary flow
 * (Services\Engine\TempFlows) never starts on new e-mails: it handles its list of tickets once, then deletes itself.
 */
class Flow extends Model
{
    protected $table = 'workflowpacks_flows';

    protected $fillable = ['name', 'mailbox_ids', 'active', 'once', 'draft', 'version_id', 'created_by', 'agent_id', 'pack_key', 'flow_key', 'pack_name',
        'temporary', 'ticket_ids', 'expires_at', 'origin'];

    protected $casts = ['mailbox_ids' => 'array', 'draft' => 'array', 'active' => 'boolean', 'once' => 'boolean',
        'temporary' => 'boolean', 'ticket_ids' => 'array'];

    protected $dates = ['expires_at'];

    protected static function boot()
    {
        parent::boot();
        // switched on / off, published, deleted: the index of the triggers is rebuilt (Services\Engine\Listener)
        $forget = function () {
            \Modules\WorkflowPacks\Services\Engine\Listener::forget();
        };
        // a temporary flow is never switched on, whatever asks for it (editor, bulk action, agent)
        static::saving(function (Flow $flow) {
            if ($flow->temporary) {
                $flow->active = false;
            }
        });
        static::saved($forget);
        static::deleted($forget);
    }

    /** The flows that are not temporary (lists, exports, agents). */
    public function scopePermanent($query)
    {
        return $query->where(function ($q) {
            $q->where('temporary', false)->orWhereNull('temporary');
        });
    }

    /** The tickets of a temporary flow (ids). */
    public function tickets()
    {
        return array_values(array_filter(array_map('intval', (array) $this->ticket_ids)));
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

    /** Deleted with its versions; the tickets still waiting in it leave it (they stay as they are). */
    public function erase()
    {
        \DB::transaction(function () {
            FlowRun::where('flow_id', $this->id)->whereIn('state', [FlowRun::WAITING, FlowRun::RUNNING])->update(['state' => FlowRun::CANCELLED]);
            FlowVersion::where('flow_id', $this->id)->delete();
            $this->delete();
        });
    }
}
