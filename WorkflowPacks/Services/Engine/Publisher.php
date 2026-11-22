<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowVersion;

/**
 * Draft -> published version: checked first (Definition::validate), then frozen. The tickets already in the flow keep
 * the version they started with.
 */
class Publisher
{
    /** @var BlockRegistry */
    protected $registry;

    public function __construct(?BlockRegistry $registry = null)
    {
        $this->registry = $registry ?: new BlockRegistry();
    }

    /**
     * @return array ['ok' => bool, 'problems' => [...], 'version' => FlowVersion|null]
     */
    public function publish(Flow $flow, $user = null)
    {
        $problems = Definition::from($flow->draft)->validate($this->registry);
        if ($problems) {
            return ['ok' => false, 'problems' => $problems, 'version' => null];
        }
        $version = \DB::transaction(function () use ($flow, $user) {
            $number = (int) FlowVersion::where('flow_id', $flow->id)->max('number') + 1;
            $version = FlowVersion::create(['flow_id' => $flow->id, 'number' => $number, 'data' => $flow->draft,
                'published_by' => $user ? $user->id : null]);
            $flow->update(['version_id' => $version->id]);

            return $version;
        });
        Listener::forget();

        return ['ok' => true, 'problems' => [], 'version' => $version];
    }
}
