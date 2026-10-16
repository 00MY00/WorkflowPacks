<?php

namespace Modules\WorkflowPacks\Services\Export;

use Modules\WorkflowPacks\Entities\ImportLog;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Entities\Resource;
use Modules\WorkflowPacks\Services\History;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Plan\Importer;
use Modules\WorkflowPacks\Services\Plan\Planner;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * "Group into a pack": existing rules become the rules of a pack — a new one or one already imported — WITHOUT being
 * recreated or changed. The pack is stored like an imported one and each rule is linked to it where it is
 * (workflowpacks_links), so that the pack can be exported, and imported again here without any duplicate.
 *
 *  - the CURRENT version of each rule goes into the pack (changes made since an import included), through the
 *    Exporter: same explicit replacements by variables, same removal of the secrets;
 *  - the same rule in several mailboxes (identical once the variables are put back) is one rule of the pack;
 *  - a rule linked to another pack moves to this one (a rule belongs to one pack); a pack left without any rule
 *    disappears, as after a removal;
 *  - an existing pack keeps its other rules, its parameters and resources; a rule of this pack is replaced by its
 *    current version; its version number goes up.
 *
 * Every rule is checked with the import's own Planner: the pack, compiled again for the rule's mailbox, must give
 * the rule exactly as it is. If not (a value replaced in one mailbox only…), the link records the compiled version:
 * the rule then appears "changed locally" and a next import offers to keep it — never a silent replacement.
 */
class Grouper
{
    /** May the user change this pack: administrator, or every rule of the pack in a mailbox they manage. */
    public static function canModify($user, Pack $pack)
    {
        if ($user->isAdmin()) {
            return true;
        }
        foreach (Link::where('pack_key', $pack->pack_key)->pluck('mailbox_id')->unique() as $mailboxId) {
            if (!Bridge::canEdit($user, $mailboxId)) {
                return false;
            }
        }

        return true;
    }

    /** 1.4.2 -> 1.4.3 ; anything else -> 1.0.0. */
    public static function bump($version)
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', (string) $version, $m)) {
            return $m[1].'.'.$m[2].'.'.($m[3] + 1);
        }

        return '1.0.0';
    }

    /**
     * What grouping would give, nothing written.
     *
     * @param \Illuminate\Support\Collection $workflows rules of mailboxes the user manages
     * @param array $target ['mode' => 'new', 'key', 'name', 'description', 'pack_version', 'requires']
     *                      or ['mode' => 'existing', 'pack_key', 'pack_version' (empty: next version)]
     * @return array ['errors' => [], 'pack' => array (file), 'normalized' => array, 'report' => array,
     *                'assign' => [workflow id => [...]], 'warnings' => [], 'values' => [...], 'resources' => [...]]
     */
    public function compose($workflows, array $target, array $replacements, $user)
    {
        $existing = null;
        $source = null;
        if ($target['mode'] === 'existing') {
            $existing = Pack::where('pack_key', (string) $target['pack_key'])->first();
            if (!$existing || !self::canModify($user, $existing)) {
                return $this->fail([__('workflowpacks::messages.export_no_pack')]);
            }
            $source = (array) json_decode($existing->source, true);
            $meta = [
                'key'          => $existing->pack_key,
                'name'         => $existing->name,
                'description'  => (string) $existing->description,
                'pack_version' => trim((string) ($target['pack_version'] ?? '')) ?: self::bump($existing->pack_version),
                'requires'     => (array) ($source['requires'] ?? []),
            ];
        } else {
            $meta = $target;
        }

        $exported = (new Exporter())->exportWorkflows($workflows, $meta, $replacements);
        $file = $exported['pack'];
        $report = $exported['report'];
        $values = $exported['param_values'] + $exported['secret_values'];

        // rules already in the pack (existing one), by key, and by content to find the same rule again
        $rules = array_values((array) ($source['workflows'] ?? []));
        $index = [];
        $byContent = [];
        foreach ($rules as $i => $rule) {
            $index[$rule['key']] = $i;
            $byContent[self::content($rule)] = $rule['key'];
        }
        if ($existing) {
            foreach (Link::where('pack_key', $existing->pack_key)->get() as $link) {
                $values += (array) json_decode((string) $link->params, true);
            }
        }

        $links = Link::whereIn('workflow_id', $workflows->pluck('id')->all() ?: [0])->get()->groupBy('workflow_id');
        $replaced = [];
        $assign = [];
        $warnings = [];
        foreach ($workflows as $workflow) {
            $rule = $file['workflows'][$exported['rule_of'][(int) $workflow->id]];
            $content = self::content($rule);
            $own = $existing ? collect($links->get($workflow->id, []))->firstWhere('pack_key', $existing->pack_key) : null;
            $from = collect($links->get($workflow->id, []))->pluck('pack_key')->reject(function ($key) use ($existing) {
                return $existing && $key === $existing->pack_key;
            })->unique()->values()->all();

            if ($own && isset($index[$own->workflow_key]) && !isset($replaced[$own->workflow_key])) {
                // already a rule of this pack: replaced by its current version
                $key = $own->workflow_key;
                $state = self::content($rules[$index[$key]]) === $content ? 'kept' : 'updated';
                $rule['key'] = $key;
                unset($byContent[self::content($rules[$index[$key]])]);
                $rules[$index[$key]] = $rule;
                $byContent[$content] = $key;
                $replaced[$key] = $content;
            } elseif (isset($byContent[$content])) {
                // the same rule as one of the pack (in another mailbox)
                $key = $byContent[$content];
                $state = $own && $own->workflow_key === $key ? 'kept' : 'joined';
            } elseif ($own && isset($replaced[$own->workflow_key])) {
                // the same rule of this pack, changed differently in two mailboxes: the first version goes into the
                // pack, this one stays linked to it and will show "changed locally" (the check below)
                $key = $own->workflow_key;
                $state = 'diverged';
                $warnings[] = __('workflowpacks::messages.group_diverged', ['name' => $workflow->name]);
            } else {
                $key = $this->freeKey($rule['key'], $index);
                $rule['key'] = $key;
                $index[$key] = count($rules);
                $rules[] = $rule;
                $byContent[$content] = $key;
                $state = 'new';
            }
            $assign[(int) $workflow->id] = [
                'workflow_id' => (int) $workflow->id,
                'name'        => (string) $workflow->name,
                'mailbox_id'  => (int) $workflow->mailbox_id,
                'key'         => $key,
                'state'       => $state,
                'from'        => $from,
                'current_fp'  => Fingerprint::of(Bridge::attributesOf($workflow), (array) Bridge::config('json_value_actions')),
                'template_fp' => '',
            ];
        }

        // the pack: its own description, parameters and resources first, then what the rules bring
        $pack = $file;
        $pack['workflows'] = $rules;
        if ($source) {
            foreach (['params', 'resources'] as $list) {
                $merged = [];
                foreach (array_merge((array) ($source[$list] ?? []), (array) ($file[$list] ?? [])) as $item) {
                    $merged[$item['key']] = $merged[$item['key']] ?? $item;
                }
                if ($merged) {
                    $pack[$list] = array_values($merged);
                }
            }
            if (!empty($source['mailboxes'])) {
                $pack['mailboxes'] = $source['mailboxes'];
            }
            $pack['requires'] = array_values(array_unique(array_merge((array) ($source['requires'] ?? []), (array) ($file['requires'] ?? []))));
            if (!$pack['requires']) {
                unset($pack['requires']);
            }
            $pack['version'] = max((int) ($source['version'] ?? 1), (int) $file['version'], !empty($pack['resources']) || !empty($pack['mailboxes']) ? 2 : 1);
        }

        $check = (new PackValidator((array) config('workflowpacks.limits')))->validate($pack);
        if ($check['pack'] === null) {
            return $this->fail($check['problems']->lines()) + ['pack' => $pack, 'report' => $report];
        }
        $normalized = $check['pack'];

        // resources replaced in the rules: the local element is the one replaced
        $resources = [];
        foreach ($replacements as $r) {
            if (strpos($r['variable'], 'resource.') === 0) {
                $resources[substr($r['variable'], 9)] = ['type' => $r['type'], 'local_id' => (string) $r['text']];
            }
        }

        // the pack compiled again for each mailbox must give the rule as it is
        $mailboxIds = array_values(array_unique(array_column($assign, 'mailbox_id')));
        $plan = (new Planner())->plan($normalized, $mailboxIds, $values, $user, array_map(function ($r) {
            return $r['local_id'];
        }, $resources));
        if (!$plan['problems']->isEmpty()) {
            $warnings[] = __('workflowpacks::messages.group_unchecked', ['problems' => implode(' · ', $plan['problems']->lines())]);
        } else {
            foreach ($assign as $id => $a) {
                $item = $plan['rules'][$a['key']]['items'][$a['mailbox_id']] ?? null;
                $fp = $item['fingerprint'] ?? '';
                $assign[$id]['template_fp'] = $fp;
                if ($fp !== $a['current_fp']) {
                    $assign[$id]['differs'] = true;
                    $reasons = $item ? implode(' · ', (array) ($item['reasons'] ?? [])) : '';
                    $warnings[] = __('workflowpacks::messages.group_differs', ['name' => $a['name']]).($reasons !== '' ? ' ('.$reasons.')' : '');
                }
            }
        }

        return [
            'errors'     => [],
            'pack'       => $pack,
            'normalized' => $normalized,
            'report'     => $report,
            'assign'     => $assign,
            'warnings'   => array_values(array_unique($warnings)),
            'values'     => $values,
            'resources'  => $resources,
            'existing'   => (bool) $existing,
        ];
    }

    /**
     * Writes the pack and links the rules to it, in one transaction (nothing is written if anything fails). The rules
     * themselves are not touched.
     *
     * @return array ['ok' => bool, 'error' => string, 'counts' => [...], 'removed_packs' => []]
     */
    public function apply(array $composed, $user)
    {
        $normalized = $composed['normalized'];
        $key = $normalized['key'];
        $rules = [];
        foreach ($normalized['workflows'] as $rule) {
            $rules[$rule['key']] = $rule;
        }
        $params = [];
        foreach ($normalized['params'] as $param) {
            if (!$param['sensitive'] && isset($composed['values'][$param['key']])) {
                $params[$param['key']] = $composed['values'][$param['key']];
            }
        }
        $counts = ['linked' => 0, 'moved_pack' => 0, 'version' => (string) $normalized['pack_version']];
        $removed = [];

        try {
            \DB::transaction(function () use ($composed, $normalized, $key, $rules, $params, $user, &$counts, &$removed) {
                Pack::updateOrCreate(['pack_key' => $key], [
                    'name'           => $normalized['name'],
                    'description'    => $normalized['description'],
                    'pack_version'   => $normalized['pack_version'],
                    'schema_version' => $normalized['version'],
                    'source'         => json_encode(Importer::template($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ]);
                $others = [];
                foreach ($composed['assign'] as $a) {
                    // the rule must still be what was shown
                    $workflow = Bridge::query()->where('id', $a['workflow_id'])->lockForUpdate()->first();
                    if (!$workflow || (int) $workflow->mailbox_id !== $a['mailbox_id']
                        || Fingerprint::of(Bridge::attributesOf($workflow), (array) Bridge::config('json_value_actions')) !== $a['current_fp']) {
                        throw new \RuntimeException(Problems::t('imp_changed', ['id' => $a['workflow_id']]));
                    }
                    foreach (Link::where('workflow_id', $a['workflow_id'])->get() as $old) {
                        if ($old->pack_key !== $key) {
                            $others[$old->pack_key] = true;
                            $counts['moved_pack']++;
                        }
                        $old->delete();
                    }
                    Link::updateOrCreate(['pack_key' => $key, 'workflow_key' => $a['key'], 'mailbox_id' => $a['mailbox_id']], [
                        'workflow_id'  => $a['workflow_id'],
                        'template'     => json_encode(Importer::ruleTemplate($rules[$a['key']]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'params'       => json_encode($params, JSON_UNESCAPED_UNICODE),
                        // what the pack gives in this mailbox: equal to the rule, unless the check found a difference
                        'fingerprint'  => $a['template_fp'] !== '' ? $a['template_fp'] : $a['current_fp'],
                        'pack_version' => $normalized['pack_version'],
                        'imported_by'  => $user ? $user->id : null,
                    ]);
                    $counts['linked']++;
                }
                foreach ($composed['resources'] as $resourceKey => $r) {
                    Resource::updateOrCreate(
                        ['pack_key' => $key, 'resource_key' => $resourceKey, 'resource_type' => $r['type']],
                        ['local_id' => $r['local_id'], 'last_seen_name' => mb_substr($resourceKey, 0, 191), 'fingerprint' => hash('sha256', $r['type'].'#'.$r['local_id'])]
                    );
                }
                // a pack left without any rule disappears (as after a removal)
                foreach (array_keys($others) as $other) {
                    if (!Link::where('pack_key', $other)->exists()) {
                        Resource::where('pack_key', $other)->delete();
                        Pack::where('pack_key', $other)->delete();
                        $removed[] = $other;
                    }
                }
                ImportLog::create([
                    'pack_key'     => $key,
                    'pack_version' => $normalized['pack_version'],
                    'user_id'      => $user ? $user->id : null,
                    'summary'      => json_encode(['action' => 'group', 'counts' => $counts, 'removed_packs' => $removed]),
                ]);
            });
        } catch (\Throwable $e) {
            \Log::error('[WorkflowPacks] [WP-GRP-01] grouping into pack '.$key.' cancelled, nothing written: '.$e->getMessage());

            return ['ok' => false, 'error' => Problems::t('imp_cancelled', ['error' => $e->getMessage()]), 'counts' => [], 'removed_packs' => []];
        }

        History::add('group', 'ok', ['user_id' => $user ? $user->id : null, 'subject' => (string) $normalized['name'], 'message' => json_encode(['counts' => $counts])]);

        return ['ok' => true, 'error' => '', 'counts' => $counts, 'removed_packs' => $removed];
    }

    /** A rule without its key, keys sorted: two rules with the same content give the same text. */
    protected static function content(array $rule)
    {
        unset($rule['key']);

        return json_encode(self::sorted($rule), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected static function sorted($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        $value = array_map([self::class, 'sorted'], $value);
        if (!$isList) {
            ksort($value);
        }

        return $value;
    }

    protected function freeKey($key, array $taken)
    {
        $base = substr((string) $key, 0, 90);
        $i = 2;
        while (isset($taken[$key])) {
            $key = $base.'-'.$i++;
        }

        return $key;
    }

    protected function fail(array $errors)
    {
        return ['errors' => $errors, 'pack' => null, 'normalized' => null, 'report' => [], 'assign' => [], 'warnings' => [], 'values' => [], 'resources' => [], 'existing' => false];
    }
}
