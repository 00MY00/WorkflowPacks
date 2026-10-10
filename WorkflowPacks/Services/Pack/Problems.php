<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * List of problems found in a pack, each with the place in the file ("workflows[2].conditions.all[0]") and an
 * explanation. Used by the validator, the variable resolver and the compiler (no Laravel dependency).
 */
class Problems
{
    /** @var array[] */
    protected $items = [];

    public function add($path, $code, $message)
    {
        $this->items[] = ['path' => (string) $path, 'code' => (string) $code, 'message' => (string) $message];

        return $this;
    }

    public function merge(Problems $other)
    {
        foreach ($other->all() as $item) {
            $this->items[] = $item;
        }

        return $this;
    }

    public function all()
    {
        return $this->items;
    }

    public function count()
    {
        return count($this->items);
    }

    public function isEmpty()
    {
        return !$this->items;
    }

    /** Plain lines, e.g. for the console. */
    public function lines()
    {
        return array_map(function ($p) {
            return '['.$p['code'].'] '.($p['path'] !== '' ? $p['path'].' : ' : '').$p['message'];
        }, $this->items);
    }
}
