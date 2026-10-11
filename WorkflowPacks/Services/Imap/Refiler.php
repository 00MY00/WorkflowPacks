<?php

namespace Modules\WorkflowPacks\Services\Imap;

use Modules\WorkflowPacks\Entities\Activity;
use Modules\WorkflowPacks\Jobs\RefileMailbox;
use Modules\WorkflowPacks\Services\History;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * "File again": the e-mails of the existing tickets go into the e-mail folder of the chosen rules. Only the "File into
 * an e-mail folder" action is done, never the other actions of the rules (status, assignment, forward…), and the
 * counters of Workflows do not change.
 *
 * Tickets taken, mailbox by mailbox, in the background (RefileMailbox, by pages):
 *  - those matching the conditions of a rule, checked by Workflows itself (checkConditions()). "New" is an event,
 *    never true for a ticket that already exists: a group of conditions holding it counts as true (every ticket was
 *    new once), as when the rule ran on it. "Reply" stays an event: such a rule only takes the tickets it handled;
 *  - those the rule has already handled (executions kept by Workflows, history of the module), even when its
 *    conditions no longer match (e.g. the rule closed the ticket).
 * When several rules apply to a ticket, the last one in the order of Workflows wins, as when they run one after the
 * other. Each e-mail is then queued like a normal filing; the job also looks for it outside the inbox (never in the
 * trash), and an e-mail already in its folder is left as it is.
 */
class Refiler
{
    /** tickets looked at per page of the background job */
    const PAGE = 100;

    /**
     * Starts the search, one background job per mailbox.
     *
     * @return array ['ok' => bool, 'error' => string, 'rules' => int, 'mailboxes' => int, 'no_folder' => int,
     *               'no_imap' => string[] (mailbox names), 'refused' => string[]]
     */
    public function rules($user, array $workflowIds)
    {
        $out = ['ok' => true, 'error' => '', 'rules' => 0, 'mailboxes' => 0, 'no_folder' => 0, 'no_imap' => [], 'refused' => []];
        if (!ImapFolders::enabled()) {
            return ['ok' => false, 'error' => __('workflowpacks::messages.history_reason_option_off')] + $out;
        }
        $byMailbox = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $workflowIds))), 0, 5000) as $id) {
            $wf = $id ? Bridge::query()->find($id) : null;
            if (!$wf || !Bridge::canEdit($user, $wf->mailbox_id)) {
                $out['refused'][] = '#'.$id.' : '.Problems::t('denied');
                continue;
            }
            // rules without e-mail folder: nothing to file, just counted
            if (self::folderOf($wf) === null) {
                $out['no_folder']++;
                continue;
            }
            $byMailbox[(int) $wf->mailbox_id][] = (int) $wf->id;
        }
        foreach ($byMailbox as $mailboxId => $ids) {
            $mailbox = \App\Mailbox::find($mailboxId);
            if (!$mailbox) {
                continue;
            }
            if (!$mailbox->in_server || !$mailbox->in_username) {
                $out['no_imap'][] = $mailbox->name;
                continue;
            }
            $activity = History::add('rules', 'refile', ['mailbox_id' => $mailboxId, 'user_id' => $user->id, 'subject' => $mailbox->name,
                'message' => __('workflowpacks::messages.refile_running', ['rules' => count($ids)])]);
            RefileMailbox::dispatch($mailboxId, $ids, $activity);
            $out['rules'] += count($ids);
            $out['mailboxes']++;
        }

        return $out;
    }

    /** What to tell after the click: [text of success or null, text of warning or null]. */
    public static function summary(array $r)
    {
        if (!$r['ok']) {
            return [null, $r['error']];
        }
        $ok = $r['mailboxes'] ? __('workflowpacks::messages.refile_started', ['rules' => $r['rules'], 'mailboxes' => $r['mailboxes']]) : null;
        $notes = [];
        if (!$r['mailboxes'] && !$r['no_imap'] && !$r['refused']) {
            $notes[] = __('workflowpacks::messages.refile_nothing');
        }
        if ($r['no_folder'] && $r['mailboxes']) {
            $ok .= ' '.__('workflowpacks::messages.refile_without_folder', ['count' => $r['no_folder']]);
        }
        if ($r['no_imap']) {
            $notes[] = __('workflowpacks::messages.refile_no_imap', ['list' => implode(', ', array_slice($r['no_imap'], 0, 10))]);
        }
        if ($r['refused']) {
            $notes[] = __('workflowpacks::messages.rules_refused', ['list' => implode(' · ', array_slice($r['refused'], 0, 10))]);
        }

        return [$ok, $notes ? implode(' ', $notes) : null];
    }

    /**
     * One page of the search of a mailbox: tickets older than $beforeId (newest first). Queues the filings and returns
     * ['next' => id to go on from or null when done, 'seen' => int, 'queued' => int, 'skipped' => int].
     */
    public function page($mailboxId, array $workflowIds, $beforeId = null)
    {
        $out = ['next' => null, 'seen' => 0, 'queued' => 0, 'skipped' => 0];
        if (!ImapFolders::enabled()) {
            return $out;
        }
        $rules = $this->prepare($mailboxId, $workflowIds);
        if (!$rules) {
            return $out;
        }
        $query = \App\Conversation::where('mailbox_id', $mailboxId)->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->orderBy('id', 'desc')->limit(self::PAGE);
        if ($beforeId) {
            $query->where('id', '<', (int) $beforeId);
        }
        $conversations = $query->get();
        foreach ($conversations as $conversation) {
            $out['seen']++;
            // the last rule applying to the ticket wins (as when Workflows runs them one after the other)
            $chosen = null;
            foreach ($rules as $rule) {
                if (isset($rule['handled'][(int) $conversation->id]) || $this->matches($rule['checker'], $conversation)) {
                    $chosen = $rule;
                }
            }
            if ($chosen) {
                if (ImapFolders::queue($conversation, $chosen['folder'], $chosen['workflow'], true)) {
                    $out['queued']++;
                } else {
                    $out['skipped']++;
                }
            }
        }
        $out['next'] = $conversations->count() === self::PAGE ? (int) $conversations->last()->id : null;

        return $out;
    }

    /** Rules of the mailbox, in the order of Workflows, with their folder, condition checker and handled tickets. */
    protected function prepare($mailboxId, array $workflowIds)
    {
        $rules = [];
        $workflows = Bridge::query()->whereIn('id', $workflowIds ?: [0])->where('mailbox_id', $mailboxId)
            ->orderBy('sort_order')->orderBy('id')->get();
        foreach ($workflows as $wf) {
            $folder = self::folderOf($wf);
            if ($folder === null) {
                continue;
            }
            $rules[] = ['workflow' => $wf, 'folder' => $folder, 'checker' => $this->checker($wf), 'handled' => array_flip($this->handledBy($wf))];
        }

        return $rules;
    }

    /**
     * Copy of the rule (never saved) whose conditions can be checked on an existing ticket: the groups holding
     * "New" are true for it and left out. Null for a manual rule (only the tickets it has handled are taken).
     */
    protected function checker($workflow)
    {
        if (!method_exists($workflow, 'checkConditions') || (int) $workflow->type === 2) {
            return null;
        }
        $conditions = [];
        foreach ((array) Bridge::attributesOf($workflow)['conditions'] as $group) {
            $event = false;
            foreach ((array) $group as $row) {
                if (($row['type'] ?? '') === 'new_or_reply' && ($row['operator'] ?? '') === 'new') {
                    $event = true;
                }
            }
            if (!$event) {
                $conditions[] = $group;
            }
        }
        $copy = $workflow->replicate();
        $copy->id = $workflow->id;
        $copy->conditions = $conditions;

        return $copy;
    }

    protected function matches($checker, $conversation)
    {
        if (!$checker) {
            return false;
        }
        try {
            return (bool) $checker->checkConditions($conversation);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Folder written in the "File into an e-mail folder" action of a rule (the last one), or null. */
    public static function folderOf($workflow)
    {
        $attributes = Bridge::attributesOf($workflow);
        $folder = null;
        foreach (Fingerprint::decodeActions($attributes['actions'], (array) Bridge::config('json_value_actions')) as $action) {
            if (($action['type'] ?? null) === FolderPath::ACTION && is_string($action['value'] ?? null) && FolderPath::parse($action['value'])['error'] === null) {
                $folder = $action['value'];
            }
        }

        return $folder;
    }

    /** Ids of the tickets the rule has already handled (executions of Workflows, history of the module). */
    protected function handledBy($workflow)
    {
        $ids = Activity::where('kind', 'imap_file')->where('workflow_id', $workflow->id)->whereNotNull('conversation_id')
            ->distinct()->pluck('conversation_id')->all();
        try {
            if (\Schema::hasTable('conversation_workflow') && \Schema::hasColumns('conversation_workflow', ['conversation_id', 'workflow_id'])) {
                $ids = array_merge($ids, \DB::table('conversation_workflow')->where('workflow_id', $workflow->id)
                    ->distinct()->pluck('conversation_id')->all());
            }
        } catch (\Throwable $e) {
            // another structure: the history of the module is enough
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
