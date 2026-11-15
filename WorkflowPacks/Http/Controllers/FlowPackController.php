<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\FlowPack;

/**
 * Packs of flows of the internal engine: import (file, pasted JSON or a pack shipped with the module) -> preview (each
 * flow: created or updated, its problems) -> flows saved as drafts, switched off. Export: the flows as a pack.
 * Administrators, engine on. Works without the Workflows module.
 */
class FlowPackController extends Controller
{
    const MAX_KB = 512;

    public function show()
    {
        $this->guardEngine();

        return $this->page('flowpack.import', ['bundled' => array_keys(self::bundled()), 'problems' => []]);
    }

    public function preview(Request $request)
    {
        $this->guardEngine();
        $json = $this->source($request);
        $read = (new FlowPack())->read($json, (array) $request->input('params', []));
        if (!$read['ok']) {
            return $this->page('flowpack.import', ['bundled' => array_keys(self::bundled()), 'problems' => $read['problems']]);
        }

        return $this->page('flowpack.preview', ['read' => $read, 'json' => $json, 'values' => (array) $request->input('params', []),
            'mailboxes' => \App\Mailbox::orderBy('name')->get(['id', 'name']), 'chosen' => array_map('intval', (array) $request->input('mailbox_ids', []))]);
    }

    public function confirm(Request $request)
    {
        $this->guardEngine();
        $pack = new FlowPack();
        $read = $pack->read((string) $request->input('pack_carry'), (array) $request->input('params', []));
        if (!$read['ok']) {
            return redirect()->route('workflowpacks.flowpack');
        }
        $mailboxes = array_values(array_intersect(array_map('intval', (array) $request->input('mailbox_ids', [])),
            \App\Mailbox::pluck('id')->map(function ($id) {
                return (int) $id;
            })->all()));
        $count = $pack->import($read, $mailboxes, auth()->user());
        \Modules\WorkflowPacks\Services\History::add('import', 'ok', ['subject' => $read['pack']['name'],
            'message' => __('workflowpacks::flowpack.history', $count)]);

        return redirect()->route('workflowpacks.flows')->with('flash_success_floating', __('workflowpacks::flowpack.done', $count));
    }

    /** Every flow (or the ones given) as a pack to download. */
    public function export(Request $request)
    {
        $this->guardEngine();
        $ids = array_map('intval', (array) $request->input('ids', []));
        $flows = $ids ? Flow::whereIn('id', $ids)->orderBy('id')->get() : Flow::orderBy('id')->get();
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

    protected function source(Request $request)
    {
        $file = $request->file('pack_file');
        if ($file) {
            return $file->isValid() && $file->getSize() <= self::MAX_KB * 1024 ? (string) file_get_contents($file->getRealPath()) : '';
        }
        $bundled = self::bundled();
        if (is_string($request->input('bundled')) && isset($bundled[$request->input('bundled')])) {
            return (string) file_get_contents($bundled[$request->input('bundled')]);
        }

        return (string) ($request->input('pack_carry') ?: $request->input('pack_json'));
    }

    protected function guardEngine()
    {
        $this->guard();
        if (!auth()->user()->isAdmin() || !Engine::enabled()) {
            \Helper::denyAccess();
        }
    }
}
