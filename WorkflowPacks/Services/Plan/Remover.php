<?php

namespace Modules\WorkflowPacks\Services\Plan;

use Modules\WorkflowPacks\Entities\Backup;
use Modules\WorkflowPacks\Entities\ImportLog;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Entities\Resource;
use Modules\WorkflowPacks\Services\Graph\RuleCodec;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * "Remove the pack": deletes the Workflows rules a pack created, after a preview, like an import in reverse.
 *
 *   delete        rule created by the pack, unchanged since          -> deleted
 *   modified      rule changed by hand since the import              -> kept, unless "delete the modified ones too"
 *   missing       rule already deleted in Workflows                  -> only the link is cleaned
 *   denied        mailbox the user may not manage                    -> untouched (not even named)
 *   not_selected  mailbox left out of the removal                    -> untouched
 *
 * Only rules linked to the pack are concerned: rules created by hand are never touched. Before anything is deleted, a
 * backup (pack as imported + every deleted rule as it was) is stored in workflowpacks_backups; everything happens in
 * one transaction. The pack itself disappears from the list once none of its rules is left.
 */
class Remover
{
    /**
     * @param int[]|null $mailboxIds mailboxes to remove the pack from (null = every mailbox the user manages)
     * @return array|null ['pack', 'items', 'counts', 'token'] or null when the pack is unknown
     */
    public function plan($packKey, $user, ?array $mailboxIds = null)
    {
        $pack = Pack::where('pack_key', (string) $packKey)->first();
        if (!$pack) {
            return null;
        }
        $jsonActions = (array) Bridge::config('json_value_actions');
        $source = json_decode((string) $pack->source, true) ?: [];
        $names = [];
        foreach ((array) ($source['workflows'] ?? []) as $rule) {
            $names[(string) ($rule['key'] ?? '')] = (string) ($rule['name'] ?? '');
        }
        $links = Link::where('pack_key', $pack->pack_key)->orderBy('workflow_key')->orderBy('mailbox_id')->get();
        $workflows = Bridge::query()->whereIn('id', $links->pluck('workflow_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $mailboxes = \App\Mailbox::whereIn('id', $links->pluck('mailbox_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $mailboxIds = $mailboxIds === null ? null : array_map('intval', $mailboxIds);

        $items = [];
        $counts = ['delete' => 0, 'modified' => 0, 'missing' => 0, 'denied' => 0, 'not_selected' => 0];
        foreach ($links as $link) {
            $allowed = Bridge::canEdit($user, (int) $link->mailbox_id);
            $wf = $workflows->get($link->workflow_id);
            if ($wf && (int) $wf->mailbox_id !== (int) $link->mailbox_id) {
                $wf = null;
            }
            $fingerprint = $wf ? Fingerprint::of(Bridge::attributesOf($wf), $jsonActions) : '';
            if (!$allowed) {
                $status = 'denied';
            } elseif ($mailboxIds !== null && !in_array((int) $link->mailbox_id, $mailboxIds, true)) {
                $status = 'not_selected';
            } elseif (!$wf) {
                $status = 'missing';
            } else {
                $status = $fingerprint === $link->fingerprint ? 'delete' : 'modified';
            }
            $counts[$status]++;
            $mailbox = $mailboxes->get($link->mailbox_id);
            $items[] = [
                'link_id'     => (int) $link->id,
                'rule'        => (string) $link->workflow_key,
                'name'        => $allowed ? ($wf ? (string) $wf->name : ($names[$link->workflow_key] ?? $link->workflow_key)) : '',
                'mailbox_id'  => (int) $link->mailbox_id,
                'mailbox'     => $allowed && $mailbox ? (string) $mailbox->name : '',
                'workflow_id' => $wf ? (int) $wf->id : null,
                'active'      => $wf ? (bool) $wf->active : false,
                'status'      => $status,
                'fingerprint' => $fingerprint,
            ];
        }

        return ['pack' => $pack, 'items' => $items, 'counts' => $counts, 'token' => $this->token($pack, $items)];
    }

    /**
     * @return array ['ok', 'error', 'deleted', 'kept', 'cleaned', 'pack_removed', 'backup_id']
     */
    public function apply(array $plan, $deleteModified, $user)
    {
        $deleteModified = (bool) $deleteModified;
        $delete = [];
        $cleaned = [];
        $kept = 0;
        foreach ($plan['items'] as $item) {
            if ($item['status'] === 'delete' || ($item['status'] === 'modified' && $deleteModified)) {
                $delete[] = $item;
            } elseif ($item['status'] === 'missing') {
                $cleaned[] = $item['link_id'];
            } elseif ($item['status'] === 'modified') {
                $kept++;
            }
        }
        $result = ['ok' => true, 'error' => '', 'deleted' => 0, 'kept' => $kept, 'cleaned' => count($cleaned), 'pack_removed' => false, 'backup_id' => null];
        if (!$delete && !$cleaned) {
            return $result;
        }
        $pack = $plan['pack'];

        try {
            \DB::transaction(function () use ($pack, $delete, $cleaned, $kept, $user, &$result) {
                // 1. backup first (same transaction: no deletion without its backup)
                $removed = [];
                foreach ($delete as $item) {
                    $wf = Bridge::query()->where('id', $item['workflow_id'])->lockForUpdate()->first();
                    // checked again: still the same rule, in the same mailbox, still allowed
                    if (!$wf || (int) $wf->mailbox_id !== $item['mailbox_id'] || !Bridge::canEdit($user, $item['mailbox_id'])) {
                        throw new \RuntimeException('changed');
                    }
                    $removed[] = [
                        'rule'       => $item['rule'],
                        'mailbox_id' => $item['mailbox_id'],
                        'mailbox'    => $item['mailbox'],
                        'modified'   => $item['status'] === 'modified',
                        'active'     => (bool) $wf->active,
                        'workflow'   => RuleCodec::fromWorkflow($wf),
                        'stored'     => Bridge::attributesOf($wf),
                        'model'      => $wf,
                    ];
                }
                $backup = Backup::create([
                    'pack_key' => $pack->pack_key,
                    'user_id'  => $user ? $user->id : null,
                    'content'  => json_encode([
                        'format'     => 'workflowpacks-backup',
                        'version'    => 1,
                        'created_at' => date('c'),
                        'pack'       => json_decode((string) $pack->source, true),
                        'removed'    => array_map(function ($r) {
                            unset($r['model']);

                            return $r;
                        }, $removed),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                // 2. the rules, with the official model (what Workflows' own "Delete" does)
                $linkIds = $cleaned;
                foreach ($removed as $i => $r) {
                    $r['model']->delete();
                    $linkIds[] = $delete[$i]['link_id'];
                }
                Link::whereIn('id', $linkIds ?: [0])->delete();

                // 3. the pack disappears when none of its rules is left (anywhere)
                $packRemoved = !Link::where('pack_key', $pack->pack_key)->exists();
                if ($packRemoved) {
                    Resource::where('pack_key', $pack->pack_key)->delete();
                    Pack::where('pack_key', $pack->pack_key)->delete();
                }
                ImportLog::create([
                    'pack_key'     => $pack->pack_key,
                    'pack_version' => (string) $pack->pack_version,
                    'user_id'      => $user ? $user->id : null,
                    'summary'      => json_encode(['action' => 'remove', 'deleted' => count($removed), 'kept' => $kept,
                        'cleaned' => count($cleaned), 'pack_removed' => $packRemoved, 'backup_id' => $backup->id]),
                ]);
                $result['deleted'] = count($removed);
                $result['pack_removed'] = $packRemoved;
                $result['backup_id'] = (int) $backup->id;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage() === 'changed' ? 'changed' : $e->getMessage()] + $result;
        }

        return $result;
    }

    /** Proof that nothing changed between the preview and the confirmation (rules, states, fingerprints). */
    protected function token($pack, array $items)
    {
        $state = [$pack->pack_key, hash('sha256', (string) $pack->source)];
        foreach ($items as $item) {
            $state[] = [$item['link_id'], $item['workflow_id'], $item['status'], $item['fingerprint']];
        }

        return hash_hmac('sha256', json_encode($state), (string) config('app.key'));
    }
}
