<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;

/**
 * FreeScout's events -> the flows whose trigger listens to them. An index (event -> flows) is kept in the cache and
 * rebuilt when a flow is published or switched on / off: an event no flow listens to costs no query. Never breaks
 * FreeScout: an error is logged, the e-mail is received as usual.
 *
 *   conversation.created_by_customer   (app/Thread.php, app/Console/Commands/FetchEmails.php)   -> new_mail
 */
class Listener
{
    const CACHE = 'workflowpacks.engine.index';

    public static function register()
    {
        if (!Engine::enabled()) {
            return;
        }
        \Eventy::addAction('conversation.created_by_customer', function ($conversation) {
            self::fire('new_mail', $conversation);
        }, 30, 1);
    }

    /** The flows listening to $event in the ticket's mailbox, each one started for this ticket. */
    public static function fire($event, $conversation, ?Engine $engine = null)
    {
        try {
            $ids = self::index()[$event] ?? [];
            if (!$ids || !$conversation) {
                return 0;
            }
            $engine = $engine ?: new Engine();
            $started = 0;
            foreach (Flow::with('version')->whereIn('id', $ids)->where('active', true)->get() as $flow) {
                if ($flow->covers($conversation->mailbox_id) && $engine->start($flow, $conversation)) {
                    $started++;
                }
            }

            return $started;
        } catch (\Throwable $e) {
            \Log::error('[WorkflowPacks] [engine] '.$event.': '.$e->getMessage());

            return 0;
        }
    }

    /** @return array event => [flow ids] (active flows with a published version) */
    public static function index()
    {
        return \Cache::rememberForever(self::CACHE, function () {
            $registry = new BlockRegistry();
            $index = [];
            foreach (Flow::with('version')->where('active', true)->whereNotNull('version_id')->get() as $flow) {
                $trigger = $flow->version ? Definition::from($flow->version->data)->trigger($registry) : null;
                $class = $trigger ? $registry->get($trigger['type']) : null;
                if ($class && method_exists($class, 'event')) {
                    $index[$class::event()][] = $flow->id;
                }
            }

            return $index;
        });
    }

    public static function forget()
    {
        \Cache::forget(self::CACHE);
    }
}
