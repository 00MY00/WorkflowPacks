<?php

namespace Modules\WorkflowPacks\Services\Imap;

use Modules\WorkflowPacks\Entities\Activity;
use Modules\WorkflowPacks\Services\History;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * "File again": the e-mails of the tickets a rule has already handled go back into its e-mail folder, e.g. after
 * they were moved by hand or by a mail client. Only the "File into an e-mail folder" action of the rule is run again,
 * never its other actions (status, assignment, forward…), and Workflows is not involved: its counters do not change.
 *
 * Tickets handled by a rule: those of the history of the module (filings asked by this rule) and, when Workflows
 * keeps them, those of its own table of executions (conversation_workflow). Each e-mail is queued like a normal
 * filing; the job also looks for it outside the inbox (never in the trash). An e-mail already in its folder is left
 * as it is.
 */
class Refiler
{
    /** tickets queued at most per request (the next request takes the others: those already filed are "already") */
    const MAX = 2000;

    /**
     * @return array ['ok' => bool, 'queued' => int, 'skipped' => int, 'rules' => int, 'more' => bool, 'refused' => string[], 'error' => string]
     */
    public function rules($user, array $workflowIds)
    {
        $out = ['ok' => true, 'queued' => 0, 'skipped' => 0, 'rules' => 0, 'more' => false, 'refused' => [], 'error' => ''];
        if (!ImapFolders::enabled()) {
            return ['ok' => false, 'error' => __('workflowpacks::messages.history_reason_option_off')] + $out;
        }
        $budget = self::MAX;
        foreach (array_slice(array_values(array_unique(array_map('intval', $workflowIds))), 0, 1000) as $id) {
            $wf = $id ? Bridge::query()->find($id) : null;
            if (!$wf || !Bridge::canEdit($user, $wf->mailbox_id)) {
                $out['refused'][] = '#'.$id.' : '.Problems::t('denied');
                continue;
            }
            $folder = self::folderOf($wf);
            if ($folder === null) {
                $out['refused'][] = $wf->name.' : '.__('workflowpacks::messages.refile_no_folder');
                continue;
            }
            $ids = $this->handledBy($wf);
            if (count($ids) > $budget) {
                $out['more'] = true;
                $ids = array_slice($ids, 0, $budget);
            }
            $queued = 0;
            foreach (array_chunk($ids, 200) as $chunk) {
                $conversations = \App\Conversation::whereIn('id', $chunk)->where('mailbox_id', $wf->mailbox_id)
                    ->where('state', '!=', \App\Conversation::STATE_DELETED)->get();
                foreach ($conversations as $conversation) {
                    if (ImapFolders::queue($conversation, $folder, $wf, true)) {
                        $queued++;
                    } else {
                        $out['skipped']++;
                    }
                }
            }
            $budget -= count($ids);
            $out['queued'] += $queued;
            $out['rules']++;
            History::add('rules', 'refile', ['mailbox_id' => $wf->mailbox_id, 'workflow_id' => $wf->id, 'subject' => $wf->name,
                'message' => __('workflowpacks::messages.refile_done', ['count' => $queued])]);
            if ($budget <= 0) {
                $out['more'] = true;
                break;
            }
        }

        return $out;
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

    /** Ids of the tickets the rule has already handled, newest first. */
    protected function handledBy($workflow)
    {
        $ids = Activity::where('kind', 'imap_file')->where('workflow_id', $workflow->id)->whereNotNull('conversation_id')
            ->orderBy('conversation_id', 'desc')->distinct()->pluck('conversation_id')->all();
        try {
            // executions kept by Workflows (all its history, also before this module): read only, when the table is there
            if (\Schema::hasTable('conversation_workflow') && \Schema::hasColumns('conversation_workflow', ['conversation_id', 'workflow_id'])) {
                $ids = array_merge($ids, \DB::table('conversation_workflow')->where('workflow_id', $workflow->id)
                    ->orderBy('conversation_id', 'desc')->distinct()->pluck('conversation_id')->all());
            }
        } catch (\Throwable $e) {
            // another structure: the history of the module is enough
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        rsort($ids);

        return $ids;
    }
}
