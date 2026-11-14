<?php

namespace Modules\WorkflowPacks\Blocks\Contracts;

/** A block that starts a flow when FreeScout tells of something (Services\Engine\Listener). */
interface Trigger extends Block
{
    /** Event of the engine it listens to: new_mail | customer_replied | user_replied | status_changed | user_changed. */
    public static function event();
}
