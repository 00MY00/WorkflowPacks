<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowEvent;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Entities\FlowVersion;

/**
 * Runs a ticket through a flow: block after block, following the outputs, until a wait (the run sleeps with its
 * wake-up time, Scheduler wakes it) or the end. The engine knows no type of block: it only follows what they return.
 * The same walk serves the simulation (dry run, virtual clock, nothing written).
 */
class Engine
{
    const OPTION = 'workflowpacks.engine';
    /** blocks run in one go, at most (a loop without a wait is refused at publishing; this is the last guard) */
    const MAX_STEPS = 50;
    /** waits before trying a block again after a temporary error (minutes) */
    const RETRY = [1, 10, 60];

    /** @var BlockRegistry */
    protected $registry;

    public function __construct(BlockRegistry $registry = null)
    {
        $this->registry = $registry ?: new BlockRegistry();
    }

    public static function enabled()
    {
        // on by default: with no active flow it does nothing (an event no flow listens to costs no query)
        return (bool) \App\Option::get(self::OPTION, true, true, false);
    }

    public function registry()
    {
        return $this->registry;
    }

    /**
     * A ticket enters a flow (its trigger matched). Null: not started (already went through it, "once per ticket").
     * $origin: how it entered (FlowRun::ORIGIN_*), told to the other modules with each change.
     */
    public function start(Flow $flow, $conversation, $origin = FlowRun::ORIGIN_NEW_MAIL)
    {
        $version = $flow->version;
        if (!$version) {
            return null;
        }
        if ($flow->once && FlowRun::where('flow_id', $flow->id)->where('conversation_id', $conversation->id)->exists()) {
            return null;
        }
        $def = Definition::from($version->data);
        $trigger = $def->trigger($this->registry);
        if (!$trigger) {
            return null;
        }
        $run = FlowRun::create(['flow_id' => $flow->id, 'version_id' => $version->id, 'conversation_id' => $conversation->id,
            'block' => $trigger['id'], 'state' => FlowRun::RUNNING, 'vars' => [], 'origin' => mb_substr((string) $origin, 0, 20),
            'flow_name' => mb_substr((string) $flow->name, 0, 191)]);
        $this->continueRun($run, $version, $conversation, null);

        return $run;
    }

    /** A sleeping run, claimed by the Scheduler: it goes on from its wait (or tries its block again after an error). */
    public function wake(FlowRun $run)
    {
        $version = FlowVersion::find($run->version_id);
        $conversation = \App\Conversation::find($run->conversation_id);
        // the ticket is gone (deleted, merged): nothing to do any more
        if (!$version || !$conversation || (int) $conversation->state === \App\Conversation::STATE_DELETED) {
            $run->update(['state' => FlowRun::CANCELLED, 'claimed_at' => null]);

            return;
        }
        $this->continueRun($run, $version, $conversation, $run->resume);
    }

    protected function continueRun(FlowRun $run, FlowVersion $version, $conversation, $resume)
    {
        $ctx = Context::forConversation($conversation, (array) $run->vars);
        $ctx->actor = self::actor($version);
        $def = Definition::from($version->data);
        $log = function ($block, Result $r, array $changes, $level) use ($run, $version, $conversation) {
            $event = FlowEvent::create(['run_id' => $run->id, 'flow_id' => $run->flow_id, 'conversation_id' => $run->conversation_id,
                'block' => $block, 'output' => $r->output, 'summary' => mb_substr($r->summary, 0, 255), 'changes' => $changes ?: null,
                'level' => $level]);
            // the other modules are told what the flow changed (RefreshGlobal: statistics); never a dry run
            if ($changes) {
                FlowReport::changed($event, $run, $version, $conversation);
            }
        };
        $end = $this->walk($def, $ctx, $run->block, $resume, (int) $run->attempts, $log);
        if ($end['state'] === FlowRun::FAILED) {
            $flow = Flow::find($run->flow_id);
            $last = FlowEvent::where('run_id', $run->id)->where('level', 3)->orderBy('id', 'desc')->value('summary');
            \Modules\WorkflowPacks\Services\History::add('flow', 'failed', ['mailbox_id' => $conversation->mailbox_id,
                'conversation_id' => $conversation->id, 'subject' => $flow ? $flow->name : '#'.$run->flow_id, 'message' => $last]);
        }
        $run->fill([
            'block'      => $end['block'],
            'state'      => $end['state'],
            'wake_at'    => $end['wake_at'],
            'resume'     => $end['resume'],
            'attempts'   => $end['attempts'],
            'vars'       => $ctx->vars,
            'claimed_at' => null,
        ])->save();
    }

    /**
     * The walk itself, shared by the real runs and the simulation. $resume: output to follow from $blockId before
     * running anything (end of a wait); null: run $blockId.
     *
     * @param callable $log function ($blockId, Result, array $changes, int $level)
     * @return array state, block, wake_at, resume, attempts
     */
    public function walk(Definition $def, Context $ctx, $blockId, $resume, $attempts, callable $log)
    {
        $out = ['state' => FlowRun::DONE, 'block' => $blockId, 'wake_at' => null, 'resume' => null, 'attempts' => 0];
        if ($resume !== null) {
            $blockId = $def->next($blockId, $resume);
            $out['block'] = $blockId ?: $out['block'];
            if (!$blockId) {
                return $out;
            }
        }
        for ($steps = 0; $steps < self::MAX_STEPS; $steps++) {
            $block = $def->block($blockId);
            $instance = $block ? $this->registry->make($block['type']) : null;
            if (!$instance) {
                $log($blockId, Result::error(__('workflowpacks::blocks.errors.type', ['type' => $block['type'] ?? '?'])), [], 3);

                return ['state' => FlowRun::FAILED] + $out;
            }
            $ctx->changes = [];
            try {
                $r = $instance->run($ctx, $block['params']);
            } catch (\Throwable $e) {
                \Log::error('[WorkflowPacks] [engine] block '.$block['type'].': '.$e->getMessage());
                $r = Result::error(__('workflowpacks::blocks.errors.internal'), true);
            }
            if ($r->kind === Result::WAIT) {
                $log($blockId, $r, $ctx->changes, 1);

                return ['state' => FlowRun::WAITING, 'block' => $blockId, 'wake_at' => $r->until, 'resume' => $r->output, 'attempts' => 0];
            }
            if ($r->kind === Result::ERROR) {
                $log($blockId, $r, $ctx->changes, 3);
                // temporary: the same block again later; else the "error" output, if it leads somewhere
                if ($r->transient && $attempts < count(self::RETRY)) {
                    return ['state' => FlowRun::WAITING, 'block' => $blockId, 'wake_at' => $ctx->now->copy()->addMinutes(self::RETRY[$attempts]),
                        'resume' => null, 'attempts' => $attempts + 1];
                }
                $next = $def->next($blockId, 'error');
                if (!$next) {
                    return ['state' => FlowRun::FAILED, 'block' => $blockId] + $out;
                }
                $blockId = $next;
                $attempts = 0;
                continue;
            }
            $log($blockId, $r, $ctx->changes, 1);
            $attempts = 0;
            $next = $def->next($blockId, $r->output);
            if (!$next) {
                return ['state' => FlowRun::DONE, 'block' => $blockId] + $out;
            }
            $blockId = $next;
        }
        $log($blockId, Result::error(__('workflowpacks::blocks.errors.steps', ['max' => self::MAX_STEPS])), [], 3);

        return ['state' => FlowRun::FAILED, 'block' => $blockId] + $out;
    }

    /**
     * Simulation: the flow from its trigger, for a ticket state (sample e-mail or copy of a ticket), in a dry run with
     * a virtual clock (waits are passed at once). Nothing is written, nothing is sent. $ctx: a real ticket (in a
     * dry run), else the state $ticket.
     *
     * @return array ['events' => [[at (seconds), block, output, summary, changes, level, ticket]], 'end' => state]
     */
    public function simulate(Definition $def, array $ticket, $maxSeconds = 31536000, Context $ctx = null)
    {
        $start = \Carbon\Carbon::now();
        // a real ticket can be given (its own Context), always in a dry run: nothing written
        $ctx = $ctx ?: new Context($ticket, [], true, $start->copy());
        $ctx->dryRun = true;
        $ctx->now = $start->copy();
        $trigger = $def->trigger($this->registry);
        $events = [];
        if (!$trigger) {
            return ['events' => [], 'end' => FlowRun::FAILED];
        }
        $log = function ($block, Result $r, array $changes, $level) use (&$events, $ctx, $start) {
            $events[] = ['at' => $ctx->now->diffInSeconds($start), 'block' => $block, 'output' => $r->output, 'summary' => $r->summary,
                'changes' => $changes, 'level' => $level, 'ticket' => $ctx->ticket];
        };
        $state = ['state' => FlowRun::RUNNING, 'block' => $trigger['id'], 'resume' => null, 'attempts' => 0];
        for ($i = 0; $i < 100; $i++) {
            $state = $this->walk($def, $ctx, $state['block'], $state['resume'], $state['attempts'], $log);
            if ($state['state'] !== FlowRun::WAITING || $state['wake_at']->diffInSeconds($start) > $maxSeconds) {
                break;
            }
            $ctx->now = $state['wake_at']->copy();
        }

        return ['events' => $events, 'end' => $state['state']];
    }

    /** The changes are made as the publisher of the version (else the first administrator). */
    public static function actor(FlowVersion $version)
    {
        $user = $version->published_by ? \App\User::find($version->published_by) : null;

        return $user ?: \App\User::where('role', \App\User::ROLE_ADMIN)->where('status', \App\User::STATUS_ACTIVE)->orderBy('id')->first();
    }
}
