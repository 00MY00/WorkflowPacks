<?php

namespace Modules\WorkflowPacks\Services\Graph;

use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Pack\RuleCompiler;
use Modules\WorkflowPacks\Services\Pack\VariableResolver;
use Modules\WorkflowPacks\Services\Plan\Importer;
use Modules\WorkflowPacks\Services\Plan\Planner;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Changes made from the diagram. Nothing is trusted from the browser: every rule goes through the same pipeline as an
 * import (PackValidator -> RuleCompiler against the catalog of the mailbox -> Workflow::validate()), the permission
 * is checked for every mailbox, the write is done in a transaction with the official Workflows model.
 * New rules are created INACTIVE; nothing is ever deleted (a link can only be disabled).
 */
class RuleEditor
{
    /** Full rule of a node, or null when not allowed. */
    public function detail($user, $workflowId)
    {
        $wf = $this->workflow($user, $workflowId);
        if (!$wf) {
            return null;
        }
        $link = Link::where('workflow_id', $wf->id)->where('mailbox_id', $wf->mailbox_id)->first();
        $mailbox = \App\Mailbox::find($wf->mailbox_id);

        return [
            'workflow_id' => (int) $wf->id,
            'mailbox_id'  => (int) $wf->mailbox_id,
            'mailbox'     => $mailbox ? $mailbox->name : '',
            'active'      => (bool) $wf->active,
            'complete'    => !isset($wf->complete) || (bool) $wf->complete,
            'rule'        => RuleCodec::fromWorkflow($wf),
            'pack'        => $link ? ['key' => $link->pack_key, 'rule' => $link->workflow_key, 'version' => $link->pack_version] : null,
            'edit_url'    => \Route::has('mailboxes.workflows.update') ? route('mailboxes.workflows.update', ['mailbox_id' => $wf->mailbox_id, 'id' => $wf->id]) : '',
        ];
    }

    /** What the editor may offer for a mailbox: catalog of Workflows + users and mailboxes for the local references. */
    public function catalog($user, $mailboxId)
    {
        if (!$this->allowed($user, $mailboxId)) {
            return null;
        }
        $users = [];
        foreach (\App\User::where('status', '!=', \App\User::STATUS_DELETED)->orderBy('first_name')->get() as $u) {
            if ($u->isAdmin() || $u->hasAccessToMailbox((int) $mailboxId)) {
                $users[] = ['id' => (int) $u->id, 'label' => trim($u->getFullName())];
            }
        }

        return [
            'catalog'   => Bridge::catalog($mailboxId),
            'users'     => $users,
            'mailboxes' => Bridge::editableMailboxes($user)->map(function ($m) {
                return ['id' => (int) $m->id, 'label' => $m->name];
            })->values()->all(),
            'json_actions' => array_values((array) Bridge::config('json_value_actions')),
            'sensitive'    => (array) Bridge::config('sensitive_actions'),
            'refs'         => (array) Bridge::config('local_refs'),
            'strict'       => (array) Bridge::config('strict_values'),
        ];
    }

    /**
     * Creates ($workflowId null) or changes a rule of a mailbox.
     *
     * @return array ['ok' => bool, 'errors' => string[], 'workflow_id' => int|null, 'pack_modified' => bool]
     */
    public function save($user, $mailboxId, $workflowId, array $rule, $activate = false)
    {
        if (!$this->allowed($user, $mailboxId)) {
            return $this->fail([Problems::t('denied')]);
        }
        $current = null;
        if ($workflowId) {
            $current = $this->workflow($user, $workflowId);
            if (!$current || (int) $current->mailbox_id !== (int) $mailboxId) {
                return $this->fail([Problems::t('denied')]);
            }
        }
        $mailbox = \App\Mailbox::find($mailboxId);

        // 1. structure (same validator as the import; unknown keys refused)
        $rule['key'] = 'rule';
        $rule += ['type' => 'automatic'];
        $checked = (new PackValidator((array) config('workflowpacks.limits')))->validate([
            'format' => PackValidator::FORMAT, 'version' => 1, 'key' => 'editor', 'name' => 'editor', 'workflows' => [$rule],
        ]);
        if ($checked['pack'] === null) {
            return $this->fail($checked['problems']->lines());
        }
        // 2. against the catalog of the mailbox, with its variables
        $compiler = new RuleCompiler(Bridge::catalog($mailboxId), Bridge::config(), Bridge::types(), function ($kind, $value, $mb) {
            return Planner::referenceError($kind, $value, $mb);
        }, (array) config('workflowpacks.limits'));
        $vars = VariableResolver::forMailbox($mailbox->id, $mailbox->email, $mailbox->name, [], []);
        $compiled = $compiler->compile($checked['pack']['workflows'][0], $vars, $mailbox->id, 'rule');
        if ($compiled['attributes'] === null) {
            return $this->fail($compiled['problems']->lines());
        }
        $attributes = $compiled['attributes'];
        // 3. Workflows' own rules: name unique in the mailbox, official validation
        $taken = Bridge::query()->where('mailbox_id', $mailbox->id)->where('name', $attributes['name']);
        if ($current) {
            $taken->where('id', '!=', $current->id);
        }
        if ($taken->exists()) {
            return $this->fail([Problems::t('graph_name_taken', ['name' => $attributes['name']])]);
        }
        $official = Bridge::officialErrors($attributes, $mailbox->id);
        if ($official) {
            return $this->fail([Problems::t('official_rejected', ['fields' => implode(', ', array_keys($official))])]);
        }

        // 4. write (transaction)
        $id = null;
        \DB::transaction(function () use ($current, $mailbox, $attributes, $activate, &$id) {
            $wf = $current ? Bridge::query()->where('id', $current->id)->lockForUpdate()->first() : Bridge::newModel();
            if (!$current) {
                $wf->mailbox_id = $mailbox->id;
                $wf->apply_to_prev = false;
                $wf->active = false;
                if (method_exists($wf, 'setSortOrderLast')) {
                    $wf->setSortOrderLast();
                }
            }
            $wasActive = (bool) $wf->active;
            $wf->name = $attributes['name'];
            $wf->type = $attributes['type'];
            $wf->max_executions = $attributes['max_executions'];
            $wf->conditions = $attributes['conditions'];
            $wf->actions = $attributes['actions'];
            if (method_exists($wf, 'checkComplete')) {
                $wf->checkComplete(false);
            }
            $complete = !isset($wf->complete) || (bool) $wf->complete;
            $wf->active = $complete && ($current ? $wasActive : (bool) $activate);
            $wf->save();
            $id = (int) $wf->id;
        });

        $packModified = $current && Link::where('workflow_id', $current->id)->where('mailbox_id', $mailbox->id)->exists();

        return ['ok' => true, 'errors' => [], 'workflow_id' => $id, 'pack_modified' => $packModified];
    }

    /** Enables / disables one rule (a link of the diagram). An incomplete rule cannot be enabled. */
    public function toggle($user, $workflowId, $active)
    {
        $wf = $this->workflow($user, $workflowId);
        if (!$wf) {
            return $this->fail([Problems::t('denied')]);
        }
        if ($active && isset($wf->complete) && !$wf->complete) {
            return $this->fail([Problems::t('graph_incomplete')]);
        }
        $wf->active = (bool) $active;
        $wf->save();

        return ['ok' => true, 'errors' => [], 'workflow_id' => (int) $wf->id, 'active' => (bool) $wf->active];
    }

    /**
     * Applies the rule of a node to another mailbox (link drawn in the diagram). Rule of a pack: the pack is imported
     * for this rule only, through the normal plan / import (variables, resources, links). Other rule: copied, inactive.
     */
    public function applyTo($user, $workflowId, $targetMailboxId)
    {
        $source = $this->workflow($user, $workflowId);
        if (!$source || !$this->allowed($user, $targetMailboxId)) {
            return $this->fail([Problems::t('denied')]);
        }
        if ((int) $source->mailbox_id === (int) $targetMailboxId) {
            return $this->fail([Problems::t('graph_same_mailbox')]);
        }
        $link = Link::where('workflow_id', $source->id)->where('mailbox_id', $source->mailbox_id)->first();
        $pack = $link ? Pack::where('pack_key', $link->pack_key)->first() : null;
        if ($link && $pack) {
            return $this->applyPackRule($user, $link, $pack, (int) $targetMailboxId);
        }

        $rule = RuleCodec::fromWorkflow($source);
        $rule['name'] = $this->freeName((int) $targetMailboxId, $rule['name']);
        unset($rule['key']);

        return $this->save($user, (int) $targetMailboxId, null, $rule, false);
    }

    protected function applyPackRule($user, $link, $pack, $targetMailboxId)
    {
        $checked = (new PackValidator((array) config('workflowpacks.limits')))->validateJson($pack->source);
        if ($checked['pack'] === null) {
            return $this->fail($checked['problems']->lines());
        }
        $params = json_decode((string) $link->params, true) ?: [];
        $plan = (new Planner())->plan($checked['pack'], [$targetMailboxId], $params, $user);
        if (!$plan['problems']->isEmpty()) {
            return $this->fail($plan['problems']->lines());
        }
        $item = $plan['rules'][$link->workflow_key]['items'][$targetMailboxId] ?? null;
        if (!$item || $item['status'] !== 'create') {
            $reasons = $item ? $item['reasons'] : [];

            return $this->fail($reasons ?: [Problems::t('graph_not_applicable', ['status' => $item ? $item['status'] : '?'])]);
        }
        // only this rule: every other rule of the pack is left as it is in that mailbox
        $decisions = [];
        foreach ($plan['rules'] as $key => $entry) {
            foreach ($entry['items'] as $mailboxId => $other) {
                if ($key !== $link->workflow_key) {
                    $decisions[$key][$mailboxId] = in_array('skip', $other['choices'], true) ? 'skip' : $other['default'];
                }
            }
        }
        $result = (new Importer())->apply($plan, $decisions, ['ack_blocked' => true], $user);
        if (!$result['ok']) {
            return $this->fail([$result['error']]);
        }
        $created = null;
        foreach ($result['results'] as $r) {
            if ($r['rule'] === $link->workflow_key && $r['outcome'] === 'created') {
                $created = $r['workflow_id'];
            }
        }

        return ['ok' => true, 'errors' => [], 'workflow_id' => $created, 'pack_modified' => false];
    }

    protected function freeName($mailboxId, $name)
    {
        $max = (int) Bridge::config('name_max');
        if (!Bridge::query()->where('mailbox_id', $mailboxId)->where('name', $name)->exists()) {
            return $name;
        }
        for ($i = 2; $i < 100; $i++) {
            $suffix = ' ('.$i.')';
            $candidate = mb_substr($name, 0, $max - mb_strlen($suffix)).$suffix;
            if (!Bridge::query()->where('mailbox_id', $mailboxId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $name;
    }

    protected function workflow($user, $workflowId)
    {
        $wf = $workflowId ? Bridge::query()->find((int) $workflowId) : null;

        return $wf && Bridge::canEdit($user, $wf->mailbox_id) ? $wf : null;
    }

    protected function allowed($user, $mailboxId)
    {
        return $mailboxId && \App\Mailbox::find((int) $mailboxId) && Bridge::canEdit($user, (int) $mailboxId);
    }

    protected function fail(array $errors)
    {
        return ['ok' => false, 'errors' => array_values($errors), 'workflow_id' => null, 'pack_modified' => false];
    }
}
