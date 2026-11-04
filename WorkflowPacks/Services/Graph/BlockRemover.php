<?php

namespace Modules\WorkflowPacks\Services\Graph;

use Modules\WorkflowPacks\Entities\Backup;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Services\Flow\FlowBuilder;
use Modules\WorkflowPacks\Services\Flow\TicketConditions;
use Modules\WorkflowPacks\Services\History;
use Modules\WorkflowPacks\Services\Imap\Retention;
use Modules\WorkflowPacks\Services\Pack\Timer;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * "Delete" of the diagram (Delete key, right click): what a selected block stands for in the Workflows rules, shown
 * before anything is done, then applied in one transaction after a backup that "Undo" restores.
 *
 *   wf:<id> / tpl:<pack>/<key>   the rule (every mailbox the user manages, for a rule of a pack); a rule of a pack
 *                                deleted from Workflows since ("missing"): its link, so that the block goes away
 *   fa:<id>:none                 a rule without delay nor action (its only block): the rule
 *   fa:<id>:<i>                  one action of the rule (the rule itself when it has no other action)
 *   ft:<id>                      the delay of the rule (it then runs on the arrival of the e-mail)
 *   fc:<mailbox>:<hash>[:<i>]    one condition of a flow: removed from every rule of the flow
 *   fb:<mailbox>:<hash>:<lane>   the test of a branch (assigned / replied): removed from the rules of the lane
 *   dest:clean:…                 the automatic clean-up of an e-mail folder (administrators: a setting, not a rule)
 *   dest:…                       a destination: the actions sending there
 * Mailboxes and packs are not deleted here (a pack: "Remove the pack", with its own preview).
 *
 * The blocks are read again from the stored rules: nothing sent by the browser is trusted but the keys, and only
 * the rules of mailboxes the user manages are touched.
 */
class BlockRemover
{
    const BACKUP_KEY = 'graph-delete';

    /** @var array mailbox id => [workflows] (loaded once) */
    protected $rules = [];

    /**
     * @return array ['ops' => [workflow id => ['delete' => bool, 'actions' => [flat index], 'lanes' => [index]]],
     *                'cleanups' => [folder settings], 'count' => int, 'lines' => [texts], 'warnings' => [texts],
     *                'refused' => [texts], 'token' => string]
     */
    public function plan($user, array $keys)
    {
        $ops = [];
        $cleanups = [];
        $dangling = [];
        $refused = [];
        $jsonActions = (array) Bridge::config('json_value_actions');
        foreach (array_slice(array_values(array_unique(array_map('strval', $keys))), 0, 200) as $key) {
            if (preg_match('/^wf:(\d+)$/', $key, $m) || preg_match('/^fa:(\d+):none$/', $key, $m)) {
                if (!$this->deleteRule($ops, $user, (int) $m[1])) {
                    $refused[] = __('workflowpacks::messages.del_refused_rights');
                }
            } elseif (preg_match('#^tpl:([^/]+)/(.+)$#', $key, $m)) {
                foreach (Link::where('pack_key', $m[1])->where('workflow_key', $m[2])->get() as $link) {
                    if (!Bridge::canEdit($user, $link->mailbox_id)) {
                        $refused[] = __('workflowpacks::messages.del_refused_rights');
                    } elseif ($link->workflow_id && Bridge::query()->where('id', $link->workflow_id)->where('mailbox_id', $link->mailbox_id)->exists()) {
                        $this->deleteRule($ops, $user, (int) $link->workflow_id);
                    } else {
                        // the rule was deleted from Workflows: only the link of the pack is left (block "missing")
                        $dangling[$link->id] = $link;
                    }
                }
            } elseif (preg_match('/^fa:(\d+):(\d+)$/', $key, $m)) {
                $this->add($ops, $user, (int) $m[1], 'actions', (int) $m[2]);
            } elseif (preg_match('/^ft:(\d+)$/', $key, $m)) {
                $wf = $this->workflow($user, (int) $m[1]);
                foreach ($wf ? $this->conditionsOf($wf) : [] as $i => $lane) {
                    if (count($lane) === 1 && FlowBuilder::isDelay($lane[0])) {
                        $this->add($ops, $user, (int) $wf->id, 'lanes', $i);
                    }
                }
            } elseif (preg_match('/^(fc|fb):(\d+):/', $key, $m)) {
                if (!$this->flowBlock($ops, $user, $key, (int) $m[2])) {
                    $refused[] = __('workflowpacks::messages.del_refused_all');
                }
            } elseif (strpos($key, 'dest:clean:') === 0) {
                // the clean-up of a folder: a setting of the administrators (RetentionController)
                $row = $this->cleanupOf($key);
                if (!$user->isAdmin()) {
                    $refused[] = __('workflowpacks::messages.del_refused_cleanup');
                } elseif ($row) {
                    $cleanups[$row->folder] = ['folder' => $row->folder, 'number' => (int) $row->number, 'metric' => $row->metric, 'mode' => $row->mode];
                }
            } elseif (strpos($key, 'dest:') === 0) {
                $builder = new GraphBuilder();
                foreach ($this->editableRules($user) as $wf) {
                    foreach (Fingerprint::decodeActions(Bridge::attributesOf($wf)['actions'], $jsonActions) as $i => $action) {
                        if (in_array($key, $builder->actionKeys($action, $jsonActions), true)) {
                            $this->add($ops, $user, (int) $wf->id, 'actions', $i);
                        }
                    }
                }
            } elseif (strpos($key, 'pack:') === 0) {
                $refused[] = __('workflowpacks::messages.del_refused_pack');
            } elseif (strpos($key, 'mb:') === 0) {
                $refused[] = __('workflowpacks::messages.del_refused_mailbox');
            }
        }

        // a rule left without any action is deleted
        foreach ($ops as $id => $op) {
            if (!$op['delete'] && $op['actions']) {
                $count = count(Fingerprint::decodeActions(Bridge::attributesOf($op['model'])['actions'], $jsonActions));
                if (count(array_unique($op['actions'])) >= $count) {
                    $ops[$id]['delete'] = true;
                    $ops[$id]['emptied'] = true;
                }
            }
        }

        list($lines, $warnings) = $this->texts($ops, $jsonActions);
        foreach ($cleanups as $c) {
            $lines[] = __('workflowpacks::messages.del_cleanup', ['folder' => $c['folder'], 'delay' => Timer::label($c)]);
        }
        $cleanups = array_values($cleanups);
        $links = [];
        foreach ($dangling as $link) {
            $template = json_decode((string) $link->template, true) ?: [];
            $lines[] = __('workflowpacks::messages.del_missing', ['rule' => (string) ($template['name'] ?? $link->workflow_key)]);
            $links[] = $link->only(['id', 'pack_key', 'workflow_key', 'mailbox_id', 'workflow_id', 'template', 'params', 'fingerprint', 'pack_version', 'imported_by']);
        }

        return ['ops' => $ops, 'cleanups' => $cleanups, 'links' => $links, 'count' => count($ops) + count($cleanups) + count($links), 'lines' => $lines,
            'warnings' => $warnings, 'refused' => array_values(array_unique($refused)), 'token' => $this->token($ops, [$cleanups, $links])];
    }

    /** @return array ['ok' => bool, 'error' => string, 'backup_id' => int, 'deleted' => int, 'changed' => int] */
    public function apply($user, array $keys, $token)
    {
        $plan = $this->plan($user, $keys);
        if (!$plan['count']) {
            return ['ok' => false, 'error' => $plan['refused'] ? implode(' ', $plan['refused']) : __('workflowpacks::messages.del_nothing')];
        }
        if (!hash_equals($plan['token'], (string) $token)) {
            return ['ok' => false, 'error' => __('workflowpacks::messages.del_changed')];
        }
        $jsonActions = (array) Bridge::config('json_value_actions');
        $result = ['ok' => true, 'error' => '', 'backup_id' => 0, 'deleted' => 0, 'changed' => 0];
        try {
            \DB::transaction(function () use ($plan, $user, $jsonActions, &$result) {
                $saved = [];
                foreach ($plan['ops'] as $id => $op) {
                    $wf = $op['model'];
                    $saved[] = ['id' => (int) $wf->id, 'mailbox_id' => (int) $wf->mailbox_id, 'fields' => self::fields($wf),
                        'links' => Link::where('workflow_id', $wf->id)->get()->map(function ($l) {
                            return $l->only(['pack_key', 'workflow_key', 'mailbox_id', 'template', 'params', 'fingerprint', 'pack_version', 'imported_by']);
                        })->all()];
                }
                $backup = Backup::create(['pack_key' => self::BACKUP_KEY, 'user_id' => $user->id, 'content' => json_encode(
                    ['format' => 'workflowpacks-graph-delete', 'version' => 1, 'created_at' => date('c'), 'workflows' => $saved, 'cleanups' => $plan['cleanups'], 'links' => $plan['links']],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $result['backup_id'] = (int) $backup->id;
                foreach ($plan['cleanups'] as $c) {
                    Retention::remove($c['folder']);
                    $result['changed']++;
                }
                foreach ($plan['links'] as $l) {
                    Link::where('id', $l['id'])->delete();
                    $result['changed']++;
                }

                foreach ($plan['ops'] as $id => $op) {
                    $wf = $op['model'];
                    if ($op['delete']) {
                        Link::where('workflow_id', $wf->id)->delete();
                        History::add('rules', 'deleted', ['user_id' => $user->id, 'mailbox_id' => $wf->mailbox_id, 'workflow_id' => $wf->id, 'subject' => (string) $wf->name]);
                        $wf->delete();
                        $result['deleted']++;
                        continue;
                    }
                    $attributes = Bridge::attributesOf($wf);
                    if ($op['lanes']) {
                        $wf->conditions = array_values(array_diff_key($attributes['conditions'], array_flip($op['lanes'])));
                    }
                    if ($op['actions']) {
                        $wf->actions = self::withoutActions($attributes['actions'], $op['actions']);
                    }
                    if (method_exists($wf, 'checkComplete')) {
                        $wf->checkComplete(false);
                    }
                    $wf->save();
                    History::add('rules', 'modified', ['user_id' => $user->id, 'mailbox_id' => $wf->mailbox_id, 'workflow_id' => $wf->id, 'subject' => (string) $wf->name]);
                    $result['changed']++;
                }
            });
        } catch (\Throwable $e) {
            \Log::error('[WorkflowPacks] [WP-DEL-01] deletion cancelled, nothing written: '.$e->getMessage());

            return ['ok' => false, 'error' => __('workflowpacks::messages.del_failed', ['error' => $e->getMessage()])];
        }

        return $result;
    }

    /** "Undo": the rules as they were (deleted ones created again, with their links to a pack). */
    public function restore($user, $backupId)
    {
        $backup = Backup::where('id', (int) $backupId)->where('pack_key', self::BACKUP_KEY)->first();
        if (!$backup || (int) $backup->user_id !== (int) $user->id) {
            return ['ok' => false, 'error' => __('workflowpacks::messages.del_no_backup')];
        }
        $content = json_decode($backup->content, true) ?: [];
        try {
            \DB::transaction(function () use ($content, $user, $backup) {
                foreach ((array) ($content['workflows'] ?? []) as $entry) {
                    if (!Bridge::canEdit($user, $entry['mailbox_id'])) {
                        throw new \RuntimeException(__('workflowpacks::messages.del_no_backup'));
                    }
                    $wf = Bridge::query()->where('id', $entry['id'])->where('mailbox_id', $entry['mailbox_id'])->first() ?: Bridge::newModel();
                    $wf->mailbox_id = $entry['mailbox_id'];
                    foreach ($entry['fields'] as $field => $value) {
                        $wf->{$field} = $value;
                    }
                    $wf->save();
                    foreach ((array) $entry['links'] as $link) {
                        Link::updateOrCreate(['pack_key' => $link['pack_key'], 'workflow_key' => $link['workflow_key'], 'mailbox_id' => $link['mailbox_id']],
                            $link + ['workflow_id' => $wf->id]);
                    }
                }
                foreach ((array) ($content['links'] ?? []) as $l) {
                    if (!Bridge::canEdit($user, $l['mailbox_id'])) {
                        throw new \RuntimeException(__('workflowpacks::messages.del_no_backup'));
                    }
                    unset($l['id']);
                    Link::updateOrCreate(['pack_key' => $l['pack_key'], 'workflow_key' => $l['workflow_key'], 'mailbox_id' => $l['mailbox_id']], $l);
                }
                foreach ((array) ($content['cleanups'] ?? []) as $c) {
                    $error = $user->isAdmin() ? Retention::save($c['folder'], ['number' => $c['number'], 'metric' => $c['metric']], $c['mode'], $user) : 'del_no_backup';
                    if ($error !== null) {
                        throw new \RuntimeException(__('workflowpacks::messages.del_no_backup'));
                    }
                }
                $backup->delete();
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => ''];
    }

    // ---------------------------------------------------------------------------------------------- internals

    /** The clean-up setting a block "dest:clean:…" stands for (same key as GraphBuilder gives it). */
    protected function cleanupOf($key)
    {
        foreach (Retention::all() as $row) {
            if ($key === 'dest:clean:'.substr(GraphBuilder::folderKey($row->folder), strlen('dest:'))) {
                return $row;
            }
        }

        return null;
    }

    protected static function fields($wf)
    {
        $a = Bridge::attributesOf($wf);

        return ['name' => $a['name'], 'type' => $a['type'], 'apply_to_prev' => $a['apply_to_prev'], 'max_executions' => $a['max_executions'],
            'conditions' => $a['conditions'], 'actions' => $a['actions'], 'active' => (bool) $wf->active, 'sort_order' => (int) $wf->sort_order];
    }

    /** Workflows' groups of actions without the given flat indexes. */
    protected static function withoutActions(array $groups, array $remove)
    {
        $out = [];
        $i = 0;
        foreach ($groups as $group) {
            $kept = [];
            foreach ((array) $group as $action) {
                if (!in_array($i++, $remove, true)) {
                    $kept[] = $action;
                }
            }
            if ($kept) {
                $out[] = $kept;
            }
        }

        return $out;
    }

    protected function workflow($user, $id)
    {
        $wf = Bridge::query()->find($id);

        return $wf && Bridge::canEdit($user, $wf->mailbox_id) ? $wf : null;
    }

    protected function editableRules($user)
    {
        $ids = Bridge::editableMailboxes($user)->pluck('id')->all();

        return $ids ? Bridge::query()->whereIn('mailbox_id', $ids)->get() : collect();
    }

    protected function conditionsOf($wf)
    {
        return array_map(function ($lane) {
            return array_values(array_filter((array) $lane, 'is_array'));
        }, (array) Bridge::attributesOf($wf)['conditions']);
    }

    /** @return bool false when the rule is not there, or in a mailbox the user does not manage */
    protected function deleteRule(array &$ops, $user, $id)
    {
        if (!$this->entry($ops, $user, $id)) {
            return false;
        }
        $ops[$id]['delete'] = true;

        return true;
    }

    protected function add(array &$ops, $user, $id, $what, $index)
    {
        if ($this->entry($ops, $user, $id) && !in_array($index, $ops[$id][$what], true)) {
            $ops[$id][$what][] = $index;
        }
    }

    protected function entry(array &$ops, $user, $id)
    {
        if (isset($ops[$id])) {
            return true;
        }
        $wf = $this->workflow($user, $id);
        if (!$wf) {
            return false;
        }
        $ops[$id] = ['model' => $wf, 'delete' => false, 'actions' => [], 'lanes' => []];

        return true;
    }

    /** A condition block (fc:) or a branch (fb:) of the flow view: the lanes it stands for in each rule. */
    protected function flowBlock(array &$ops, $user, $key, $mailboxId)
    {
        if (!Bridge::canEdit($user, $mailboxId)) {
            return true;
        }
        $jsonActions = (array) Bridge::config('json_value_actions');
        if (!isset($this->rules[$mailboxId])) {
            $this->rules[$mailboxId] = Bridge::query()->where('mailbox_id', $mailboxId)->orderBy('sort_order')->get();
        }
        $rules = $this->rules[$mailboxId]->map(function ($wf) use ($jsonActions) {
            return GraphBuilder::flowRule($wf, Bridge::attributesOf($wf), $jsonActions);
        })->all();
        foreach ((new FlowBuilder(GraphBuilder::labels($mailboxId)))->build($mailboxId, $rules) as $flow) {
            foreach ($flow['blocks'] as $i => $block) {
                if ($block['key'] !== $key) {
                    continue;
                }
                if ($block['family'] === 'all' || !isset($flow['base'][$i])) {
                    return false;   // "All the e-mails": no condition to remove
                }
                $wanted = FlowBuilder::canon($flow['base'][$i]);
                foreach ($flow['lanes'] as $lane) {
                    foreach ($lane['steps'] as $step) {
                        $wf = $this->workflow($user, $step['workflow_id']);
                        foreach ($wf ? $this->conditionsOf($wf) : [] as $index => $l) {
                            if (FlowBuilder::canon($l) === $wanted) {
                                $this->add($ops, $user, (int) $wf->id, 'lanes', $index);
                            }
                        }
                    }
                }

                return true;
            }
            foreach ($flow['lanes'] as $lane) {
                if ($lane['key'] !== $key) {
                    continue;
                }
                foreach ($lane['steps'] as $step) {
                    $wf = $this->workflow($user, $step['workflow_id']);
                    foreach ($wf ? $this->conditionsOf($wf) : [] as $index => $l) {
                        if (count($l) === 1 && in_array($l[0]['type'] ?? '', TicketConditions::TYPES, true)) {
                            $this->add($ops, $user, (int) $wf->id, 'lanes', $index);
                        }
                    }
                }

                return true;
            }
        }

        return true;
    }

    /** What will happen, in words, and what to watch. */
    protected function texts(array $ops, array $jsonActions)
    {
        $lines = [];
        $warnings = [];
        foreach ($ops as $op) {
            $wf = $op['model'];
            $labels = GraphBuilder::labels($wf->mailbox_id);
            $mailbox = $labels->action(['type' => 'move', 'value' => $wf->mailbox_id])['label'];
            if ($op['delete']) {
                $lines[] = __('workflowpacks::messages.'.(!empty($op['emptied']) ? 'del_rule_empty' : 'del_rule'), ['rule' => $wf->name, 'mailbox' => $mailbox]);
                continue;
            }
            $attributes = Bridge::attributesOf($wf);
            $actions = Fingerprint::decodeActions($attributes['actions'], $jsonActions);
            foreach ($op['actions'] as $i) {
                if (isset($actions[$i])) {
                    $lines[] = __('workflowpacks::messages.del_action', ['action' => $labels->action($actions[$i])['label'], 'rule' => $wf->name]);
                }
            }
            $lanes = $this->conditionsOf($wf);
            $delayRemoved = false;
            foreach ($op['lanes'] as $i) {
                $lane = $lanes[$i] ?? [];
                if (!$lane) {
                    continue;
                }
                $text = $labels->condition(count($lane) === 1 ? $lane[0] : ['type' => '__or', 'items' => $lane]);
                $lines[] = __('workflowpacks::messages.del_condition', ['condition' => $text, 'rule' => $wf->name]);
                $delayRemoved = $delayRemoved || (count($lane) === 1 && FlowBuilder::isDelay($lane[0]));
            }
            if ($delayRemoved) {
                $warnings[] = __('workflowpacks::messages.del_warn_delay', ['rule' => $wf->name]);
            }
            if ($op['lanes'] && count($op['lanes']) >= count($lanes)) {
                $warnings[] = __('workflowpacks::messages.del_warn_no_condition', ['rule' => $wf->name]);
            }
        }

        return [$lines, array_values(array_unique($warnings))];
    }

    /** Proof that the rules did not change between the question and the confirmation. */
    protected function token(array $ops, array $cleanups = [])
    {
        $state = [$cleanups];
        foreach ($ops as $id => $op) {
            $state[] = [$id, $op['delete'], $op['actions'], $op['lanes'], self::fields($op['model'])];
        }

        return hash_hmac('sha256', json_encode($state), (string) config('app.key'));
    }
}
