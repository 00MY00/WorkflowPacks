<?php

namespace Modules\WebPush\Services;

/** Test stub of the WebPush module (github.com/altmenorg/freescout-webpush): its public sending method only. */
class WebPush
{
    public static $sent = [];

    /** @param array $items [[user_id, ['title', 'body', 'url', 'tag']], ...] */
    public static function sendToUsers(array $items)
    {
        foreach ($items as $item) {
            self::$sent[] = $item;
        }

        return count($items);
    }
}
