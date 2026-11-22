<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Blocks\Contracts\Block;

/**
 * The types of blocks. Another module adds its own with the Eventy filter "workflowpacks.blocks" (list of classes
 * implementing Blocks\Contracts\Block); a class that does not is ignored.
 */
class BlockRegistry
{
    const BUILT_IN = [
        \Modules\WorkflowPacks\Blocks\Triggers\NewMail::class,
        \Modules\WorkflowPacks\Blocks\Conditions\Ticket::class,
        \Modules\WorkflowPacks\Blocks\Conditions\Similar::class,
        \Modules\WorkflowPacks\Blocks\Conditions\MailTag::class,
        \Modules\WorkflowPacks\Blocks\Waits\Duration::class,
        \Modules\WorkflowPacks\Blocks\Actions\SetStatus::class,
        \Modules\WorkflowPacks\Blocks\Actions\Assign::class,
        \Modules\WorkflowPacks\Blocks\Actions\Note::class,
        \Modules\WorkflowPacks\Blocks\Actions\ImapFolder::class,
        \Modules\WorkflowPacks\Blocks\Actions\Push::class,
        \Modules\WorkflowPacks\Blocks\Actions\Delete::class,
    ];

    const FAMILIES = ['trigger', 'condition', 'wait', 'action', 'util'];

    /** @var array type => class */
    protected $types;

    public function __construct(array $classes = null)
    {
        $classes = $classes ?? (array) \Eventy::filter('workflowpacks.blocks', self::BUILT_IN);
        $this->types = [];
        foreach ($classes as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, Block::class)) {
                $this->types[$class::type()] = $class;
            }
        }
    }

    public function has($type)
    {
        return isset($this->types[(string) $type]);
    }

    /** @return string|null class of the type */
    public function get($type)
    {
        return $this->types[(string) $type] ?? null;
    }

    public function make($type)
    {
        $class = $this->get($type);

        return $class ? new $class() : null;
    }

    public function types()
    {
        return $this->types;
    }

    /** Description of every block for the editor (one call, cached by the browser): no list of types in the JS. */
    public function catalog()
    {
        $out = [];
        foreach ($this->types as $type => $class) {
            $out[] = [
                'type'    => $type,
                'family'  => $class::family(),
                'name'    => __('workflowpacks::blocks.'.$type.'.name'),
                'help'    => __('workflowpacks::blocks.'.$type.'.help'),
                'fields'  => $class::fields(),
                'outputs' => array_map(function ($o) {
                    return ['key' => $o, 'label' => __('workflowpacks::blocks.outputs.'.$o)];
                }, $class::outputs()),
            ];
        }

        return $out;
    }
}
