<?php

namespace Modules\WorkflowPacks\Services\Ai;

/** A refusal of AgentTools: an HTTP status, a stable code ("not_found", "tickets_off"…) and the problems of a flow. */
class AgentToolError extends \RuntimeException
{
    public $status;
    public $codeName;
    public $problems;

    public function __construct($status, $code, array $problems = [])
    {
        parent::__construct($code);
        $this->status = (int) $status;
        $this->codeName = (string) $code;
        $this->problems = $problems;
    }
}
