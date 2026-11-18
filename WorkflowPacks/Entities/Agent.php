<?php

namespace Modules\WorkflowPacks\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * An AI agent connected by the API. Its token is shown once, at its creation, then only its SHA-256 fingerprint is
 * kept. "once": closed after its first proposal or at its expiry (24 h); "continuous": until it is disconnected.
 */
class Agent extends Model
{
    const ONCE = 'once';
    const CONTINUOUS = 'continuous';
    const ONCE_HOURS = 24;
    const PREFIX = 'wpa_';

    protected $table = 'workflowpacks_agents';

    protected $fillable = ['name', 'token_hash', 'token_hint', 'mode', 'mailbox_ids', 'anonymise', 'can_publish', 'can_activate', 'can_see_all', 'can_publish_others', 'can_delete', 'permissions', 'reapply_limits', 'read_only', 'allowed_ips',
        'last_ip', 'expires_at', 'last_used_at', 'revoked_at', 'created_by'];

    protected $casts = ['mailbox_ids' => 'array', 'anonymise' => 'boolean', 'can_publish' => 'boolean', 'can_activate' => 'boolean', 'can_see_all' => 'boolean', 'can_publish_others' => 'boolean', 'can_delete' => 'boolean', 'permissions' => 'array', 'reapply_limits' => 'array', 'read_only' => 'boolean', 'allowed_ips' => 'array'];

    /**
     * Rights of an agent: an action on its own flows ("own.…") or on the other flows of its mailboxes ("others.…").
     * Seeing and testing its own flows is always allowed; acting on another flow needs that flow to run only on its
     * mailboxes (checked by Services\Ai\AgentTools).
     */
    const PERMISSIONS = ['own.propose', 'own.edit', 'own.rename', 'own.delete', 'own.activate', 'own.deactivate', 'own.publish', 'own.reapply',
        'others.see', 'others.test', 'others.edit', 'others.rename', 'others.delete', 'others.activate', 'others.deactivate', 'others.publish',
        'others.reapply'];

    /** The actions that change something (an agent without any of them only reads). */
    const WRITES = ['own.propose', 'own.edit', 'own.rename', 'own.delete', 'own.activate', 'own.deactivate', 'own.publish', 'own.reapply',
        'others.edit', 'others.rename', 'others.delete', 'others.activate', 'others.deactivate', 'others.publish', 'others.reapply'];

    /** Numbers of tickets an agent may apply a flow to at once (limit chosen by the administrator). */
    const REAPPLY_MAX = [100, 500, 1000, 5000, 20000];

    /** Limits of "apply to the existing tickets" when none were chosen: active and pending, 30 days, 500 tickets. */
    const REAPPLY_DEFAULTS = ['statuses' => ['active', 'pending'], 'period_max' => 30, 'tags' => [], 'assignees' => [], 'max_tickets' => 500, 'risky' => false];

    /** Its rights (the matrix; an agent saved before 3.17 without it: its former check boxes). */
    public function rights()
    {
        return is_array($this->permissions) ? $this->permissions : self::legacyRights($this->getAttributes());
    }

    public function may($permission)
    {
        return in_array($permission, $this->rights(), true);
    }

    /** The former check boxes (read only, publish, switch on after a test, delete, see / act on the others) as rights. */
    public static function legacyRights(array $flags)
    {
        $on = function ($key) use ($flags) {
            return !empty($flags[$key]);
        };
        $rights = [];
        if (!$on('read_only')) {
            $rights = ['own.propose', 'own.edit', 'own.rename'];
            foreach (['can_activate' => 'activate', 'can_publish' => 'publish', 'can_delete' => 'delete'] as $flag => $action) {
                if ($on($flag)) {
                    $rights[] = 'own.'.$action;
                }
            }
        }
        if ($on('can_see_all')) {
            $rights[] = 'others.see';
        }
        if ($on('can_publish_others') && !$on('read_only')) {
            $rights[] = 'others.test';
            $rights[] = 'others.rename';
            foreach (['can_activate' => 'activate', 'can_publish' => 'publish', 'can_delete' => 'delete'] as $flag => $action) {
                if ($on($flag)) {
                    $rights[] = 'others.'.$action;
                }
            }
        }

        return $rights;
    }

    /** The former check boxes kept in step with the rights (older reports, an older version brought back). */
    public static function legacyFlags(array $rights)
    {
        $has = function ($p) use ($rights) {
            return in_array($p, $rights, true);
        };

        return ['read_only' => !array_intersect(self::WRITES, $rights), 'can_publish' => $has('own.publish') || $has('others.publish'),
            'can_activate' => $has('own.activate') || $has('others.activate'), 'can_delete' => $has('own.delete') || $has('others.delete'),
            'can_see_all' => $has('others.see'), 'can_publish_others' => (bool) array_intersect(['others.test', 'others.edit', 'others.rename',
                'others.delete', 'others.activate', 'others.deactivate', 'others.publish', 'others.reapply'], $rights)];
    }

    /** Its limits for applying a flow to the existing tickets (always valid values). */
    public function reapplyLimits()
    {
        return self::cleanLimits(is_array($this->reapply_limits) ? $this->reapply_limits : []);
    }

    /**
     * Limits of "apply to the existing tickets", from a form or as saved: statuses among active / pending / closed, a
     * period offered, tags (none: any), users that exist (0: nobody assigned; none: any), a number of tickets offered.
     */
    public static function cleanLimits(array $in)
    {
        $d = self::REAPPLY_DEFAULTS;
        $scope = \Modules\WorkflowPacks\Services\Engine\TicketScope::class;
        $period = (int) ($in['period_max'] ?? $d['period_max']);
        $max = (int) ($in['max_tickets'] ?? $d['max_tickets']);
        $users = array_values(array_diff($scope::assignees((array) ($in['assignees'] ?? [])), [$scope::UNASSIGNED]));
        $known = $users ? \App\User::whereIn('id', $users)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all() : [];
        $assignees = $scope::assignees((array) ($in['assignees'] ?? []));
        $tags = is_array($in['tags'] ?? null) ? $in['tags'] : preg_split('/\s*,\s*/', (string) ($in['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        return [
            'statuses'    => array_values(array_intersect($scope::STATUSES, (array) ($in['statuses'] ?? $d['statuses']))),
            'period_max'  => in_array($period, \Modules\WorkflowPacks\Services\Engine\Reapply::PERIODS, true) ? $period : $d['period_max'],
            'tags'        => $scope::tags($tags),
            'assignees'   => array_values(array_filter($assignees, function ($id) use ($known, $scope) {
                return $id === $scope::UNASSIGNED || in_array($id, $known, true);
            })),
            'max_tickets' => in_array($max, self::REAPPLY_MAX, true) ? $max : $d['max_tickets'],
            'risky'       => !empty($in['risky']),
        ];
    }

    /** Lengths of access offered for a continuous agent (days; 0: until it is disconnected). */
    const DAYS = [7, 30, 90, 365, 0];

    protected $dates = ['expires_at', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    /** A new agent and its token (the only time the token exists in clear). @return array [Agent, token] */
    public static function issue(array $attributes)
    {
        $token = self::PREFIX.bin2hex(random_bytes(24));
        $agent = self::create($attributes + [
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, 0, 10),
            'expires_at' => ($attributes['mode'] ?? '') === self::ONCE ? \Carbon\Carbon::now()->addHours(self::ONCE_HOURS) : null,
        ]);

        return [$agent, $token];
    }

    /** The agent of a token, if it may still be used. */
    public static function byToken($token)
    {
        if (!is_string($token) || strpos($token, self::PREFIX) !== 0 || strlen($token) > 100) {
            return null;
        }
        $agent = self::where('token_hash', hash('sha256', $token))->first();

        return $agent && $agent->usable() ? $agent : null;
    }

    public function usable()
    {
        return !$this->revoked_at && (!$this->expires_at || $this->expires_at->isFuture());
    }

    public function revoke()
    {
        if (!$this->revoked_at) {
            $this->update(['revoked_at' => \Carbon\Carbon::now()]);
        }
    }

    /** May this address use the token? No list: any address. */
    public function allowsIp($ip)
    {
        $list = array_filter((array) $this->allowed_ips);
        if (!$list) {
            return true;
        }
        foreach ($list as $range) {
            if (self::inRange((string) $ip, (string) $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "203.0.113.7", "203.0.113.0/24", "2001:db8::/32", "192.168.1.10 - 192.168.1.50": a valid address or range (written
     * back in a standard way), else null.
     */
    public static function range($text)
    {
        $text = trim((string) $text);
        if (strpos($text, '-') !== false) {
            $ends = array_map('trim', explode('-', $text, 2));
            $a = @inet_pton($ends[0]);
            $b = @inet_pton($ends[1]);
            if ($a === false || $b === false || strlen($a) !== strlen($b) || strcmp($a, $b) > 0) {
                return null;
            }

            return inet_ntop($a).'-'.inet_ntop($b);
        }
        $parts = explode('/', $text, 2);
        $bin = @inet_pton($parts[0]);
        if ($bin === false || $text === '') {
            return null;
        }
        if (isset($parts[1])) {
            $max = strlen($bin) * 8;
            if (!ctype_digit($parts[1]) || (int) $parts[1] > $max) {
                return null;
            }
        }

        return inet_ntop($bin).(isset($parts[1]) ? '/'.(int) $parts[1] : '');
    }

    public static function inRange($ip, $range)
    {
        // "start-end": compared byte by byte (addresses of the same family, written the same length)
        if (strpos($range, '-') !== false) {
            $ends = explode('-', $range, 2);
            $x = @inet_pton($ip);
            $a = @inet_pton($ends[0]);
            $b = @inet_pton($ends[1]);

            return $x !== false && $a !== false && $b !== false && strlen($x) === strlen($a) && strlen($a) === strlen($b)
                && strcmp($x, $a) >= 0 && strcmp($x, $b) <= 0;
        }
        $parts = explode('/', $range, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($parts[0]);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($a) * 8;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if (!$rest) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (substr($a, $bytes, 1) & $mask) === (substr($b, $bytes, 1) & $mask);
    }

    public function mayRead($mailboxId)
    {
        return in_array((int) $mailboxId, array_map('intval', (array) $this->mailbox_ids), true);
    }
}
