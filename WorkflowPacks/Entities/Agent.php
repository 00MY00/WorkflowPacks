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

    protected $fillable = ['name', 'token_hash', 'token_hint', 'mode', 'mailbox_ids', 'anonymise', 'can_publish', 'read_only', 'allowed_ips',
        'last_ip', 'expires_at', 'last_used_at', 'revoked_at', 'created_by'];

    protected $casts = ['mailbox_ids' => 'array', 'anonymise' => 'boolean', 'can_publish' => 'boolean', 'read_only' => 'boolean', 'allowed_ips' => 'array'];

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

    /** "203.0.113.7", "203.0.113.0/24", "2001:db8::/32": a valid address or range, else null. */
    public static function range($text)
    {
        $text = trim((string) $text);
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
