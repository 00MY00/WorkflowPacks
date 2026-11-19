<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Services\MailTags;

/**
 * Which existing tickets a flow is applied to (Reapply): mailboxes, statuses, period, assigned user (or nobody) in the
 * query; tags afterwards, by batches of ids, from FreeScout's Tags module or else from the e-mail tags of RefreshGlobal.
 * Every value is checked here (whitelists, integers): the filters may come from an agent.
 */
class TicketScope
{
    const STATUSES = ['active', 'pending', 'closed'];
    /** "nobody assigned" among the assigned users */
    const UNASSIGNED = 0;
    const MAX_TAGS = 20;
    /** at most this many tickets are looked at to count those having a tag */
    const MAX_SCAN = 50000;
    /** a list of tickets (temporary flows): at most this many */
    const MAX_IDS = 5000;

    /** The filters, cleaned: unknown statuses dropped, integers, tags normalised. */
    public static function clean(array $f)
    {
        return [
            'mailboxes' => array_values(array_unique(array_map('intval', array_filter((array) ($f['mailboxes'] ?? []), 'is_numeric')))),
            'statuses'  => array_values(array_intersect(self::STATUSES, (array) ($f['statuses'] ?? []))),
            'period'    => max(0, (int) ($f['period'] ?? 0)),
            'assignees' => self::assignees((array) ($f['assignees'] ?? [])),
            'tags'      => self::tags((array) ($f['tags'] ?? [])),
            // a list of tickets given (temporary flow): only those; a list with no valid id matches none ([0]); no
            // list (or an empty one, as cleaned before): no restriction
            'ids'       => !empty($f['ids']) ? (self::ids((array) $f['ids']) ?: [0]) : [],
        ];
    }

    /** Ticket ids: positive integers, each once, in order, at most $max (MAX_IDS). */
    public static function ids(array $values, $max = self::MAX_IDS)
    {
        $ids = [];
        foreach ($values as $v) {
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                $ids[(int) $v] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return array_slice($ids, 0, (int) $max);
    }

    /** User ids, 0 for "nobody assigned" ("unassigned" accepted). */
    public static function assignees(array $values)
    {
        $out = [];
        foreach ($values as $v) {
            if ($v === 'unassigned' || (is_numeric($v) && (int) $v === self::UNASSIGNED)) {
                $out[] = self::UNASSIGNED;
            } elseif (is_numeric($v) && (int) $v > 0) {
                $out[] = (int) $v;
            }
        }

        return array_values(array_unique($out));
    }

    /** Tags in lower case, short, at most MAX_TAGS. */
    public static function tags(array $values)
    {
        $out = [];
        foreach ($values as $v) {
            $tag = mb_substr(MailTags::normalize(is_scalar($v) ? (string) $v : ''), 0, 60);
            if ($tag !== '') {
                $out[] = $tag;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, self::MAX_TAGS);
    }

    /** Where the tags of a ticket come from: "tags" (FreeScout's Tags module), "mail" (RefreshGlobal) or null. */
    public static function tagSource()
    {
        try {
            if (\Schema::hasTable('conversation_tag') && \Schema::hasTable('tags')) {
                return 'tags';
            }
        } catch (\Throwable $e) {
            // no database information: no tags module
        }

        return MailTags::available() ? 'mail' : null;
    }

    /** The query part of the filters (everything but the tags). */
    public static function apply($query, array $f)
    {
        $statuses = array_values(array_filter(array_map(function ($s) {
            return array_search($s, Context::STATUS, true);
        }, $f['statuses'])));
        $query->whereIn('status', $statuses ?: [0]);
        if ($f['mailboxes']) {
            $query->whereIn('mailbox_id', $f['mailboxes']);
        }
        if (!empty($f['ids'])) {
            $query->whereIn('id', $f['ids']);
        }
        if ($f['period'] > 0) {
            $query->where('created_at', '>=', \Carbon\Carbon::now()->subDays($f['period']));
        }
        if ($f['assignees']) {
            $users = array_values(array_diff($f['assignees'], [self::UNASSIGNED]));
            $nobody = in_array(self::UNASSIGNED, $f['assignees'], true);
            $query->where(function ($q) use ($users, $nobody) {
                if ($users) {
                    $q->whereIn('user_id', $users);
                }
                if ($nobody) {
                    $q->orWhereNull('user_id')->orWhere('user_id', 0);
                }
            });
        }

        return $query;
    }

    /** Of these tickets, the ones having at least one of the tags (all of them when no tag is asked). */
    public static function withTags(array $ids, array $tags)
    {
        $ids = array_values(array_map('intval', $ids));
        if (!$tags || !$ids) {
            return $ids;
        }
        $has = [];
        $source = self::tagSource();
        if ($source === 'tags') {
            $rows = \DB::table('conversation_tag')->join('tags', 'tags.id', '=', 'conversation_tag.tag_id')
                ->whereIn('conversation_tag.conversation_id', $ids)->get(['conversation_tag.conversation_id', 'tags.name']);
            foreach ($rows as $row) {
                if (in_array(MailTags::normalize($row->name), $tags, true)) {
                    $has[(int) $row->conversation_id] = true;
                }
            }
        } elseif ($source === 'mail') {
            $all = \Eventy::filter('refreshglobal.mail_tags', [], $ids);
            foreach ($ids as $id) {
                $mine = array_map([MailTags::class, 'normalize'], is_array($all[$id] ?? null) ? $all[$id] : []);
                if (array_intersect($tags, $mine)) {
                    $has[$id] = true;
                }
            }
        }

        return array_values(array_filter($ids, function ($id) use ($has) {
            return isset($has[$id]);
        }));
    }

    /** How many tickets of a query have the tags (looked at by batches, at most MAX_SCAN). */
    public static function count($query, array $tags)
    {
        if (!$tags) {
            return $query->count();
        }
        $count = 0;
        $after = 0;
        $seen = 0;
        while ($seen < self::MAX_SCAN) {
            $ids = (clone $query)->where('id', '>', $after)->orderBy('id')->limit(1000)->pluck('id')->all();
            if (!$ids) {
                break;
            }
            $count += count(self::withTags($ids, $tags));
            $after = (int) end($ids);
            $seen += count($ids);
        }

        return $count;
    }
}
