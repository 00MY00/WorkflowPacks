<?php

namespace Modules\WorkflowPacks\Services\Plan;

use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Pack\RuleCompiler;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * What an import would do, for every rule of the pack and every selected mailbox, without writing anything.
 *
 * Identity of a rule: pack.key + workflow.key + mailbox (table workflowpacks_links). Status of each pair:
 *   create         no link, name free in the mailbox
 *   conflict_name  no link, a rule that does not belong to the pack already has this name (never modified)
 *   unchanged      linked rule identical to what the pack gives
 *   update         linked rule not modified by hand since the last import, the pack changed it
 *   conflict       linked rule modified by hand since the last import (choice: keep local / replace / skip)
 *   missing        linked rule deleted from Workflows since the last import (choice: skip / recreate)
 *   blocked        dependency missing, unknown type, invalid value, rejected by Workflows' validation…
 *   denied         the user may not manage the workflows of this mailbox
 */
class Planner
{
    const CHOICES = [
        'create'        => ['create', 'skip'],
        'conflict_name' => ['skip', 'create_renamed'],
        'unchanged'     => ['unchanged'],
        'update'        => ['update', 'skip'],
        'conflict'      => ['keep_local', 'replace', 'skip'],
        'missing'       => ['skip', 'recreate'],
        'blocked'       => ['skip'],
        'denied'        => ['skip'],
    ];

    /**
     * @param array $pack        pack normalized by PackValidator
     * @param int[] $mailboxIds  target mailboxes
     * @param array $paramValues key => raw value entered in the form
     * @param \App\User $user
     */
    public function plan(array $pack, array $mailboxIds, array $paramValues, $user)
    {
        $problems = new Problems();
        $config = Bridge::config();
        $limits = (array) config('workflowpacks.limits');

        // ------------------------------------------------------------------------------------ mailboxes
        $mailboxIds = array_values(array_unique(array_map('intval', $mailboxIds)));
        if (!$mailboxIds) {
            $problems->add('mailboxes', 'WP-MB-01', 'choisissez au moins une boîte.');
        }
        if (count($mailboxIds) > (int) ($limits['mailboxes'] ?? 100)) {
            $problems->add('mailboxes', 'WP-MB-02', 'trop de boîtes sélectionnées.');
        }
        $mailboxes = [];
        foreach ($mailboxIds as $id) {
            $mailbox = \App\Mailbox::find($id);
            if (!$mailbox) {
                $problems->add('mailboxes', 'WP-MB-03', 'boîte #'.$id.' introuvable.');
                continue;
            }
            $mailboxes[$id] = [
                'id'      => $id,
                'name'    => (string) $mailbox->name,
                'email'   => (string) $mailbox->email,
                'allowed' => Bridge::canEdit($user, $id),
            ];
        }

        // ----------------------------------------------------------------------------------- parameters
        $params = $this->checkParams($pack['params'], $paramValues, $problems);

        // ------------------------------------------------------------------------------------------ rules
        $types = Bridge::types();
        $jsonActions = (array) $config['json_value_actions'];
        $refCheck = function ($kind, $value, $mailboxId) {
            return self::referenceError($kind, $value, $mailboxId);
        };
        $rules = [];
        foreach ($pack['workflows'] as $index => $rule) {
            $requires = array_values(array_unique(array_merge($pack['requires'], $rule['requires'])));
            $missingModules = array_values(array_filter($requires, function ($alias) {
                return !Bridge::moduleActive($alias);
            }));
            $items = [];
            foreach ($mailboxes as $id => $mailbox) {
                $item = [
                    'rule_key'   => $rule['key'],
                    'mailbox_id' => $id,
                    'status'     => 'blocked',
                    'reasons'    => [],
                    'attributes' => null,
                    'fingerprint' => '',
                    'current'    => null,
                    'link_id'    => null,
                    'diff'       => [],
                    'sensitive'  => [],
                ];
                if (!$mailbox['allowed']) {
                    $item['status'] = 'denied';
                    $item['reasons'][] = 'vous n\'avez pas le droit de gérer les workflows de cette boîte.';
                    $items[$id] = $this->finish($item);
                    continue;
                }
                if ($missingModules) {
                    $item['reasons'][] = 'module(s) requis inactif(s) : '.implode(', ', $missingModules).'. La règle n\'est pas importée '
                        .'(aucune action n\'est retirée pour la rendre importable).';
                    $items[$id] = $this->finish($item);
                    continue;
                }
                if (!$problems->isEmpty()) {
                    $item['reasons'][] = 'corrigez d\'abord les erreurs générales (boîtes, paramètres).';
                    $items[$id] = $this->finish($item);
                    continue;
                }
                $vars = VariableResolver::forMailbox($id, $mailbox['email'], $mailbox['name'], $pack['params'], $params);
                $compiler = new RuleCompiler(Bridge::catalog($id), $config, $types, $refCheck, $limits);
                $compiled = $compiler->compile($rule, $vars, $id, 'workflows['.$index.']');
                $item['sensitive'] = $compiled['sensitive'];
                if ($compiled['attributes'] === null) {
                    $item['reasons'] = array_merge($item['reasons'], $compiled['problems']->lines());
                    $items[$id] = $this->finish($item);
                    continue;
                }
                $attributes = $compiled['attributes'];
                $item['attributes'] = $attributes;
                $item['fingerprint'] = Fingerprint::of($attributes, $jsonActions);

                $official = Bridge::officialErrors($attributes, $id);
                if ($official) {
                    $item['reasons'][] = 'refusée par la validation de Workflows (champs : '.implode(', ', array_keys($official)).') : '
                        .'une référence (utilisateur, boîte…) ou une valeur est incomplète.';
                    $items[$id] = $this->finish($item);
                    continue;
                }

                $items[$id] = $this->finish($this->state($item, $pack['key'], $rule, $attributes, $jsonActions));
            }
            $rules[$rule['key']] = ['index' => $index, 'rule' => $rule, 'requires' => $requires, 'missing_modules' => $missingModules, 'items' => $items];
        }

        $plan = [
            'pack'      => $pack,
            'mailboxes' => $mailboxes,
            'params'    => $params,
            'problems'  => $problems,
            'rules'     => $rules,
            'counts'    => $this->counts($rules),
        ];
        $plan['token'] = $this->token($plan);

        return $plan;
    }

    /** Status of a compiled rule in a mailbox (links, manual changes, name collisions). */
    protected function state(array $item, $packKey, array $rule, array $attributes, array $jsonActions)
    {
        $id = $item['mailbox_id'];
        $link = Link::where('pack_key', $packKey)->where('workflow_key', $rule['key'])->where('mailbox_id', $id)->first();
        if ($link) {
            $item['link_id'] = $link->id;
            $workflow = $link->workflow_id ? Bridge::query()->find($link->workflow_id) : null;
            if (!$workflow || (int) $workflow->mailbox_id !== (int) $id) {
                $item['status'] = 'missing';
                $item['reasons'][] = 'la règle créée lors d\'un import précédent n\'existe plus dans cette boîte.';
                $item = $this->nameCheck($item, $attributes['name'], null, 'create');

                return $item;
            }
            $current = Bridge::attributesOf($workflow);
            $currentFp = Fingerprint::of($current, $jsonActions);
            $item['current'] = [
                'id'            => (int) $workflow->id,
                'name'          => (string) $workflow->name,
                'active'        => (bool) $workflow->active,
                'apply_to_prev' => (bool) $workflow->apply_to_prev,
                'fingerprint'   => $currentFp,
            ];
            if ($currentFp === $item['fingerprint']) {
                $item['status'] = 'unchanged';
            } elseif ($currentFp === $link->fingerprint) {
                $item['status'] = 'update';
                $item['diff'] = Fingerprint::diff($current, $attributes, $jsonActions);
                $item = $this->nameCheck($item, $attributes['name'], $workflow->id, 'update');
            } else {
                $item['status'] = 'conflict';
                $item['reasons'][] = 'la règle a été modifiée dans Workflows depuis le dernier import.';
                $item['diff'] = Fingerprint::diff($current, $attributes, $jsonActions);
                $item = $this->nameCheck($item, $attributes['name'], $workflow->id, 'conflict');
            }

            return $item;
        }

        $item['status'] = 'create';

        return $this->nameCheck($item, $attributes['name'], null, 'create');
    }

    /** Workflows refuses two rules with the same name in a mailbox (WorkflowsController, Rule::unique). */
    protected function nameCheck(array $item, $name, $exceptId, $mode)
    {
        $query = Bridge::query()->where('mailbox_id', $item['mailbox_id'])->where('name', $name);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $other = $query->first();
        if (!$other) {
            return $item;
        }
        if ($mode === 'create' && $item['status'] === 'create') {
            $item['status'] = 'conflict_name';
            $item['reasons'][] = 'une règle qui n\'appartient pas au pack porte déjà ce nom (#'.$other->id.') : elle ne sera jamais modifiée.';
        } else {
            $item['reasons'][] = 'le nouveau nom est déjà utilisé par une autre règle de la boîte (#'.$other->id.') : renommage impossible.';
            $item['name_taken'] = true;
            if ($item['status'] === 'update') {
                $item['status'] = 'blocked';
            }
        }

        return $item;
    }

    protected function finish(array $item)
    {
        $choices = self::CHOICES[$item['status']];
        if (!empty($item['name_taken'])) {
            $choices = array_values(array_diff($choices, ['replace', 'recreate']));
        }
        $item['choices'] = $choices;
        $item['default'] = $choices[0];

        return $item;
    }

    protected function counts(array $rules)
    {
        $counts = array_fill_keys(array_keys(self::CHOICES), 0);
        foreach ($rules as $rule) {
            foreach ($rule['items'] as $item) {
                $counts[$item['status']]++;
            }
        }

        return $counts;
    }

    /** Proof that the state seen in the preview did not change before the confirmation. */
    protected function token(array $plan)
    {
        $state = [
            'pack'      => hash('sha256', json_encode($plan['pack'])),
            'mailboxes' => array_keys($plan['mailboxes']),
            'params'    => $plan['params'],
            'items'     => [],
        ];
        foreach ($plan['rules'] as $key => $rule) {
            foreach ($rule['items'] as $id => $item) {
                $state['items'][] = [$key, $id, $item['status'], $item['fingerprint'], $item['current']['fingerprint'] ?? '', $item['current']['id'] ?? 0];
            }
        }

        return hash_hmac('sha256', json_encode($state), (string) config('app.key'));
    }

    /** Values entered for the pack parameters, checked (types, required, local references). */
    protected function checkParams(array $declared, array $input, Problems $problems)
    {
        $values = [];
        foreach ($declared as $param) {
            $key = $param['key'];
            $raw = $input[$key] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            if ($value === '' || $value === null) {
                $value = $param['default'];
            }
            if ($value === null || $value === '') {
                if ($param['required']) {
                    $problems->add('params.'.$key, 'WP-PARAM-10', 'paramètre obligatoire « '.$param['label'].' » non renseigné : import bloqué.');
                }
                $values[$key] = null;
                continue;
            }
            $error = PackValidator::paramError($param['type'], $value);
            if ($error === null && in_array($param['type'], ['user', 'mailbox'], true)) {
                $error = self::referenceError($param['type'], $value, null);
            }
            if ($error !== null) {
                $problems->add('params.'.$key, 'WP-PARAM-11', '« '.$param['label'].' » : '.$error);
                $values[$key] = null;
                continue;
            }
            $values[$key] = in_array($param['type'], ['integer', 'user', 'mailbox'], true) ? (int) $value : (string) $value;
        }

        return $values;
    }

    /** Error when a value does not point to an existing user / mailbox of this instance, or null. */
    public static function referenceError($kind, $value, $mailboxId)
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) {
            return ($kind === 'user' ? 'utilisateur' : 'boîte').' : identifiant numérique attendu, « '.(is_scalar($value) ? $value : 'liste').' » reçu.';
        }
        if ($kind === 'mailbox') {
            return \App\Mailbox::find((int) $value) ? null : 'boîte #'.$value.' introuvable sur cette instance.';
        }
        $user = \App\User::find((int) $value);
        if (!$user || (int) $user->status === \App\User::STATUS_DELETED) {
            return 'utilisateur #'.$value.' introuvable sur cette instance.';
        }
        if ($mailboxId && !$user->isAdmin() && !$user->hasAccessToMailbox((int) $mailboxId)) {
            return 'l\'utilisateur #'.$value.' n\'a pas accès à la boîte #'.$mailboxId.'.';
        }

        return null;
    }
}
