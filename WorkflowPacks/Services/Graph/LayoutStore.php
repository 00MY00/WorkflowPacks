<?php

namespace Modules\WorkflowPacks\Services\Graph;

use Modules\WorkflowPacks\Entities\Layout;

/**
 * Positions of the blocks and display choices of the diagram, per user and per view. Saved live by the browser
 * (partial updates: only the moved blocks are sent). Everything is checked: keys, coordinates, choices.
 */
class LayoutStore
{
    const KEY = '/^(mb|wf|tpl|pack|dest):[A-Za-z0-9._:\/-]{1,210}$/';
    const MAX_NODES = 5000;
    const LIMIT = 100000;
    const SETTINGS = [
        'layout' => ['columns', 'radial', 'grid', 'free'],
        'edges'  => ['orthogonal', 'curved', 'straight'],
        'packs'  => [true, false],
        'dests'  => [true, false],
    ];
    const DEFAULTS = ['layout' => 'columns', 'edges' => 'orthogonal', 'packs' => true, 'dests' => true];

    public function get($user, $view = 'main')
    {
        $row = Layout::where('user_id', $user->id)->where('view', $this->view($view))->first();

        return [
            'positions' => $row ? (json_decode($row->positions, true) ?: []) : [],
            'settings'  => ($row ? (json_decode((string) $row->settings, true) ?: []) : []) + self::DEFAULTS,
            'sizes'     => $row ? (json_decode((string) $row->sizes, true) ?: []) : [],
        ];
    }

    /** Blocks enlarged by hand: width and height within these bounds (the text always fits, see textfit.js). */
    const MIN_SIZE = [120, 40];
    const MAX_SIZE = [900, 600];

    /**
     * @param array $positions node key => [x, y] (merged with the saved ones; null removes a position)
     * @return array|string the saved layout, or the reason of the refusal
     */
    public function save($user, $view, array $positions, array $settings = [], $replace = false, array $sizes = [])
    {
        $current = $this->get($user, $view);
        $merged = $replace ? [] : $current['positions'];
        foreach ($positions as $key => $xy) {
            if (!is_string($key) || !preg_match(self::KEY, $key)) {
                return 'invalid key';
            }
            if ($xy === null) {
                unset($merged[$key]);
                continue;
            }
            if (!is_array($xy) || count($xy) !== 2 || !is_numeric($xy[0] ?? null) || !is_numeric($xy[1] ?? null)
                || abs($xy[0]) > self::LIMIT || abs($xy[1]) > self::LIMIT) {
                return 'invalid position';
            }
            $merged[$key] = [round((float) $xy[0], 1), round((float) $xy[1], 1)];
        }
        if (count($merged) > self::MAX_NODES) {
            return 'too many nodes';
        }
        // sizes chosen by hand (null = back to the automatic size); kept when the blocks are rearranged
        $newSizes = $current['sizes'];
        foreach ($sizes as $key => $wh) {
            if (!is_string($key) || !preg_match(self::KEY, $key)) {
                return 'invalid key';
            }
            if ($wh === null) {
                unset($newSizes[$key]);
                continue;
            }
            if (!is_array($wh) || count($wh) !== 2 || !is_numeric($wh[0] ?? null) || !is_numeric($wh[1] ?? null)
                || $wh[0] < self::MIN_SIZE[0] || $wh[0] > self::MAX_SIZE[0] || $wh[1] < self::MIN_SIZE[1] || $wh[1] > self::MAX_SIZE[1]) {
                return 'invalid size';
            }
            $newSizes[$key] = [(int) round($wh[0]), (int) round($wh[1])];
        }
        if (count($newSizes) > self::MAX_NODES) {
            return 'too many nodes';
        }
        $newSettings = $current['settings'];
        foreach ($settings as $name => $value) {
            if (!isset(self::SETTINGS[$name]) || !in_array($value, self::SETTINGS[$name], true)) {
                return 'invalid setting';
            }
            $newSettings[$name] = $value;
        }
        Layout::updateOrCreate(['user_id' => $user->id, 'view' => $this->view($view)], [
            'positions' => json_encode($merged ?: new \stdClass()),
            'settings'  => json_encode($newSettings),
            'sizes'     => json_encode($newSizes ?: new \stdClass()),
        ]);

        return ['positions' => $merged, 'settings' => $newSettings, 'sizes' => $newSizes];
    }

    protected function view($view)
    {
        return preg_match('/^[a-z0-9_-]{1,50}$/', (string) $view) ? (string) $view : 'main';
    }
}
