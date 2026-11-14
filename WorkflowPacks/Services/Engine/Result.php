<?php

namespace Modules\WorkflowPacks\Services\Engine;

/** What a block gives back: the output to follow, a wait until a time, or an error. */
class Result
{
    const NEXT = 'next';
    const WAIT = 'wait';
    const ERROR = 'error';

    /** @var string next | wait | error */
    public $kind;
    /** @var string output to follow (next), or to follow on wake-up (wait) */
    public $output = 'next';
    /** @var \Carbon\Carbon|null */
    public $until;
    /** @var string the decision, in short, for the history */
    public $summary = '';
    /** @var bool error worth trying again (mail server down, quota…) */
    public $transient = false;

    public static function next($output = 'next', $summary = '')
    {
        $r = new self();
        $r->kind = self::NEXT;
        $r->output = $output;
        $r->summary = (string) $summary;

        return $r;
    }

    public static function wait($until, $summary = '', $output = 'next')
    {
        $r = new self();
        $r->kind = self::WAIT;
        $r->until = $until;
        $r->output = $output;
        $r->summary = (string) $summary;

        return $r;
    }

    public static function error($message, $transient = false)
    {
        $r = new self();
        $r->kind = self::ERROR;
        $r->output = 'error';
        $r->summary = (string) $message;
        $r->transient = (bool) $transient;

        return $r;
    }
}
