<?php

namespace Modules\WebPush\Services;

/**
 * Test stub of the WebPush module (github.com/altmenorg/freescout-webpush): its public sending method and the list of
 * the devices of an agent. $devices: [user id => number of devices] (1 when not given); $refused: devices that do not
 * accept (the push service answers an error).
 */
class WebPush
{
    public static $sent = [];
    public static $devices = [];
    public static $refused = 0;

    /** @param array $items [[user_id, ['title', 'body', 'url', 'tag']], ...] @return int devices that accepted */
    public static function sendToUsers(array $items)
    {
        $accepted = 0;
        foreach ($items as $item) {
            self::$sent[] = $item;
            $accepted += count(self::devices($item[0]));
        }

        return max(0, $accepted - self::$refused);
    }

    public static function devices($userId)
    {
        $count = self::$devices[(int) $userId] ?? 1;
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['id' => 'd'.$userId.'-'.$i, 'label' => 'Test', 'created' => null, 'endpoint' => 'https://push.invalid/'.$userId.'/'.$i];
        }

        return $out;
    }
}
