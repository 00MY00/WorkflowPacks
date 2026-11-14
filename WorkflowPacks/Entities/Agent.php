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

    protected $fillable = ['name', 'token_hash', 'token_hint', 'mode', 'mailbox_ids', 'anonymise', 'can_publish', 'expires_at', 'last_used_at',
        'revoked_at', 'created_by'];

    protected $casts = ['mailbox_ids' => 'array', 'anonymise' => 'boolean', 'can_publish' => 'boolean'];

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

    public function mayRead($mailboxId)
    {
        return in_array((int) $mailboxId, array_map('intval', (array) $this->mailbox_ids), true);
    }
}
