<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Entities\Flow;

/**
 * Packs of flows for the internal engine (docs/flux-internes.md):
 *   {"format": "workflowpacks-flows", "version": 1, "key", "name", "description", "pack_version",
 *    "params": [{"key", "label", "description", "required"}], "flows": [{"key", "name", "description", "once", "blocks", "links"}]}
 * A single flow (the answer of the AI assistant, "workflowpacks-flow") is read as a pack of one flow.
 * {{param.<key>}} in the parameters of the blocks is replaced by the value given at the import (plain text).
 * Imported flows are drafts, switched off; a new import of the same pack updates their drafts.
 */
class FlowPack
{
    const FORMAT = 'workflowpacks-flows';
    const MAX_BYTES = 524288;
    const MAX_FLOWS = 50;
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
     *               'flows' => [{key, name, description, once, draft, problems, existing}]]
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
        $data = strlen($json) <= self::MAX_BYTES ? json_decode($json, true) : null;
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
        $key = (string) ($data['key'] ?? '');
        $flows = array_values(array_filter((array) ($data['flows'] ?? []), 'is_array'));
        if (!preg_match(self::KEY, $key) || !$flows || count($flows) > self::MAX_FLOWS) {
            return $fail(__('workflowpacks::flowpack.bad_pack', ['max' => self::MAX_FLOWS]));
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
        $existing = Flow::where('pack_key', $key)->pluck('id', 'flow_key');
        $out = [];
        $seen = [];
        foreach ($flows as $i => $f) {
            $fkey = (string) ($f['key'] ?? '');
            if (!preg_match(self::KEY, $fkey) || isset($seen[$fkey])) {
                return $fail(__('workflowpacks::flowpack.bad_flow_key', ['n' => $i + 1]));
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
                'existing'    => $existing[$fkey] ?? null,
            ];
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
     * @return array ['created' => n, 'updated' => n]
     */
    public function import(array $read, array $mailboxIds, $user)
    {
        $count = ['created' => 0, 'updated' => 0];
        \DB::transaction(function () use ($read, $mailboxIds, $user, &$count) {
            foreach ($read['flows'] as $f) {
                $flow = $f['existing'] ? Flow::find($f['existing']) : null;
                if ($flow) {
                    $flow->update(['name' => $f['name'], 'draft' => $f['draft'], 'once' => $f['once'], 'mailbox_ids' => $mailboxIds]);
                    $count['updated']++;
                } else {
                    Flow::create(['name' => $f['name'], 'draft' => $f['draft'], 'once' => $f['once'], 'mailbox_ids' => $mailboxIds,
                        'active' => false, 'created_by' => $user ? $user->id : null, 'pack_key' => $read['pack']['key'], 'flow_key' => $f['key']]);
                    $count['created']++;
                }
            }
        });

        return $count;
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
