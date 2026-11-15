<?php

namespace Modules\WorkflowPacks\Services\Notify;

/**
 * Adapter to the WebPush module (github.com/altmenorg/freescout-webpush): push notifications to the devices of agents.
 * Workflow Packs sends nothing itself: it hands the notification to that module, which encrypts and delivers it,
 * and respects each agent's quiet hours. Without the module (or not active), nothing is sent.
 */
class WebPush
{
    const MODULE = 'webpush';
    const SERVICE = '\Modules\WebPush\Services\WebPush';
    const QUIET = '\Modules\WebPush\Services\QuietHours';
    const MAX_TEXT = 500;

    public static function available()
    {
        return \App\Module::isActive(self::MODULE) && class_exists(self::SERVICE) && method_exists(self::SERVICE, 'sendToUsers');
    }

    /**
     * @param \App\User[]       $users
     * @param \App\Conversation $conversation
     * @return int number of agents the notification was handed over for (quiet hours excluded)
     */
    public static function send($users, $title, $text, $conversation)
    {
        if (!self::available()) {
            return 0;
        }
        $service = self::SERVICE;
        $quiet = class_exists(self::QUIET) && method_exists(self::QUIET, 'silences') ? self::QUIET : null;
        $payload = [
            'title' => mb_substr((string) $title, 0, 120),
            'body'  => mb_substr((string) $text, 0, self::MAX_TEXT),
            'url'   => $conversation->url(),
            'tag'   => 'workflowpacks-'.$conversation->id,
        ];
        $items = [];
        foreach ($users as $user) {
            if (!$quiet || !$quiet::silences($user, $conversation)) {
                $items[] = [(int) $user->id, $payload];
            }
        }
        if ($items) {
            $service::sendToUsers($items);
        }

        return count($items);
    }
}
