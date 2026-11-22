<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\FlowPack;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\Engine\Reapply;

/**
 * Packs of flows of the internal engine: import (file, pasted JSON or a pack shipped with the module) -> preview (each
 * flow: created or updated, its problems) -> flows saved as drafts, switched off. Export: the flows as a pack.
 * Administrators, engine on. Works without the Workflows module.
 */
class FlowPackController extends Controller
{
    public function show()
    {
        $this->guardEngine();

        return $this->page('flowpack.import', ['bundled' => array_keys(self::bundled()), 'problems' => []]);
    }

    public function preview(Request $request)
    {
        $this->guardEngine();
        $json = $this->source($request);
        $read = $json === null
            ? ['ok' => false, 'problems' => [__('workflowpacks::flowpack.too_big', ['max' => FlowPack::MAX_BYTES / 1048576])]]
            : (new FlowPack())->read($json, (array) $request->input('params', []));
        if (!$read['ok']) {
            return $this->page('flowpack.import', ['bundled' => array_keys(self::bundled()), 'problems' => $read['problems']]);
        }

        return $this->previewPage($request, $read, $json);
    }

    protected function previewPage(Request $request, array $read, $json, $riskyMissing = false)
    {
        return $this->page('flowpack.preview', ['read' => $read, 'json' => $json, 'values' => (array) $request->input('params', []),
            'mailboxes' => \App\Mailbox::orderBy('name')->get(['id', 'name']), 'chosen' => array_map('intval', (array) $request->input('mailbox_ids', [])),
            'after' => self::after($request), 'risky' => FlowPack::risky($read), 'riskyMissing' => $riskyMissing]);
    }

    /** What to do right after the import: publish, switch on, apply to the tickets already there (period, statuses). */
    protected static function after(Request $request)
    {
        $apply = (bool) $request->input('after_apply');
        $activate = (bool) $request->input('after_activate');

        return ['publish' => $apply || $activate || (bool) $request->input('after_publish'), 'activate' => $activate, 'apply' => $apply,
            'reapply' => Reapply::options(['scope' => 'existing', 'period' => $request->input('after_period', 30), 'statuses' => $request->input('after_statuses', ['active', 'pending'])])];
    }

    public function confirm(Request $request)
    {
        $this->guardEngine();
        $pack = new FlowPack();
        $read = $pack->read((string) $request->input('pack_carry'), (array) $request->input('params', []));
        if (!$read['ok']) {
            return redirect()->route('workflowpacks.flowpack');
        }
        $after = self::after($request);
        // flows that delete tickets or notify: applied to the tickets already there only when it was ticked
        if ($after['apply'] && FlowPack::risky($read) && !$request->input('confirm_risky')) {
            return $this->previewPage($request, $read, (string) $request->input('pack_carry'), true);
        }
        $mailboxes = array_values(array_intersect(array_map('intval', (array) $request->input('mailbox_ids', [])),
            \App\Mailbox::pluck('id')->map(function ($id) {
                return (int) $id;
            })->all()));
        $count = $pack->import($read, $mailboxes, auth()->user());
        \Modules\WorkflowPacks\Services\History::add('import', 'ok', ['subject' => $read['pack']['name'],
            'message' => __('workflowpacks::flowpack.history', $count)]);
        $messages = [__('workflowpacks::flowpack.done', $count)];
        $refused = [];
        if ($after['publish']) {
            $made = ['published' => 0, 'activated' => 0, 'jobs' => 0];
            foreach (Flow::whereIn('id', $pack->imported())->orderBy('name')->get() as $flow) {
                if (!(new Publisher())->publish($flow, auth()->user())['ok']) {
                    $refused[] = $flow->name;
                    continue;
                }
                $made['published']++;
                $flow = $flow->fresh();
                if ($after['activate']) {
                    $flow->update(['active' => true]);
                    $made['activated']++;
                }
                if ($after['apply'] && (new Reapply())->start($flow, $after['reapply'], auth()->user())) {
                    $made['jobs']++;
                }
            }
            \Modules\WorkflowPacks\Services\Engine\Listener::forget();
            $messages[] = __('workflowpacks::flowpack.after_done', $made);
        }
        $back = redirect()->route('workflowpacks.flows')->with('flash_success_floating', implode(' ', $messages));

        return $refused ? $back->with('flash_error_floating', __('workflowpacks::flowpack.after_refused', ['list' => e(implode(' · ', array_slice($refused, 0, 10)))])) : $back;
    }

    /** The page (flows to include, name of the pack), then with "download": those flows as a pack to download. */
    public function export(Request $request)
    {
        $this->guardEngine();
        if (!$request->input('download')) {
            // the flows by pack (each one exported again in one click); ids given: only those ticked at first
            return $this->page('flowpack.export', ['flows' => Flow::permanent()->orderBy('name')->get(['id', 'name', 'active', 'version_id']),
                'groups' => FlowPack::groups(), 'chosen' => array_map('intval', (array) $request->input('ids', []))]);
        }
        $ids = array_map('intval', (array) $request->input('ids', []));
        // a temporary flow is never exported (its list of tickets belongs to this help desk only)
        $flows = $ids ? Flow::permanent()->whereIn('id', $ids)->orderBy('id')->get() : Flow::permanent()->orderBy('id')->get();
        $name = trim((string) $request->input('name')) ?: __('workflowpacks::flowpack.export_name');
        $json = json_encode(FlowPack::export($flows, $name), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response($json."\n", 200, ['Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.FlowPack::slug($name).'.json"']);
    }

    /** Packs of flows shipped with the module (Resources/flows), chosen by name only, never by path. */
    public static function bundled()
    {
        $out = [];
        foreach (glob(__DIR__.'/../../Resources/flows/*.json') ?: [] as $path) {
            $out[basename($path)] = $path;
        }

        return $out;
    }

    /** @return string|null the JSON of the pack; null: the file is too big */
    protected function source(Request $request)
    {
        $file = $request->file('pack_file');
        if ($file) {
            if (!$file->isValid() || $file->getSize() > FlowPack::MAX_BYTES) {
                return null;   // too big (or cut by the upload limits of PHP)
            }

            return (string) file_get_contents($file->getRealPath());
        }
        $bundled = self::bundled();
        if (is_string($request->input('bundled')) && isset($bundled[$request->input('bundled')])) {
            return (string) file_get_contents($bundled[$request->input('bundled')]);
        }

        return (string) ($request->input('pack_carry') ?: $request->input('pack_json'));
    }

}
