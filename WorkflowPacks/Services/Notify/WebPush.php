<?php

namespace Modules\WorkflowPacks\Services\Notify;

/**
 * Adapter to the WebPush module (github.com/altmenorg/freescout-webpush): push notifications to the devices of agents.
 * Workflow Packs sends nothing itself: it hands the notification to that module, which encrypts and delivers it.
 * Without the module (or not active), nothing is sent.
 *
 * Its public parts used, each one only when that version of the module has it (older and newer versions both work):
 *   Services\WebPush::sendToUsers([[user id, payload], …])   sending (required)
 *   Services\Settings::hideContent()                         "Hide the content": only the ticket number (locked screens)
 *   Services\Settings::notificationIcon() / notificationBadge()   icon and status bar badge chosen in its settings
 *   Providers\WebPushServiceProvider::buttons($conversation, $user)   "Reply" / "Assign to me" buttons
 *   Services\QuietHours::silences($user, $conversation)      quiet hours of each agent (versions that have them)
 */
class WebPush
{
    const MODULE = 'webpush';
    const SERVICE = '\Modules\WebPush\Services\WebPush';
    const SETTINGS = '\Modules\WebPush\Services\Settings';
    const PROVIDER = '\Modules\WebPush\Providers\WebPushServiceProvider';
    const QUIET = '\Modules\WebPush\Services\QuietHours';
    const MAX_TITLE = 120;
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
        $payload = self::payload($title, $text, $conversation);
        $quiet = self::has(self::QUIET, 'silences') ? self::QUIET : null;
        $buttons = self::has(self::PROVIDER, 'buttons') ? self::PROVIDER : null;
        $items = [];
        foreach ($users as $user) {
            if ($quiet && $quiet::silences($user, $conversation)) {
                continue;
            }
            $items[] = [(int) $user->id, $payload + ($buttons ? self::buttons($buttons, $conversation, $user) : [])];
        }
        if ($items) {
            $service = self::SERVICE;
            $service::sendToUsers($items);
        }

        return count($items);
    }

    /**
     * What every device shows: the title and text of the block, the link to the ticket; with "Hide the content" in the
     * settings of WebPush, only the ticket number (nothing readable on a locked screen); its icon and badge.
     */
    public static function payload($title, $text, $conversation)
    {
        $payload = [
            'title' => mb_substr((string) $title, 0, self::MAX_TITLE),
            'body'  => mb_substr((string) $text, 0, self::MAX_TEXT),
            'url'   => $conversation->url(),
            'tag'   => 'workflowpacks-'.$conversation->id,
        ];
        $settings = self::SETTINGS;
        if (self::has($settings, 'hideContent') && $settings::hideContent()) {
            $payload['title'] = '#'.($conversation->number ?: $conversation->id);
            $payload['body'] = \Lang::has('webpush::messages.new_activity')
                ? (string) __('webpush::messages.new_activity') : (string) __('workflowpacks::blocks.push_hidden');
        }
        foreach (['icon' => 'notificationIcon', 'badge' => 'notificationBadge'] as $key => $method) {
            if (self::has($settings, $method)) {
                $value = (string) $settings::$method();
                if ($value !== '') {
                    $payload[$key] = $value;
                }
            }
        }

        return $payload;
    }

    /** Its buttons for this agent ("actions", "failed"), or none when they cannot be made. */
    protected static function buttons($provider, $conversation, $user)
    {
        try {
            $buttons = $provider::buttons($conversation, $user);

            return is_array($buttons) ? array_intersect_key($buttons, array_flip(['actions', 'failed'])) : [];
        } catch (\Throwable $e) {
            \Log::warning('[WorkflowPacks] [push] buttons: '.$e->getMessage());

            return [];
        }
    }

    protected static function has($class, $method)
    {
        return class_exists($class) && method_exists($class, $method);
    }
}
