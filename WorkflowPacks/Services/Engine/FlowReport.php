<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowEvent;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Entities\FlowVersion;

/**
 * What the flows do, for the other modules (RefreshGlobal: statistics of the e-mails handled by each flow). Stable,
 * documented in docs/api-flux.md. Inside FreeScoot only (Eventy): no address, nothing sent outside.
 *
 *   \Eventy::addAction('workflowpacks.ticket_changed', function ($conversationId, array $change) {}, 20, 2)
 *   \Eventy::addAction('workflowpacks.temp_flow_finished', function ($flowId, array $summary) {}, 20, 2)
 *   \Eventy::filter('workflowpacks.info', [])                                  version, api, engine on
 *   \Eventy::filter('workflowpacks.ticket_changes', [], $conversationIds, $o)  [id => [change, …]]
 *   \Eventy::filter('workflowpacks.flow_stats', [], $o)                        tickets changed per flow over a period
 *   \Eventy::filter('workflowpacks.untreated', [], $o)                         tickets no flow has acted on
 *   \Eventy::filter('workflowpacks.temp_flow', [], $input)                     a temporary flow for a list of tickets
 *   \Eventy::filter('workflowpacks.temp_flow_status', [], $flowId)             where it is
 *
 * A change never carries the text of an e-mail or of a note: ids, the name of the flow and known values only.
 */
class FlowReport
{
    const API = 1;
    /** tickets read at once (ticket_changes) */
    const MAX_IDS = 1000;
    /** changes given back at once (ticket_changes) */
    const MAX_ROWS = 5000;
    /** changes looked at for the statistics, at most */
    const MAX_SCAN = 200000;
    const STATUSES = ['active', 'pending', 'closed', 'spam'];
    const FIELDS = ['status', 'assignee', 'folder', 'note', 'deleted', 'push'];
    const ORIGINS = [FlowRun::ORIGIN_NEW_MAIL, FlowRun::ORIGIN_REAPPLY, FlowRun::ORIGIN_AGENT, FlowRun::ORIGIN_TEMPORARY];

    public static function register()
    {
        // a module asking never breaks: an error is logged, the default comes back
        $safe = function (callable $read, $default) {
            try {
                return $read();
            } catch (\Throwable $e) {
                \Log::error('[WorkflowPacks] [flow report] '.$e->getMessage());

                return $default;
            }
        };
        \Eventy::addFilter('workflowpacks.info', function ($default = []) {
            return self::info();
        }, 20, 1);
        \Eventy::addFilter('workflowpacks.ticket_changes', function ($default = [], $ids = [], $o = []) use ($safe) {
            return $safe(function () use ($ids, $o, $default) {
                return self::ticketChanges((array) $ids, (array) $o) + (array) $default;
            }, (array) $default);
        }, 20, 3);
        \Eventy::addFilter('workflowpacks.flow_stats', function ($default = [], $o = []) use ($safe) {
            return $safe(function () use ($o) {
                return self::flowStats((array) $o);
            }, (array) $default);
        }, 20, 2);
        \Eventy::addFilter('workflowpacks.untreated', function ($default = [], $o = []) use ($safe) {
            return $safe(function () use ($o) {
                return TempFlows::untreated((array) $o);
            }, (array) $default);
        }, 20, 2);
        \Eventy::addFilter('workflowpacks.temp_flow', function ($default = [], $input = []) use ($safe) {
            return $safe(function () use ($input) {
                return TempFlows::fromApi((array) $input);
            }, ['ok' => false, 'error' => 'internal']);
        }, 20, 2);
        \Eventy::addFilter('workflowpacks.temp_flow_status', function ($default = [], $flowId = 0) use ($safe) {
            return $safe(function () use ($flowId) {
                return TempFlows::status((int) $flowId);
            }, ['ok' => false, 'error' => 'internal']);
        }, 20, 2);
    }

    public static function info()
    {
        return ['version' => \Modules\WorkflowPacks\Services\ModuleInfo::version(), 'api' => self::API, 'engine' => Engine::enabled(),
            'max_tickets' => TicketScope::MAX_IDS];
    }

    /** A step of a real run changed the ticket: each change told to the other modules (their errors never stop the flow). */
    public static function changed(FlowEvent $event, FlowRun $run, FlowVersion $version, $conversation)
    {
        $base = self::base($event, $run, $version ? (int) $version->number : null, (int) $conversation->mailbox_id);
        foreach ((array) $event->changes as $change) {
            $fact = self::fact($change);
            if (!$fact) {
                continue;
            }
            try {
                \Eventy::action('workflowpacks.ticket_changed', (int) $conversation->id, $base + $fact);
            } catch (\Throwable $e) {
                \Log::error('[WorkflowPacks] [flow report] ticket_changed: '.$e->getMessage());
            }
        }
    }

    /** What every change says: the ticket, the flow and its version, how the ticket entered it, when. */
    protected static function base(FlowEvent $event, FlowRun $run, $number, $mailboxId)
    {
        $origin = in_array($run->origin, self::ORIGINS, true) ? $run->origin : FlowRun::ORIGIN_NEW_MAIL;

        return ['v' => self::API, 'conversation_id' => (int) $event->conversation_id, 'mailbox_id' => $mailboxId ?: null,
            'flow_id' => (int) $event->flow_id, 'flow_name' => (string) ($run->flow_name ?? ''), 'version' => $number,
            'run_id' => (int) $event->run_id, 'event_id' => (int) $event->id, 'block' => (string) $event->block,
            'origin' => $origin, 'temporary' => $origin === FlowRun::ORIGIN_TEMPORARY,
            'at' => $event->created_at ? $event->created_at->format('Y-m-d H:i:s') : null];
    }

    /**
     * One change as stored ([field, shown before, shown after, raw before, raw after]) -> field, before, after with
     * known values only (never a text written by someone). Null: nothing to tell.
     */
    public static function fact($change)
    {
        if (!is_array($change) || !is_string($change[0] ?? null)) {
            return null;
        }
        $field = $change[0];
        $word = function ($v) {
            return in_array($v, self::STATUSES, true) ? $v : null;
        };
        $user = function ($v) {
            return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
        };
        $folder = function ($v) {
            return is_string($v) && trim($v) !== '' ? mb_substr($v, 0, 191) : null;
        };
        switch ($field) {
            case 'status':
                return ['field' => 'status', 'before' => $word($change[1] ?? null), 'after' => $word($change[2] ?? null)];
            case 'assignee':
                return ['field' => 'assignee', 'before' => $user($change[3] ?? null), 'after' => $user($change[4] ?? null)];
            case 'folder':
                return ['field' => 'folder', 'before' => $folder($change[1] ?? null), 'after' => $folder($change[2] ?? null)];
            case 'note':
                return ['field' => 'note', 'before' => null, 'after' => true];
            case 'deleted':
                return ['field' => 'deleted', 'before' => false, 'after' => true];
            case 'push':
                return ['field' => 'push', 'before' => null, 'after' => is_numeric($change[4] ?? null) ? (int) $change[4] : null];
        }

        // a block of another module: its name only, never its values
        return preg_match('/^[a-z0-9_.]{1,40}$/', $field) ? ['field' => $field, 'before' => null, 'after' => null] : null;
    }

    /**
     * The changes made by the flows on these tickets (at most MAX_IDS tickets, MAX_ROWS changes, oldest first).
     * $o: field (one of FIELDS) to keep only those.
     *
     * @return array [conversation id => [change, …]] (the same keys as the action workflowpacks.ticket_changed)
     */
    public static function ticketChanges(array $ids, array $o = [])
    {
        $ids = TicketScope::ids($ids, self::MAX_IDS);
        if (!$ids) {
            return [];
        }
        $field = in_array($o['field'] ?? null, self::FIELDS, true) ? $o['field'] : null;
        $events = FlowEvent::whereIn('conversation_id', $ids)->whereNotNull('changes')->orderBy('id')->limit(self::MAX_ROWS)->get();
        $runs = FlowRun::whereIn('id', $events->pluck('run_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $numbers = FlowVersion::whereIn('id', $runs->pluck('version_id')->unique()->all() ?: [0])->pluck('number', 'id');
        $mailboxes = \App\Conversation::whereIn('id', $ids)->pluck('mailbox_id', 'id');
        $names = self::names($events->pluck('flow_id')->unique()->all());
        $out = [];
        foreach ($events as $event) {
            $run = $runs[$event->run_id] ?? new FlowRun(['origin' => null, 'flow_name' => null]);
            if (!$run->flow_name) {
                $run->flow_name = $names[$event->flow_id] ?? '';
            }
            $base = self::base($event, $run, isset($numbers[$run->version_id]) ? (int) $numbers[$run->version_id] : null,
                (int) ($mailboxes[$event->conversation_id] ?? 0));
            foreach ((array) $event->changes as $change) {
                $fact = self::fact($change);
                if ($fact && (!$field || $fact['field'] === $field)) {
                    $out[(int) $event->conversation_id][] = $base + $fact;
                }
            }
        }

        return $out;
    }

    /**
     * Tickets changed by each flow over a period. $o: from, to ("Y-m-d" or "Y-m-d H:i:s"; the last 30 days by
     * default, at most Reapply::MAX_DAYS), mailboxes (ids), field (one of FIELDS), after (the value after, e.g.
     * "closed"), origins (some of ORIGINS). At most MAX_SCAN changes looked at (truncated: true beyond).
     *
     * @return array ['from', 'to', 'flows' => [['flow_id', 'flow_name', 'exists', 'temporary', 'tickets', 'changes']], 'scanned', 'truncated']
     */
    public static function flowStats(array $o)
    {
        $to = self::date($o['to'] ?? null, true) ?: \Carbon\Carbon::now();
        $from = self::date($o['from'] ?? null, false) ?: $to->copy()->subDays(30);
        if ($from->gt($to)) {
            list($from, $to) = [$to->copy(), $from->copy()];
        }
        if ($from->diffInDays($to) > Reapply::MAX_DAYS) {
            $from = $to->copy()->subDays(Reapply::MAX_DAYS);
        }
        $field = in_array($o['field'] ?? null, self::FIELDS, true) ? $o['field'] : null;
        $after = isset($o['after']) && is_scalar($o['after']) ? (string) $o['after'] : null;
        $origins = array_values(array_intersect(self::ORIGINS, (array) ($o['origins'] ?? [])));
        $mailboxes = TicketScope::clean(['mailboxes' => $o['mailboxes'] ?? []])['mailboxes'];

        $query = FlowEvent::whereNotNull('changes')->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        if ($mailboxes) {
            $query->whereIn('conversation_id', \App\Conversation::whereIn('mailbox_id', $mailboxes)->select('id'));
        }
        $flows = [];
        $runNames = [];
        $scanned = 0;
        $last = 0;
        while ($scanned < self::MAX_SCAN) {
            $events = (clone $query)->where('id', '>', $last)->orderBy('id')->limit(2000)->get(['id', 'run_id', 'flow_id', 'conversation_id', 'changes']);
            if ($events->isEmpty()) {
                break;
            }
            $missing = array_diff($events->pluck('run_id')->unique()->all(), array_keys($runNames));
            if ($missing) {
                foreach (FlowRun::whereIn('id', $missing)->get(['id', 'origin', 'flow_name']) as $run) {
                    $runNames[(int) $run->id] = [(string) $run->flow_name, in_array($run->origin, self::ORIGINS, true) ? $run->origin : FlowRun::ORIGIN_NEW_MAIL];
                }
            }
            foreach ($events as $event) {
                $last = (int) $event->id;
                $scanned++;
                list($name, $origin) = $runNames[(int) $event->run_id] ?? ['', FlowRun::ORIGIN_NEW_MAIL];
                if ($origins && !in_array($origin, $origins, true)) {
                    continue;
                }
                foreach ((array) $event->changes as $change) {
                    $fact = self::fact($change);
                    if (!$fact || ($field && $fact['field'] !== $field) || ($after !== null && self::text($fact['after']) !== $after)) {
                        continue;
                    }
                    $id = (int) $event->flow_id;
                    if (!isset($flows[$id])) {
                        $flows[$id] = ['flow_id' => $id, 'flow_name' => $name, 'temporary' => false, 'tickets' => [], 'changes' => 0];
                    }
                    $flows[$id]['flow_name'] = $name ?: $flows[$id]['flow_name'];
                    $flows[$id]['temporary'] = $flows[$id]['temporary'] || $origin === FlowRun::ORIGIN_TEMPORARY;
                    $flows[$id]['tickets'][(int) $event->conversation_id] = true;
                    $flows[$id]['changes']++;
                }
            }
        }
        $exists = Flow::whereIn('id', array_keys($flows) ?: [0])->pluck('name', 'id');
        $list = array_map(function ($f) use ($exists) {
            $f['exists'] = isset($exists[$f['flow_id']]);
            $f['flow_name'] = $f['flow_name'] !== '' ? $f['flow_name'] : (string) ($exists[$f['flow_id']] ?? '');
            $f['tickets'] = count($f['tickets']);

            return $f;
        }, array_values($flows));
        usort($list, function ($a, $b) {
            return $b['tickets'] <=> $a['tickets'] ?: $a['flow_id'] <=> $b['flow_id'];
        });

        return ['ok' => true, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s'), 'flows' => $list,
            'scanned' => $scanned, 'truncated' => $scanned >= self::MAX_SCAN];
    }

    /** A value as text, to compare it with "after" ("true", "closed", "12"). */
    protected static function text($value)
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** Names of flows that still exist (for the runs made before 3.20, which did not keep it). */
    protected static function names(array $flowIds)
    {
        return $flowIds ? Flow::whereIn('id', $flowIds)->pluck('name', 'id')->all() : [];
    }

    /** "Y-m-d" (start or end of that day) or "Y-m-d H:i:s"; anything else: null. */
    protected static function date($value, $endOfDay)
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $d = \Carbon\Carbon::createFromFormat('Y-m-d', $value);

                return $endOfDay ? $d->endOfDay() : $d->startOfDay();
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            }
        } catch (\Throwable $e) {
            // not a date
        }

        return null;
    }
}
