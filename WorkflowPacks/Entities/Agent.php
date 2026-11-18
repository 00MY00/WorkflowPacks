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

    protected $fillable = ['name', 'token_hash', 'token_hint', 'mode', 'mailbox_ids', 'anonymise', 'can_publish', 'can_activate', 'can_see_all', 'can_publish_others', 'can_delete', 'permissions', 'read_only', 'allowed_ips',
        'last_ip', 'expires_at', 'last_used_at', 'revoked_at', 'created_by'];

    protected $casts = ['mailbox_ids' => 'array', 'anonymise' => 'boolean', 'can_publish' => 'boolean', 'can_activate' => 'boolean', 'can_see_all' => 'boolean', 'can_publish_others' => 'boolean', 'can_delete' => 'boolean', 'permissions' => 'array', 'read_only' => 'boolean', 'allowed_ips' => 'array'];

    /**
     * Rights of an agent: an action on its own flows ("own.…") or on the other flows of its mailboxes ("others.…").
     * Seeing and testing its own flows is always allowed; acting on another flow needs that flow to run only on its
     * mailboxes (checked by Services\Ai\AgentTools).
     */
    const PERMISSIONS = ['own.propose', 'own.edit', 'own.rename', 'own.delete', 'own.activate', 'own.publish',
        'others.see', 'others.test', 'others.edit', 'others.rename', 'others.delete', 'others.activate', 'others.publish'];

    /** The actions that change something (an agent without any of them only reads). */
    const WRITES = ['own.propose', 'own.edit', 'own.rename', 'own.delete', 'own.activate', 'own.publish',
        'others.edit', 'others.rename', 'others.delete', 'others.activate', 'others.publish'];

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
                'others.delete', 'others.activate', 'others.publish'], $rights)];
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
