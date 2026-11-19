<?php

namespace Modules\WebPush\Providers;

/**
 * Test stub of the WebPush module (1.3.0): only its public buttons() ("Reply", "Assign to me"); not a real provider
 * (the stub has no module.json, FreeScout never loads it as a module).
 */
class WebPushServiceProvider
{
    public static $calls = 0;

    public static function buttons($conv, $user)
    {
        self::$calls++;
        $reply = ['action' => 'reply', 'title' => 'Reply', 'url' => $conv->url().'#webpush-reply'];

        return ['actions' => (int) $conv->user_id === (int) $user->id ? [$reply] : [['action' => 'assign', 'title' => 'Assign', 'token' => 't'.$user->id], $reply],
            'failed' => 'failed', 'other' => 'not a button'];
    }
}
