<?php

namespace Modules\WebPush\Services;

/** Test stub of the WebPush module (1.3.0): the settings read by Workflow Packs, set by the tests. */
class Settings
{
    public static $hide = false;
    public static $icon = '';
    public static $badge = '';

    public static function hideContent()
    {
        return (bool) self::$hide;
    }

    public static function notificationIcon()
    {
        return self::$icon;
    }

    public static function notificationBadge()
    {
        return self::$badge;
    }
}
