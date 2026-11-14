<?php

namespace Modules\WorkflowPacks\Services\Engine;

/**
 * A flow as data: blocks {id, type, params} and links {from, port, to}. Read from a draft or a published version;
 * checked before publishing and when a pack is imported (validate()).
 */
class Definition
{
    const MAX_BLOCKS = 200;
    const ID = '/^[A-Za-z0-9_-]{1,40}$/';

    /** @var array id => ['id', 'type', 'params'] */
    public $blocks = [];
    /** @var array "from|port" => to */
    public $links = [];
    /** @var array problems found while reading */
    protected $read = [];

    public static function from($data)
    {
        $def = new self();
        $data = is_array($data) ? $data : [];
        foreach (array_values((array) ($data['blocks'] ?? [])) as $i => $block) {
            $id = is_array($block) ? (string) ($block['id'] ?? '') : '';
            if (!preg_match(self::ID, $id) || isset($def->blocks[$id])) {
                $def->read[] = ['block' => null, 'field' => 'blocks['.$i.'].id', 'message' => __('workflowpacks::blocks.errors.id')];
                continue;
            }
            $def->blocks[$id] = ['id' => $id, 'type' => (string) ($block['type'] ?? ''), 'params' => (array) ($block['params'] ?? [])];
        }
        foreach (array_values((array) ($data['links'] ?? [])) as $i => $link) {
            $from = (string) ($link['from'] ?? '');
            $port = (string) ($link['port'] ?? 'next');
            $to = (string) ($link['to'] ?? '');
            if (!isset($def->blocks[$from], $def->blocks[$to]) || isset($def->links[$from.'|'.$port])) {
                $def->read[] = ['block' => $from ?: null, 'field' => 'links['.$i.']', 'message' => __('workflowpacks::blocks.errors.link')];
                continue;
            }
            $def->links[$from.'|'.$port] = $to;
        }

        return $def;
    }

    public function block($id)
    {
        return $this->blocks[$id] ?? null;
    }

    /** Block reached by an output, or null (end of this branch). */
    public function next($id, $port)
    {
        return $this->links[$id.'|'.$port] ?? null;
    }

    public function trigger(BlockRegistry $registry)
    {
        foreach ($this->blocks as $block) {
            $class = $registry->get($block['type']);
            if ($class && $class::family() === 'trigger') {
                return $block;
            }
        }

        return null;
    }

    /** @return array [[from, to]] */
    protected function edges()
    {
        $out = [];
        foreach ($this->links as $key => $to) {
            $out[] = [explode('|', $key)[0], $to];
        }

        return $out;
    }

    /**
     * Problems that prevent publishing: [['block' => id|null, 'field' => key|null, 'message' => text]]. Unknown type,
     * incomplete parameters, a link from an output the block does not have, a block not reached from the trigger, a
     * loop without a wait (it would run forever), a variable not made by a block before.
     */
    public function validate(BlockRegistry $registry)
    {
        $problems = $this->read;
        $add = function ($block, $field, $message) use (&$problems) {
            $problems[] = ['block' => $block, 'field' => $field, 'message' => $message];
        };
        if (!$this->blocks || count($this->blocks) > self::MAX_BLOCKS) {
            $add(null, 'blocks', __('workflowpacks::blocks.errors.count', ['max' => self::MAX_BLOCKS]));

            return $problems;
        }
        $triggers = 0;
        foreach ($this->blocks as $id => $block) {
            $class = $registry->get($block['type']);
            if (!$class) {
                $add($id, 'type', __('workflowpacks::blocks.errors.type', ['type' => $block['type']]));
                continue;
            }
            $triggers += $class::family() === 'trigger' ? 1 : 0;
            foreach ($class::validate($block['params']) as $field => $message) {
                $add($id, $field, $message);
            }
            // a variable tested here must be made by a block before, on every path
            $var = $block['params']['var'] ?? null;
            if ($var !== null && $class::family() === 'condition') {
                $available = $this->variablesBefore($id, $registry);
                if (!isset($available[$var])) {
                    $add($id, 'var', __('workflowpacks::blocks.errors.var'));
                }
            }
        }
        if ($triggers !== 1) {
            $add(null, 'blocks', __('workflowpacks::blocks.errors.trigger'));
        }
        foreach ($this->links as $key => $to) {
            list($from, $port) = explode('|', $key);
            $class = $registry->get($this->blocks[$from]['type']);
            if ($class && !in_array($port, $class::outputs(), true)) {
                $add($from, 'links', __('workflowpacks::blocks.errors.port', ['port' => $port]));
            }
            $toClass = $registry->get($this->blocks[$to]['type']);
            if ($toClass && $toClass::family() === 'trigger') {
                $add($to, 'links', __('workflowpacks::blocks.errors.into_trigger'));
            }
        }
        $trigger = $this->trigger($registry);
        if ($trigger) {
            $reached = $this->reachable($trigger['id']);
            foreach ($this->blocks as $id => $block) {
                if (!isset($reached[$id])) {
                    $add($id, null, __('workflowpacks::blocks.errors.unreached'));
                }
            }
        }
        foreach ($this->loopsWithoutWait($registry) as $id) {
            $add($id, null, __('workflowpacks::blocks.errors.loop'));
        }

        return $problems;
    }

    protected function reachable($start)
    {
        $seen = [$start => true];
        $todo = [$start];
        while ($todo) {
            $id = array_pop($todo);
            foreach ($this->edges() as $edge) {
                if ($edge[0] === $id && !isset($seen[$edge[1]])) {
                    $seen[$edge[1]] = true;
                    $todo[] = $edge[1];
                }
            }
        }

        return $seen;
    }

    /** Blocks on a loop that never waits: the engine would go round and round (it also stops after 50 blocks). */
    protected function loopsWithoutWait(BlockRegistry $registry)
    {
        $next = [];
        foreach ($this->edges() as $edge) {
            $class = $registry->get($this->blocks[$edge[0]]['type']);
            if (!$class || $class::family() !== 'wait') {
                $next[$edge[0]][] = $edge[1];
            }
        }
        $state = [];
        $found = [];
        $visit = function ($id, array $path) use (&$visit, &$state, &$found, $next) {
            $state[$id] = 1;
            $path[] = $id;
            foreach ($next[$id] ?? [] as $to) {
                if (($state[$to] ?? 0) === 1) {
                    $found = array_merge($found, array_slice($path, array_search($to, $path, true)));
                } elseif (!isset($state[$to])) {
                    $visit($to, $path);
                }
            }
            $state[$id] = 2;
        };
        foreach (array_keys($this->blocks) as $id) {
            if (!isset($state[$id])) {
                $visit($id, []);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Variables a block can use: made by the blocks before it. ['partial' => true]: made on some paths only (it may
     * be empty there). Fixed point over the links, so loops are fine.
     *
     * @return array key => definition (type, values) + partial
     */
    public function variablesBefore($id, BlockRegistry $registry)
    {
        $made = [];
        foreach ($this->blocks as $bid => $block) {
            $class = $registry->get($block['type']);
            $made[$bid] = $class ? $class::variables($block['params']) : [];
        }
        $preds = [];
        foreach ($this->edges() as $edge) {
            $preds[$edge[1]][] = $edge[0];
        }
        $all = [];   // on every path
        $any = [];   // on some path
        foreach (array_keys($this->blocks) as $bid) {
            $all[$bid] = null;
            $any[$bid] = [];
        }
        for ($round = 0, $changed = true; $changed && $round < 400; $round++) {
            $changed = false;
            foreach (array_keys($this->blocks) as $bid) {
                $in = null;
                $union = [];
                foreach ($preds[$bid] ?? [] as $p) {
                    $out = array_keys($made[$p]);
                    $outAll = $all[$p] === null ? null : array_unique(array_merge($all[$p], $out));
                    if ($outAll !== null) {
                        $in = $in === null ? $outAll : array_values(array_intersect($in, $outAll));
                    }
                    $union = array_unique(array_merge($union, $any[$p], $out));
                }
                $in = $in ?? (empty($preds[$bid]) ? [] : null);
                if ($in !== null && ($all[$bid] === null || count($in) !== count($all[$bid]) || array_diff($in, $all[$bid]))) {
                    $all[$bid] = array_values($in);
                    $changed = true;
                }
                if (array_diff($union, $any[$bid])) {
                    $any[$bid] = array_values($union);
                    $changed = true;
                }
            }
        }
        $defs = [];
        foreach ($made as $vars) {
            $defs += $vars;
        }
        $out = [];
        foreach ($any[$id] ?? [] as $key) {
            $out[$key] = ($defs[$key] ?? ['type' => 'text']) + ['partial' => !in_array($key, (array) $all[$id], true)];
        }

        return $out;
    }
}
