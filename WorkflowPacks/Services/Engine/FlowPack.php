<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;

/**
 * Packs of flows for the internal engine (docs/flux-internes.md):
 *   {"format": "workflowpacks-flows", "version": 1, "key", "name", "description", "pack_version",
 *    "params": [{"key", "label", "description", "required"}], "flows": [{"key", "name", "description", "once", "blocks", "links"}]}
 * A single flow (the answer of the AI assistant, "workflowpacks-flow") is read as a pack of one flow.
 * {{param.<key>}} in the parameters of the blocks is replaced by the value given at the import (plain text).
 * Imported flows are drafts, switched off. No duplicates: a flow already here (same pack and key, else same name) is
 * left as it is when identical, else its draft is updated (its mailboxes kept); only the missing ones are created.
 * No limit on the number of flows; only the size of the file (memory of the server). Keys written otherwise
 * ("Mon Pack") are turned into valid ones instead of refusing the pack.
 */
class FlowPack
{
    const FORMAT = 'workflowpacks-flows';
    const MAX_BYTES = 8388608;   // 8 MB
    const KEY = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    /** @var BlockRegistry */
    protected $registry;

    public function __construct(BlockRegistry $registry = null)
    {
        $this->registry = $registry ?: new BlockRegistry();
    }

    /**
     * @param array $values values of the parameters (key => text)
     * @return array ['ok', 'problems' => [texts], 'pack' => {key, name, description, version}, 'params' => [...],
     *               'flows' => [{key, name, description, once, draft, problems, existing (id|null), same (bool)}]]
     */
    public function read($json, array $values = [])
    {
        $fail = function ($message) {
            return ['ok' => false, 'problems' => [$message], 'pack' => null, 'params' => [], 'flows' => []];
        };
        $json = trim((string) $json);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $json, $m)) {
            $json = $m[1];
        }
        if (strlen($json) > self::MAX_BYTES) {
            return $fail(__('workflowpacks::flowpack.too_big', ['max' => self::MAX_BYTES / 1048576]));
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $fail(__('workflowpacks::flowpack.not_json'));
        }
        // one flow alone (the answer of the AI assistant): a pack of one flow
        if (($data['format'] ?? '') !== self::FORMAT && isset($data['blocks'])) {
            $name = trim((string) ($data['name'] ?? '')) ?: 'flow';
            $data = ['format' => self::FORMAT, 'version' => 1, 'key' => self::slug($name), 'name' => $name,
                'flows' => [['key' => self::slug($name)] + $data]];
        }
        if (($data['format'] ?? '') !== self::FORMAT || (int) ($data['version'] ?? 0) !== 1) {
            return $fail(__('workflowpacks::flowpack.bad_format'));
        }
        $key = self::key((string) ($data['key'] ?? ''), (string) ($data['name'] ?? ''));
        $flows = array_values(array_filter((array) ($data['flows'] ?? []), 'is_array'));
        if (!$flows) {
            return $fail(__('workflowpacks::flowpack.no_flows'));
        }
        $params = [];
        foreach (array_values(array_filter((array) ($data['params'] ?? []), 'is_array')) as $p) {
            if (preg_match(self::KEY, (string) ($p['key'] ?? ''))) {
                $params[] = ['key' => $p['key'], 'label' => mb_substr((string) ($p['label'] ?? $p['key']), 0, 191),
                    'description' => mb_substr((string) ($p['description'] ?? ''), 0, 500), 'required' => !empty($p['required'])];
            }
        }
        $replace = [];
        foreach ($params as $p) {
            $replace['{{param.'.$p['key'].'}}'] = mb_substr(trim((string) ($values[$p['key']] ?? '')), 0, 500);
        }
        // the flows already here: same pack and key first, else same name (a pack exported under another name, a
        // flow made here then exported, the same answer of the AI pasted twice); each one matched once at most
        $here = Flow::orderBy('id')->get(['id', 'name', 'pack_key', 'flow_key', 'draft']);
        $used = [];
        $out = [];
        $seen = [];
        foreach ($flows as $f) {
            // same key twice: the next ones get -2, -3… (always in the same order, so a new import finds them again)
            $base = self::key((string) ($f['key'] ?? ''), (string) ($f['name'] ?? ''));
            for ($fkey = $base, $n = 2; isset($seen[$fkey]); $n++) {
                $fkey = substr($base, 0, 95).'-'.$n;
            }
            $seen[$fkey] = true;
            $draft = \Modules\WorkflowPacks\Services\Ai\Answer::place(self::draft($f, $replace));
            $out[] = [
                'key'         => $fkey,
                'name'        => mb_substr(trim((string) ($f['name'] ?? $fkey)), 0, 191),
                'description' => mb_substr(trim((string) ($f['description'] ?? '')), 0, 2000),
                'once'        => !array_key_exists('once', $f) || (bool) $f['once'],
                'draft'       => $draft,
                'problems'    => Definition::from($draft)->validate($this->registry),
                'existing'    => null,
                'same'        => false,
            ];
            $match = $here->first(function ($flow) use ($key, $fkey, $used) {
                return !isset($used[$flow->id]) && (string) $flow->pack_key === $key && (string) $flow->flow_key === $fkey;
            }) ?: $here->first(function ($flow) use ($out, $used) {
                return !isset($used[$flow->id]) && self::sameName($flow->name, end($out)['name']);
            });
            if ($match) {
                $used[$match->id] = true;
                $last = count($out) - 1;
                $out[$last]['existing'] = (int) $match->id;
                $out[$last]['same'] = self::sameDraft((array) $match->draft, $draft);
            }
        }

        return ['ok' => true, 'problems' => [], 'params' => $params, 'flows' => $out, 'pack' => [
            'key' => $key, 'name' => mb_substr(trim((string) ($data['name'] ?? $key)), 0, 191),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 4000),
            'version' => mb_substr((string) ($data['pack_version'] ?? ''), 0, 30)]];
    }

    /**
     * The flows of a pack saved as drafts, switched off (a new import of the same pack: their drafts updated, the
     * version that runs is kept until they are published again).
     *
     * @return array ['created' => n, 'updated' => n, 'same' => n]
     */
    public function import(array $read, array $mailboxIds, $user)
    {
        $count = ['created' => 0, 'updated' => 0, 'same' => 0];
        \DB::transaction(function () use ($read, $mailboxIds, $user, &$count) {
            foreach ($read['flows'] as $f) {
                $flow = $f['existing'] ? Flow::find($f['existing']) : null;
                if ($flow) {
                    // a flow found by its name now knows its pack: the next import finds it by its key
                    $keys = (string) $flow->pack_key === '' ? ['pack_key' => $read['pack']['key'], 'flow_key' => $f['key'], 'pack_name' => $read['pack']['name']] : [];
                    // same pack: its name as given by this import
                    if ((string) $flow->pack_key === $read['pack']['key'] && (string) $flow->pack_name !== $read['pack']['name']) {
                        $keys['pack_name'] = $read['pack']['name'];
                    }
                    if ($f['same']) {
                        $count['same']++;
                    } else {
                        // the draft only: its mailboxes, "active" and the published version stay as they are
                        $keys += ['name' => $f['name'], 'draft' => $f['draft'], 'once' => $f['once']];
                        $count['updated']++;
                    }
                    if ($keys) {
                        $flow->update($keys);
                    }
                } else {
                    Flow::create(['name' => $f['name'], 'draft' => $f['draft'], 'once' => $f['once'], 'mailbox_ids' => $mailboxIds,
                        'active' => false, 'created_by' => $user ? $user->id : null, 'pack_key' => $read['pack']['key'], 'flow_key' => $f['key'], 'pack_name' => $read['pack']['name']]);
                    $count['created']++;
                }
            }
        });

        return $count;
    }

    /** Same name, whatever the case and the spaces around. */
    protected static function sameName($a, $b)
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /** Same blocks (type, settings) and same links; where the blocks are drawn does not count. */
    protected static function sameDraft(array $a, array $b)
    {
        $shape = function (array $d) {
            $blocks = [];
            foreach ((array) ($d['blocks'] ?? []) as $bl) {
                $blocks[(string) ($bl['id'] ?? '')] = [(string) ($bl['type'] ?? ''), self::sorted((array) ($bl['params'] ?? []))];
            }
            ksort($blocks);
            $links = [];
            foreach ((array) ($d['links'] ?? []) as $l) {
                $links[] = ($l['from'] ?? '').'|'.($l['port'] ?? 'next').'|'.($l['to'] ?? '');
            }
            sort($links);

            return json_encode([$blocks, $links]);
        };

        return $shape($a) === $shape($b);
    }

    protected static function sorted(array $v)
    {
        ksort($v);
        foreach ($v as $k => $x) {
            if (is_array($x)) {
                $v[$k] = self::sorted($x);
            }
        }

        return $v;
    }

    /** Flows -> a pack (to share them): blocks and links only, no mailbox (they are particular to this installation). */
    public static function export($flows, $name)
    {
        $out = [];
        $keys = [];
        foreach ($flows as $flow) {
            $key = $flow->flow_key ?: self::slug($flow->name);
            for ($n = 2; isset($keys[$key]); $n++) {
                $key = self::slug($flow->name).'-'.$n;
            }
            $keys[$key] = true;
            $draft = (array) $flow->draft;
            $out[] = ['key' => $key, 'name' => $flow->name, 'once' => (bool) $flow->once,
                'blocks' => array_map(function ($b) {
                    return ['id' => $b['id'], 'type' => $b['type'], 'params' => (object) ($b['params'] ?? [])];
                }, (array) ($draft['blocks'] ?? [])),
                'links' => array_values((array) ($draft['links'] ?? []))];
        }

        return ['format' => self::FORMAT, 'version' => 1, 'key' => self::slug($name), 'name' => $name, 'pack_version' => '1.0.0', 'flows' => $out];
    }

    /** A valid key: the one given, else made from it (or from the name). */
    protected static function key($key, $name)
    {
        return preg_match(self::KEY, $key) ? $key : self::slug($key !== '' ? $key : $name);
    }

    public static function slug($text)
    {
        $ascii = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text) ?: ''), '-'));

        return substr($ascii !== '' ? $ascii : 'flow', 0, 100);
    }

    protected static function draft(array $flow, array $replace)
    {
        $draft = ['blocks' => [], 'links' => []];
        foreach ((array) ($flow['blocks'] ?? []) as $b) {
            if (is_array($b)) {
                $draft['blocks'][] = ['id' => (string) ($b['id'] ?? ''), 'type' => (string) ($b['type'] ?? ''),
                    'params' => self::fill((array) ($b['params'] ?? []), $replace)];
            }
        }
        foreach ((array) ($flow['links'] ?? []) as $l) {
            if (is_array($l)) {
                $draft['links'][] = ['from' => (string) ($l['from'] ?? ''), 'port' => (string) ($l['port'] ?? 'next'), 'to' => (string) ($l['to'] ?? '')];
            }
        }

        return $draft;
    }

    /** {{param.<key>}} replaced in every text of the parameters (nested values too). */
    protected static function fill(array $params, array $replace)
    {
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = self::fill($v, $replace);
            } elseif (is_string($v) && strpos($v, '{{param.') !== false) {
                $params[$k] = strtr($v, $replace);
            }
        }

        return $params;
    }
}
