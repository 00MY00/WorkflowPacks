<?php

namespace Modules\WorkflowPacks\Services\Graph;

use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Nodes and links of the diagram, limited to the mailboxes whose workflows the user may manage.
 *
 *   node  mb:<id>           mailbox
 *         tpl:<pack>/<key>  rule of a pack (one node, linked to every mailbox where the pack created it)
 *         wf:<id>           rule created outside any pack (linked to its own mailbox)
 *         pack:<key>        pack (contains its rules)
 *         dest:<kind>:<id>  where a rule sends the conversations: folder of a status (closed, pending, spam…),
 *                           Deleted, a person (assignment), a tag (Tags module); moving to another mailbox links the
 *                           rule to that mailbox
 *   link  rule -> mailbox   one Workflows rule; state synced | modified | local | missing, active true/false
 *         pack -> rule      "contains"
 *         rule -> dest      "sends": derived from the actions of the rule (display only)
 *
 * Only identifiers, names and short summaries are sent: the full rule is loaded when a node is opened.
 */
class GraphBuilder
{
    public function build($user)
    {
        $jsonActions = (array) Bridge::config('json_value_actions');
        $mailboxes = Bridge::editableMailboxes($user);
        $mailboxIds = $mailboxes->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        $nodes = [];
        $edges = [];
        $sends = [];

        foreach ($mailboxes as $mailbox) {
            $nodes['mb:'.$mailbox->id] = ['key' => 'mb:'.$mailbox->id, 'kind' => 'mailbox', 'id' => (int) $mailbox->id,
                'label' => (string) $mailbox->name, 'sub' => (string) $mailbox->email];
        }

        $workflows = $mailboxIds ? Bridge::query()->whereIn('mailbox_id', $mailboxIds)->orderBy('sort_order')->get() : collect();
        $links = $workflows->isNotEmpty() ? Link::whereIn('mailbox_id', $mailboxIds)->get() : collect();
        $linkByWorkflow = $links->keyBy('workflow_id');
        $packs = Pack::whereIn('pack_key', $links->pluck('pack_key')->unique()->all() ?: [''])->get()->keyBy('pack_key');

        foreach ($workflows as $wf) {
            $link = $linkByWorkflow->get($wf->id);
            $attributes = Bridge::attributesOf($wf);
            $summary = $this->summary($attributes, $jsonActions);
            if ($link && (int) $link->mailbox_id === (int) $wf->mailbox_id) {
                $ruleKey = 'tpl:'.$link->pack_key.'/'.$link->workflow_key;
                if (!isset($nodes[$ruleKey])) {
                    $template = json_decode((string) $link->template, true) ?: [];
                    $nodes[$ruleKey] = ['key' => $ruleKey, 'kind' => 'rule', 'origin' => 'pack', 'pack' => $link->pack_key,
                        'rule' => $link->workflow_key, 'label' => (string) ($template['name'] ?? $wf->name),
                        'sub' => $summary['text'], 'type' => Bridge::typeName($attributes['type']), 'sensitive' => $summary['sensitive']];
                }
                $state = Fingerprint::of($attributes, $jsonActions) === $link->fingerprint ? 'synced' : 'modified';
            } else {
                $ruleKey = 'wf:'.$wf->id;
                $nodes[$ruleKey] = ['key' => $ruleKey, 'kind' => 'rule', 'origin' => 'local', 'id' => (int) $wf->id,
                    'label' => (string) $wf->name, 'sub' => $summary['text'], 'type' => Bridge::typeName($attributes['type']),
                    'sensitive' => $summary['sensitive']];
                $state = 'local';
            }
            $edges[] = ['from' => $ruleKey, 'to' => 'mb:'.$wf->mailbox_id, 'kind' => 'applies', 'workflow_id' => (int) $wf->id,
                'active' => (bool) $wf->active, 'state' => $state, 'name' => (string) $wf->name];
            foreach ($this->destinations($attributes, $jsonActions, $nodes) as $dest) {
                $nodes[$dest['key']] = $nodes[$dest['key']] ?? $dest;
                $sends[$ruleKey.'>'.$dest['key']] = ['from' => $ruleKey, 'to' => $dest['key'], 'kind' => 'sends', 'action' => $dest['action']];
            }
        }
        foreach ($sends as $edge) {
            $edges[] = $edge;
        }

        // links whose rule was deleted from Workflows since the import
        $existing = $workflows->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        foreach ($links as $link) {
            if ($link->workflow_id && !in_array((int) $link->workflow_id, $existing, true)) {
                $ruleKey = 'tpl:'.$link->pack_key.'/'.$link->workflow_key;
                if (!isset($nodes[$ruleKey])) {
                    $template = json_decode((string) $link->template, true) ?: [];
                    $nodes[$ruleKey] = ['key' => $ruleKey, 'kind' => 'rule', 'origin' => 'pack', 'pack' => $link->pack_key,
                        'rule' => $link->workflow_key, 'label' => (string) ($template['name'] ?? $link->workflow_key), 'sub' => '', 'type' => 'automatic', 'sensitive' => []];
                }
                $edges[] = ['from' => $ruleKey, 'to' => 'mb:'.$link->mailbox_id, 'kind' => 'applies', 'workflow_id' => null,
                    'active' => false, 'state' => 'missing', 'name' => ''];
            }
        }

        foreach ($packs as $pack) {
            $nodes['pack:'.$pack->pack_key] = ['key' => 'pack:'.$pack->pack_key, 'kind' => 'pack', 'pack' => $pack->pack_key,
                'label' => (string) $pack->name, 'sub' => $pack->pack_version !== '' ? 'v'.$pack->pack_version : ''];
        }
        foreach ($nodes as $node) {
            if ($node['kind'] === 'rule' && $node['origin'] === 'pack' && isset($nodes['pack:'.$node['pack']])) {
                $edges[] = ['from' => 'pack:'.$node['pack'], 'to' => $node['key'], 'kind' => 'contains'];
            }
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }

    /**
     * Where the actions of a rule send the conversations (FreeScout's folders follow the status, the state and the
     * assignee of a conversation). Known native actions only, plus tags when an action of the catalog is about tags;
     * "move" points to the target mailbox (its own node when the user manages it).
     */
    protected function destinations(array $attributes, array $jsonActions, array $nodes)
    {
        $out = [];
        foreach (Fingerprint::decodeActions($attributes['actions'], $jsonActions) as $action) {
            $type = (string) ($action['type'] ?? '');
            $value = $action['value'] ?? null;
            if ($type === 'status' && is_scalar($value)) {
                $status = ['1' => 'active', '2' => 'pending', '3' => 'closed', '4' => 'spam'][(string) $value] ?? null;
                if ($status) {
                    $out[] = ['key' => 'dest:status:'.$status, 'kind' => 'dest', 'dest' => $status, 'label' => __('workflowpacks::messages.dest_'.$status), 'sub' => __('workflowpacks::messages.dest_folder'), 'action' => $type];
                }
            } elseif ($type === 'delete' || $type === 'delete_forever') {
                $out[] = ['key' => 'dest:'.$type, 'kind' => 'dest', 'dest' => 'deleted', 'label' => __('workflowpacks::messages.dest_'.$type), 'sub' => __('workflowpacks::messages.dest_folder'), 'action' => $type];
            } elseif ($type === 'assign' && is_scalar($value)) {
                $user = (string) $value === '-1' ? null : \App\User::find((int) $value);
                $label = (string) $value === '-1' ? __('workflowpacks::messages.dest_unassigned') : ((string) $value === '-10' ? __('workflowpacks::messages.dest_current_user') : ($user ? trim($user->getFullName()) : '#'.$value));
                $out[] = ['key' => 'dest:assign:'.preg_replace('/[^0-9-]/', '', (string) $value), 'kind' => 'dest', 'dest' => 'person', 'label' => $label, 'sub' => __('workflowpacks::messages.dest_assigned'), 'action' => $type];
            } elseif ($type === 'move' && is_scalar($value) && preg_match('/^\d+$/', (string) $value)) {
                if (isset($nodes['mb:'.$value])) {
                    $out[] = ['key' => 'mb:'.$value, 'kind' => 'mailbox', 'action' => $type];
                } else {
                    $mailbox = \App\Mailbox::find((int) $value);
                    $out[] = ['key' => 'dest:mailbox:'.(int) $value, 'kind' => 'dest', 'dest' => 'mailbox', 'label' => $mailbox ? $mailbox->name : '#'.$value, 'sub' => __('workflowpacks::messages.dest_other_mailbox'), 'action' => $type];
                }
            } elseif (stripos($type, 'tag') !== false) {
                foreach ((array) $value as $tag) {
                    if (is_scalar($tag) && trim((string) $tag) !== '') {
                        $slug = substr(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $tag), 0, 80);
                        $out[] = ['key' => 'dest:tag:'.$slug, 'kind' => 'dest', 'dest' => 'tag', 'label' => (string) $tag, 'sub' => __('workflowpacks::messages.dest_tag'), 'action' => $type];
                    }
                }
            }
        }

        return $out;
    }

    /** Short sentence: number of conditions / actions + sensitive actions (full detail when the node is opened). */
    protected function summary(array $attributes, array $jsonActions)
    {
        $conditions = 0;
        foreach ((array) $attributes['conditions'] as $group) {
            $conditions += count((array) $group);
        }
        $actions = Fingerprint::decodeActions($attributes['actions'], $jsonActions);
        $sensitive = [];
        foreach ($actions as $action) {
            $kind = Bridge::config('sensitive_actions')[$action['type'] ?? ''] ?? null;
            if ($kind) {
                $sensitive[] = $kind;
            }
        }

        return [
            'text'      => __('workflowpacks::messages.graph_summary', ['conditions' => $conditions, 'actions' => count($actions)]),
            'sensitive' => array_values(array_unique($sensitive)),
        ];
    }
}
