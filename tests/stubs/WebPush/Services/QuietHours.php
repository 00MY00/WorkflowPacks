<?php

namespace Modules\WebPush\Services;

/** Test stub of the WebPush module: quiet hours of the users listed in $silent. */
class QuietHours
{
    public static $silent = [];

    public static function silences($user, $conversation = null, $now = null)
    {
        return in_array((int) $user->id, self::$silent, true);
    }
}
