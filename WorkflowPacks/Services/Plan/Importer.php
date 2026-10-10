<?php

namespace Modules\WorkflowPacks\Services\Plan;

use Modules\WorkflowPacks\Entities\ImportLog;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Applies a plan (Planner) with the choices made in the preview.
 *
 * Atomicity: the WHOLE import (every mailbox) runs in one database transaction. If anything fails, nothing is
 * written: no workflow, no link, no history line.
 *
 * Defaults: new rules inactive, "apply to previous" off; the active state and "apply to previous" of existing rules
 * are kept unless explicitly chosen. The import itself sends no email and processes no conversation: it never calls
 * Workflow::maybeProcessInBackground() (what the Workflows form calls after saving).
 */
class Importer
{
    /**
     * @param array $decisions rule_key => [mailbox_id => choice]
     * @param array $options   activate_new (bool), allow_apply_to_prev (bool), active_mode keep|activate|deactivate,
     *                         ack_blocked (bool)
     * @return array ['ok' => bool, 'error' => string, 'results' => [...], 'counts' => [...]]
     */
    public function apply(array $plan, array $decisions, array $options, $user)
    {
        $options += ['activate_new' => false, 'allow_apply_to_prev' => false, 'active_mode' => 'keep', 'ack_blocked' => false];
        if (!$plan['problems']->isEmpty()) {
            return $this->fail('le plan contient des erreurs : corrigez-les avant de confirmer.');
        }
        $blocked = $plan['counts']['blocked'] + $plan['counts']['denied'];
        if ($blocked && !$options['ack_blocked']) {
            return $this->fail($blocked.' règle(s) bloquée(s) : cochez « ignorer les règles bloquées » pour importer le reste.');
        }

        $pack = $plan['pack'];
        $jsonActions = (array) Bridge::config('json_value_actions');
        $storedParams = $this->storableParams($pack['params'], $plan['params']);
        $results = [];
        $counts = ['created' => 0, 'updated' => 0, 'kept' => 0, 'unchanged' => 0, 'skipped' => 0];

        try {
            \DB::transaction(function () use ($plan, $decisions, $options, $user, $pack, $jsonActions, $storedParams, &$results, &$counts) {
                foreach ($plan['rules'] as $ruleKey => $entry) {
                    foreach ($entry['items'] as $mailboxId => $item) {
                        $choice = $decisions[$ruleKey][$mailboxId] ?? $item['default'];
                        if (!in_array($choice, $item['choices'], true)) {
                            throw new \RuntimeException('choix « '.$choice.' » non permis pour la règle '.$ruleKey.' dans la boîte #'.$mailboxId.'.');
                        }
                        // permission checked again for every mailbox and every operation
                        if ($choice !== 'skip' && !Bridge::canEdit($user, $mailboxId)) {
                            throw new \RuntimeException('droits insuffisants sur la boîte #'.$mailboxId.'.');
                        }
                        $outcome = $this->applyOne($pack, $entry['rule'], $item, $choice, $options, $user, $jsonActions, $storedParams);
                        $results[] = ['rule' => $ruleKey, 'mailbox_id' => $mailboxId, 'choice' => $choice, 'outcome' => $outcome['outcome'], 'workflow_id' => $outcome['workflow_id']];
                        $counts[$outcome['outcome']]++;
                    }
                }

                Pack::updateOrCreate(['pack_key' => $pack['key']], [
                    'name'           => $pack['name'],
                    'description'    => $pack['description'],
                    'pack_version'   => $pack['pack_version'],
                    'schema_version' => $pack['version'],
                    'source'         => json_encode(self::template($pack), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ]);
                ImportLog::create([
                    'pack_key'     => $pack['key'],
                    'pack_version' => $pack['pack_version'],
                    'user_id'      => $user ? $user->id : null,
                    'summary'      => json_encode(['counts' => $counts, 'mailboxes' => array_keys($plan['mailboxes']), 'results' => $results]),
                ]);
            });
        } catch (\Throwable $e) {
            \Log::error('[WorkflowPacks] [WP-IMP-01] import of pack '.$pack['key'].' cancelled, nothing written: '.$e->getMessage());

            return $this->fail('import annulé, aucune modification enregistrée : '.$e->getMessage());
        }

        return ['ok' => true, 'error' => '', 'results' => $results, 'counts' => $counts];
    }

    protected function applyOne(array $pack, array $rule, array $item, $choice, array $options, $user, array $jsonActions, $storedParams)
    {
        $attributes = $item['attributes'];
        $mailboxId = $item['mailbox_id'];
        $linkData = [
            'template'     => json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'params'       => $storedParams,
            'pack_version' => $pack['pack_version'],
            'imported_by'  => $user ? $user->id : null,
        ];

        switch ($choice) {
            case 'skip':
                return ['outcome' => 'skipped', 'workflow_id' => $item['current']['id'] ?? null];

            case 'unchanged':
                $this->saveLink($pack['key'], $rule['key'], $mailboxId, $linkData + ['workflow_id' => $item['current']['id'], 'fingerprint' => $item['fingerprint']]);

                return ['outcome' => 'unchanged', 'workflow_id' => $item['current']['id']];

            case 'keep_local':
                // the local version becomes the reference: next imports compare with it
                $this->saveLink($pack['key'], $rule['key'], $mailboxId, $linkData + ['workflow_id' => $item['current']['id'], 'fingerprint' => $item['current']['fingerprint']]);

                return ['outcome' => 'kept', 'workflow_id' => $item['current']['id']];

            case 'create':
            case 'recreate':
            case 'create_renamed':
                if ($choice === 'create_renamed') {
                    $attributes['name'] = $this->freeName($mailboxId, $attributes['name']);
                }
                $workflow = Bridge::newModel();
                $workflow->mailbox_id = $mailboxId;
                $this->fill($workflow, $attributes);
                $workflow->apply_to_prev = $options['allow_apply_to_prev'] ? (bool) $attributes['apply_to_prev'] : false;
                $workflow->active = false;
                if (method_exists($workflow, 'setSortOrderLast')) {
                    $workflow->setSortOrderLast();
                }
                $this->checkComplete($workflow);
                if ($options['activate_new'] && $this->isComplete($workflow)) {
                    $workflow->active = true;
                }
                $workflow->save();
                $this->saveLink($pack['key'], $rule['key'], $mailboxId, $linkData + [
                    'workflow_id' => $workflow->id,
                    'fingerprint' => Fingerprint::of(Bridge::attributesOf($workflow), $jsonActions),
                ]);

                return ['outcome' => 'created', 'workflow_id' => (int) $workflow->id];

            case 'update':
            case 'replace':
                $workflow = Bridge::query()->where('id', $item['current']['id'])->lockForUpdate()->first();
                if (!$workflow || (int) $workflow->mailbox_id !== (int) $mailboxId) {
                    throw new \RuntimeException('la règle #'.($item['current']['id'] ?? '?').' a disparu pendant l\'import.');
                }
                $current = Fingerprint::of(Bridge::attributesOf($workflow), $jsonActions);
                if ($current !== $item['current']['fingerprint']) {
                    throw new \RuntimeException('la règle #'.$workflow->id.' a été modifiée depuis la prévisualisation.');
                }
                $wasActive = (bool) $workflow->active;
                $this->fill($workflow, $attributes);
                $this->checkComplete($workflow);
                if ($options['active_mode'] === 'deactivate') {
                    $workflow->active = false;
                } elseif ($options['active_mode'] === 'activate') {
                    $workflow->active = $this->isComplete($workflow);
                } else {
                    $workflow->active = $wasActive && $this->isComplete($workflow);
                }
                $workflow->save();
                $this->saveLink($pack['key'], $rule['key'], $mailboxId, $linkData + [
                    'workflow_id' => $workflow->id,
                    'fingerprint' => Fingerprint::of(Bridge::attributesOf($workflow), $jsonActions),
                ]);

                return ['outcome' => 'updated', 'workflow_id' => (int) $workflow->id];
        }

        throw new \RuntimeException('choix inconnu « '.$choice.' ».');
    }

    /** Managed fields only: active, apply_to_prev and sort_order are handled by the caller. */
    protected function fill($workflow, array $attributes)
    {
        $workflow->name = $attributes['name'];
        $workflow->type = $attributes['type'];
        $workflow->max_executions = $attributes['max_executions'];
        $workflow->conditions = $attributes['conditions'];
        $workflow->actions = $attributes['actions'];
    }

    /** Workflow::checkComplete() (official): marks the rule complete / incomplete and deactivates an incomplete one. */
    protected function checkComplete($workflow)
    {
        if (method_exists($workflow, 'checkComplete')) {
            $workflow->checkComplete(false);
        }
    }

    protected function isComplete($workflow)
    {
        return !isset($workflow->complete) || (bool) $workflow->complete;
    }

    protected function freeName($mailboxId, $name)
    {
        $max = (int) Bridge::config('name_max');
        for ($i = 1; $i < 100; $i++) {
            $suffix = ' ('.($i === 1 ? 'pack' : 'pack '.$i).')';
            $candidate = mb_substr($name, 0, $max - mb_strlen($suffix)).$suffix;
            if (!Bridge::query()->where('mailbox_id', $mailboxId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }
        throw new \RuntimeException('aucun nom libre pour « '.$name.' ».');
    }

    protected function saveLink($packKey, $ruleKey, $mailboxId, array $data)
    {
        Link::updateOrCreate(['pack_key' => $packKey, 'workflow_key' => $ruleKey, 'mailbox_id' => $mailboxId], $data);
    }

    /** Values kept to pre-fill the next import of the pack: parameters marked "sensitive" are never stored. */
    protected function storableParams(array $declared, array $values)
    {
        $out = [];
        foreach ($declared as $param) {
            if (!$param['sensitive'] && isset($values[$param['key']])) {
                $out[$param['key']] = $values[$param['key']];
            }
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /** The pack as a template (what was uploaded, normalized), to export it again with its variables. */
    public static function template(array $pack)
    {
        $out = [
            'format'       => $pack['format'],
            'version'      => $pack['version'],
            'key'          => $pack['key'],
            'name'         => $pack['name'],
            'description'  => $pack['description'],
            'pack_version' => $pack['pack_version'],
            'requires'     => $pack['requires'],
            'params'       => array_map(function ($p) {
                return array_filter($p, function ($v, $k) {
                    return !($v === null || ($v === '' && $k === 'description') || ($v === false && in_array($k, ['required', 'sensitive'], true)));
                }, ARRAY_FILTER_USE_BOTH);
            }, $pack['params']),
            'workflows'    => array_map(function ($rule) {
                return array_filter($rule, function ($v, $k) {
                    return !(in_array($k, ['description', 'requires'], true) && ($v === '' || $v === []));
                }, ARRAY_FILTER_USE_BOTH);
            }, $pack['workflows']),
        ];
        foreach (['description', 'pack_version'] as $k) {
            if ($out[$k] === '') {
                unset($out[$k]);
            }
        }
        if (!$out['requires']) {
            unset($out['requires']);
        }
        if (!$out['params']) {
            unset($out['params']);
        }

        return $out;
    }

    protected function fail($message)
    {
        return ['ok' => false, 'error' => $message, 'results' => [], 'counts' => []];
    }
}
