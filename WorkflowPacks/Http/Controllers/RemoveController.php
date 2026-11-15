<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Backup;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Plan\Remover;

/**
 * Workflow Packs › "Remove the pack": preview (what will be deleted, mailbox by mailbox) -> confirmation (preview
 * computed again and compared) -> result with the backup to download. See Services/Plan/Remover.
 */
class RemoveController extends Controller
{
    public function preview(Request $request, $key)
    {
        $this->guard();
        if (Checker::packsBlocked()) {
            return $this->blockedPage();
        }
        $plan = (new Remover())->plan($key, auth()->user(), $this->selected($request));
        abort_if(!$plan, 404);

        return $this->page('remove.preview', ['plan' => $plan, 'warning' => '', 'deleteModified' => false]);
    }

    public function confirm(Request $request, $key)
    {
        $this->guard();
        if (Checker::packsBlocked()) {
            return $this->blockedPage();
        }
        $user = auth()->user();
        $remover = new Remover();
        $plan = $remover->plan($key, $user, $this->selected($request));
        abort_if(!$plan, 404);
        $deleteModified = (bool) $request->input('delete_modified');
        if (!hash_equals($plan['token'], (string) $request->input('token'))) {
            return $this->page('remove.preview', ['plan' => $plan, 'deleteModified' => $deleteModified,
                'warning' => __('workflowpacks::messages.plan_changed')]);
        }
        $outcome = $remover->apply($plan, $deleteModified, $user);
        if (!$outcome['ok']) {
            return $this->page('remove.preview', ['plan' => $remover->plan($key, $user, $this->selected($request)), 'deleteModified' => $deleteModified,
                'warning' => $outcome['error'] === 'changed' ? __('workflowpacks::messages.plan_changed') : $outcome['error']]);
        }
        \Log::info('[WorkflowPacks] pack '.$plan['pack']->pack_key.' removed by user #'.$user->id.': '.json_encode(array_diff_key($outcome, ['ok' => 0, 'error' => 0])));

        return $this->page('remove.result', ['plan' => $plan, 'outcome' => $outcome]);
    }

    /**
     * Remove a pack when the Workflows module is not there (its rules cannot be reached): what Workflow Packs keeps
     * of it — the pack, its links to the rules, its resources — is deleted, after a backup (downloadable, as for a
     * normal removal). Administrators; the rules possibly left in Workflows' tables are not touched.
     */
    public function forget($key)
    {
        $this->guard();
        if (!auth()->user()->isAdmin() || Checker::coreBlocking() || Checker::workflowsReady()) {
            \Helper::denyAccess();
        }
        $pack = \Modules\WorkflowPacks\Entities\Pack::where('pack_key', (string) $key)->firstOrFail();
        $links = \Modules\WorkflowPacks\Entities\Link::where('pack_key', $pack->pack_key)->get();
        $backup = \DB::transaction(function () use ($pack, $links) {
            $backup = Backup::create(['pack_key' => $pack->pack_key, 'user_id' => auth()->id(), 'content' => json_encode([
                'format' => 'workflowpacks-backup', 'version' => 1, 'created_at' => date('c'), 'without_workflows' => true,
                'pack' => json_decode((string) $pack->source, true),
                'links' => $links->map(function ($l) {
                    return ['workflow_key' => $l->workflow_key, 'mailbox_id' => (int) $l->mailbox_id, 'workflow_id' => (int) $l->workflow_id];
                })->values()->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            \Modules\WorkflowPacks\Entities\Link::where('pack_key', $pack->pack_key)->delete();
            \Modules\WorkflowPacks\Entities\Resource::where('pack_key', $pack->pack_key)->delete();
            $pack->delete();

            return $backup;
        });
        \Modules\WorkflowPacks\Services\History::add('remove', 'ok', ['subject' => $pack->name, 'message' => __('workflowpacks::messages.forget_history')]);

        return redirect()->route('workflowpacks.index')->with('flash_success_floating',
            __('workflowpacks::messages.forget_done', ['name' => $pack->name, 'url' => route('workflowpacks.backup', ['id' => $backup->id, 'part' => 'pack'])]));
    }

    /** Backup made before a removal: the pack as imported ("pack") or everything that was deleted ("full"). */
    public function backup(Request $request, $id)
    {
        $this->guard();
        $user = auth()->user();
        $backup = Backup::find((int) $id);
        // only its author, or an administrator (it holds the rules of the mailboxes that were concerned)
        abort_if(!$backup || (!$user->isAdmin() && (int) $backup->user_id !== (int) $user->id), 404);
        $content = json_decode($backup->content, true) ?: [];
        $part = $request->input('part') === 'pack' ? 'pack' : 'full';
        $data = $part === 'pack' ? ($content['pack'] ?? []) : $content;
        $name = preg_replace('/[^a-z0-9._-]+/i', '-', $backup->pack_key).($part === 'pack' ? '' : '-sauvegarde-'.$backup->created_at->format('Ymd-His')).'.json';

        return response(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /**
     * Mailboxes ticked on the preview. First display (no form sent): every mailbox of the pack the user manages.
     * Form sent with nothing ticked: no mailbox (an unchecked box is not posted: "mailboxes_sent" tells it apart).
     */
    protected function selected(Request $request)
    {
        if (!$request->has('mailboxes_sent')) {
            return null;
        }

        return array_map('intval', (array) $request->input('mailboxes', []));
    }
}
