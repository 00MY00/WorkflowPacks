<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowReapply;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Services\History;

/**
 * Temporary flows (docs/flux-internes.md, docs/api-flux.md): a flow made for a list of tickets — for instance the
 * tickets no flow has acted on ("untreated") — built in the editor (or given), launched once on that list, then deleted
 * by itself. It never starts on new e-mails, is never shown to the connected agents and never exported.
 *
 *   1. create  — the flow, as a draft, with its list (at most TicketScope::MAX_IDS tickets);
 *   2. launch  — published, then applied to its list by the scheduler, batch after batch (Reapply);
 *   3. cleanup — every minute: deleted once its run is done and no ticket waits in it any more, or at the latest when
 *                it expires (never launched: after DRAFT_DAYS; launched: RUN_DAYS later — the tickets still waiting
 *                then leave it, as when any flow is deleted). What it did stays in History and in the statistics.
 */
class TempFlows
{
    const DRAFT_DAYS = 7;
    const RUN_DAYS = 30;
    /** a draft given by another module: at most this size (JSON), as in the editor */
    const MAX_DRAFT = 262144;
    /** a short word saying who asked for it */
    const ORIGIN = '/^[a-z0-9_-]{1,40}$/';

    /**
     * The tickets of a list that can be handled: ids of published tickets that exist, each once, in order.
     * Null: more than TicketScope::MAX_IDS given.
     */
    public static function ids(array $values)
    {
        // a huge list is refused before being read
        if (count($values) > TicketScope::MAX_IDS * 2) {
            return null;
        }
        $ids = TicketScope::ids($values, TicketScope::MAX_IDS + 1);
        if (count($ids) > TicketScope::MAX_IDS) {
            return null;
        }
        $found = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (\App\Conversation::whereIn('id', $chunk)->where('state', \App\Conversation::STATE_PUBLISHED)->pluck('id') as $id) {
                $found[] = (int) $id;
            }
        }
        sort($found);

        return $found;
    }

    /** A new flow: only its trigger, the rest is built in the editor. */
    public static function skeleton()
    {
        return ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail', 'params' => [], 'x' => 40, 'y' => 120]], 'links' => []];
    }

    /**
     * The temporary flow of a list of tickets (already checked by ids()), as a draft. $o: name, draft (a clean
     * definition), origin.
     */
    public static function create(array $ticketIds, $user, array $o = [])
    {
        $origin = preg_match(self::ORIGIN, (string) ($o['origin'] ?? '')) ? $o['origin'] : null;
        $name = trim((string) ($o['name'] ?? '')) ?: __('workflowpacks::temp.default_name', ['date' => \Carbon\Carbon::now()->format('Y-m-d H:i')]);
        $flow = Flow::create(['name' => mb_substr($name, 0, 191), 'mailbox_ids' => [], 'active' => false, 'once' => true,
            'draft' => !empty($o['draft']) ? $o['draft'] : self::skeleton(), 'created_by' => $user ? $user->id : null,
            'temporary' => true, 'ticket_ids' => array_values($ticketIds), 'expires_at' => \Carbon\Carbon::now()->addDays(self::DRAFT_DAYS),
            'origin' => $origin]);
        History::add('flow', 'ok', ['subject' => $flow->name, 'message' => __('workflowpacks::temp.history_created', ['count' => count($ticketIds)])
            .($origin ? ' ('.$origin.')' : '')]);

        return $flow;
    }

    /** The options of its run: its list only, whatever the status or the date of each ticket. */
    public static function options(Flow $flow, array $o = [])
    {
        // never every ticket: a list with no ticket left matches none
        return ['scope' => FlowReapply::SCOPE_EXISTING, 'period' => 0, 'statuses' => TicketScope::STATUSES, 'ids' => $flow->tickets() ?: [0],
            'batch' => (int) ($o['batch'] ?? 50), 'every' => (int) ($o['every'] ?? 1)];
    }

    /** The run started: it may now live RUN_DAYS (tickets waiting in it), then it is deleted anyway. */
    public static function started(Flow $flow)
    {
        $flow->update(['expires_at' => \Carbon\Carbon::now()->addDays(self::RUN_DAYS)]);
    }

    /**
     * Published (when needed) and launched on its list. @return array ['ok' => true, 'job' => FlowReapply] or
     * ['ok' => false, 'error' => code, 'problems' => [...]]
     */
    public static function launch(Flow $flow, $user, $confirmRisky = false)
    {
        if (!$flow->temporary) {
            return ['ok' => false, 'error' => 'not_temporary', 'problems' => []];
        }
        if (Reapply::current($flow)) {
            return ['ok' => false, 'error' => 'already_running', 'problems' => []];
        }
        $service = new Reapply();
        $o = self::options($flow);
        $estimate = $service->estimate($flow, $o, false);
        if ($estimate['problems']) {
            return ['ok' => false, 'error' => 'invalid_flow', 'problems' => array_slice($estimate['problems'], 0, 10)];
        }
        if ($estimate['risky'] && !$confirmRisky) {
            return ['ok' => false, 'error' => 'confirm_risky_required', 'problems' => $estimate['risky']];
        }
        if (!$flow->version_id) {
            $published = (new Publisher())->publish($flow, $user);
            if (!$published['ok']) {
                return ['ok' => false, 'error' => 'invalid_flow', 'problems' => array_slice(array_column($published['problems'], 'message'), 0, 10)];
            }
            $flow = $flow->fresh(['version']);
        }
        $job = $service->start($flow, $o, $user);
        if (!$job) {
            return ['ok' => false, 'error' => 'nothing_to_do', 'problems' => []];
        }
        self::started($flow);

        return ['ok' => true, 'job' => $job];
    }

    /**
     * Every minute (scheduler): the temporary flows whose work is over, or that expired, deleted.
     * @return int flows deleted
     */
    public static function cleanup()
    {
        $deleted = 0;
        foreach (Flow::where('temporary', true)->orderBy('id')->limit(100)->get() as $flow) {
            $expired = $flow->expires_at && $flow->expires_at->isPast();
            $done = !$expired && FlowReapply::where('flow_id', $flow->id)->where('state', FlowReapply::DONE)->exists()
                && !Reapply::current($flow)
                && !FlowRun::where('flow_id', $flow->id)->whereIn('state', [FlowRun::WAITING, FlowRun::RUNNING])->exists();
            if (!$expired && !$done) {
                continue;
            }
            $summary = ['flow_id' => (int) $flow->id, 'flow_name' => (string) $flow->name, 'origin' => $flow->origin,
                'tickets' => count($flow->tickets()), 'handled' => FlowRun::where('flow_id', $flow->id)->count(),
                'reason' => $done ? 'done' : 'expired'];
            $flow->erase();
            $deleted++;
            History::add('flow', 'ok', ['subject' => $summary['flow_name'],
                'message' => __('workflowpacks::temp.history_'.$summary['reason'], ['count' => $summary['handled'], 'total' => $summary['tickets']])]);
            try {
                \Eventy::action('workflowpacks.temp_flow_finished', $summary['flow_id'], $summary);
            } catch (\Throwable $e) {
                \Log::error('[WorkflowPacks] [engine] temp_flow_finished: '.$e->getMessage());
            }
        }

        return $deleted;
    }

    /** Where a temporary flow is (for the other modules): draft, running, paused, stopped, waiting, done or deleted. */
    public static function status($flowId)
    {
        $flow = Flow::where('temporary', true)->find((int) $flowId);
        if (!$flow) {
            return ['ok' => true, 'flow_id' => (int) $flowId, 'state' => 'deleted'];
        }
        $job = FlowReapply::where('flow_id', $flow->id)->latest('id')->first();
        $waiting = FlowRun::where('flow_id', $flow->id)->where('state', FlowRun::WAITING)->count();
        $state = !$job ? 'draft' : ($job->state === FlowReapply::DONE ? ($waiting ? 'waiting' : 'done') : $job->state);

        return ['ok' => true, 'flow_id' => (int) $flow->id, 'flow_name' => (string) $flow->name, 'state' => $state,
            'tickets' => count($flow->tickets()), 'total' => $job ? (int) $job->total : 0, 'done' => $job ? (int) $job->done : 0,
            'started' => $job ? (int) $job->started : 0, 'waiting' => $waiting,
            'expires_at' => $flow->expires_at ? $flow->expires_at->format('Y-m-d H:i:s') : null,
            'edit_url' => route('workflowpacks.flows', ['flow' => $flow->id]), 'launch_url' => route('workflowpacks.reapply', ['id' => $flow->id])];
    }

    /**
     * The tickets no flow has acted on (no change written by a flow, ever): in these mailboxes, statuses and last
     * days. The filters are those of TicketScope (checked there).
     */
    public static function untreatedQuery(array $f)
    {
        $q = \App\Conversation::where('state', \App\Conversation::STATE_PUBLISHED);
        TicketScope::apply($q, TicketScope::clean($f));
        $events = (new \Modules\WorkflowPacks\Entities\FlowEvent())->getTable();
        $q->whereNotExists(function ($sub) use ($events) {
            $sub->select(\DB::raw(1))->from($events)->whereColumn($events.'.conversation_id', 'conversations.id')->whereNotNull($events.'.changes');
        });

        return $q;
    }

    /**
     * The untreated tickets for another module: their number and ids (at most $o['limit'], 1 to MAX_IDS, 1000 by
     * default). $o: mailboxes, statuses (active, pending by default), period (days back, 30 by default; 0: all).
     */
    public static function untreated(array $o)
    {
        $period = Reapply::period($o['period'] ?? 30);
        $f = TicketScope::clean(['mailboxes' => $o['mailboxes'] ?? [], 'statuses' => $o['statuses'] ?? ['active', 'pending'],
            'period' => $period === null ? 30 : $period]);
        $limit = max(1, min(TicketScope::MAX_IDS, (int) ($o['limit'] ?? 1000)));
        $q = self::untreatedQuery($f);
        $total = (clone $q)->count();
        $ids = (clone $q)->orderBy('id')->limit($limit)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        return ['ok' => true, 'total' => $total, 'ids' => $ids, 'truncated' => $total > count($ids),
            'filters' => ['mailboxes' => $f['mailboxes'], 'statuses' => $f['statuses'], 'period' => $f['period']]];
    }

    /**
     * A temporary flow asked by another module (\Eventy::filter('workflowpacks.temp_flow', null, $input)), see
     * docs/api-flux.md. Only for an administrator: the one logged in or, when nobody is (scheduler, command line),
     * the active administrator named by user_id.
     */
    public static function fromApi(array $in)
    {
        if (!Engine::enabled()) {
            return self::error('engine_off');
        }
        $user = self::caller($in);
        if (!$user) {
            return self::error('forbidden');
        }
        $ids = self::ids((array) ($in['ticket_ids'] ?? []));
        if ($ids === null) {
            return self::error('too_many_tickets', ['max' => TicketScope::MAX_IDS]);
        }
        if (!$ids) {
            return self::error('no_tickets');
        }
        $draft = null;
        if (!empty($in['from_flow'])) {
            $source = Flow::permanent()->with('version')->find((int) $in['from_flow']);
            if (!$source) {
                return self::error('flow_not_found');
            }
            $draft = Definition::clean($source->version ? (array) $source->version->data : (array) ($source->draft ?: []));
        } elseif (isset($in['draft'])) {
            if (!is_array($in['draft']) || strlen((string) json_encode($in['draft'])) > self::MAX_DRAFT) {
                return self::error('invalid_flow', ['problems' => [__('workflowpacks::flows.too_big')]]);
            }
            $draft = Definition::clean($in['draft']);
        }
        $flow = self::create($ids, $user, ['name' => $in['name'] ?? '', 'draft' => $draft, 'origin' => $in['origin'] ?? '']);
        $out = ['ok' => true, 'job' => null] + self::status($flow->id);
        if (!empty($in['launch'])) {
            $launched = self::launch($flow, $user, !empty($in['confirm_risky']));
            if (!$launched['ok']) {
                // kept as a draft: the administrator finishes it in the editor (edit_url)
                return ['ok' => false, 'error' => $launched['error'], 'problems' => $launched['problems']] + self::status($flow->id);
            }

            return ['ok' => true] + self::status($flow->id) + ['job' => ['total' => (int) $launched['job']->total]];
        }

        return $out;
    }

    /** The administrator asking (see fromApi()); null: refused. */
    protected static function caller(array $in)
    {
        $user = auth()->check() ? auth()->user() : (!empty($in['user_id']) ? \App\User::find((int) $in['user_id']) : null);

        return $user && $user->isAdmin() && (int) $user->status === \App\User::STATUS_ACTIVE ? $user : null;
    }

    protected static function error($code, array $more = [])
    {
        return ['ok' => false, 'error' => $code] + $more;
    }
}
