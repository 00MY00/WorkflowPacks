<?php

namespace Modules\WorkflowPacks\Services;

use Modules\WorkflowPacks\Entities\Activity;

/**
 * History of what the module did, shown in Workflow Packs › History (FreeScout's log is not readable without access to
 * the server). Writing it never breaks the action itself: any error is ignored. Entries older than 90 days, or beyond
 * the 20000 most recent, are removed by the clean-up command.
 *
 *   imap_file   "file into an e-mail folder": queued (Workflows called the action), skipped (why), then moved /
 *               already / not_found / failed when the job has run
 *   imap_clean  automatic clean-up of a folder (e-mails moved to the trash or deleted)
 *   import      pack imported            remove   pack removed            rules   rules changed from the module
 */
class History
{
    const KEEP_DAYS = 90;
    const KEEP_ROWS = 20000;

    /** @return int|null id of the entry */
    public static function add($kind, $status, array $data = [])
    {
        try {
            return (int) Activity::create([
                'kind'            => (string) $kind,
                'status'          => (string) $status,
                'mailbox_id'      => isset($data['mailbox_id']) ? (int) $data['mailbox_id'] : null,
                'conversation_id' => isset($data['conversation_id']) ? (int) $data['conversation_id'] : null,
                'workflow_id'     => isset($data['workflow_id']) ? (int) $data['workflow_id'] : null,
                'user_id'         => isset($data['user_id']) ? (int) $data['user_id'] : (auth()->check() ? (int) auth()->id() : null),
                'subject'         => mb_substr((string) ($data['subject'] ?? ''), 0, 191),
                'message'         => isset($data['message']) ? mb_substr((string) $data['message'], 0, 2000) : null,
            ])->id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** New state of an entry (the job of a queued filing has run). */
    public static function update($id, $status, array $data = [])
    {
        if (!$id) {
            return;
        }
        try {
            $changes = ['status' => (string) $status];
            if (isset($data['subject'])) {
                $changes['subject'] = mb_substr((string) $data['subject'], 0, 191);
            }
            if (array_key_exists('message', $data)) {
                $changes['message'] = $data['message'] === null ? null : mb_substr((string) $data['message'], 0, 2000);
            }
            Activity::where('id', (int) $id)->update($changes + ['updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
        }
    }

    public static function prune()
    {
        try {
            Activity::where('created_at', '<', date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400))->delete();
            $last = Activity::orderBy('id', 'desc')->skip(self::KEEP_ROWS)->value('id');
            if ($last) {
                Activity::where('id', '<=', $last)->delete();
            }
        } catch (\Throwable $e) {
        }
    }
}
