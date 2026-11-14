<?php

namespace Modules\WorkflowPacks\Blocks\Contracts;

use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/**
 * A block of a flow (docs/moteur-autonome.md, 14). Everything else — the editor's fields, the ports of the diagram,
 * the "+" menu, the checks before publishing, packs, simulation — comes from this description. Adding a block = one
 * class implementing this (Blocks\AbstractBlock gives the usual defaults) + its texts in the 7 languages (blocks.php),
 * registered in Services\Engine\BlockRegistry or by the Eventy filter "workflowpacks.blocks".
 */
interface Block
{
    /** Stable key, kept in flows and packs: "action.status". Its texts are blocks.<family>.<name> (blocks.php). */
    public static function type();

    /** trigger | condition | wait | action | util */
    public static function family();

    /**
     * Parameters: [key => ['type' => text|textarea|choice|user|duration|variable|operator|value, 'values' => [..],
     * 'default' => .., 'required' => bool]].
     */
    public static function fields();

    /** Outputs (ports of the diagram): ['next'], ['yes', 'no'], ['next', 'error']… */
    public static function outputs();

    /** Variables made available to the next blocks: [key => ['type' => text|choice|bool|number, 'values' => [..]]]. */
    public static function variables(array $params);

    /** Title in the language of the user, from the parameters ("Wait 2 hours"). Never stored. */
    public static function title(array $params);

    /** Problems of the parameters: [field => translated message], [] when the block is complete. */
    public static function validate(array $params);

    /**
     * Does the work. In a dry run (simulation, test on a ticket) the Context changes nothing: its ticket actions only
     * say what they would do, so a block has one logic for both.
     */
    public function run(Context $ctx, array $params);
}
