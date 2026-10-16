<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Export\Exporter;
use Modules\WorkflowPacks\Services\Export\Grouper;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Export of a selection of workflows, or of an imported pack, to a pack file, and "Group into a pack" (Grouper):
 * the same selection becomes a pack — new, or added to one already imported — without the rules being recreated.
 * Only rules of mailboxes the user may manage are taken, whatever the form sends.
 */
class ExportController extends Controller
{
    public function form(Request $request)
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }

        return $this->formPage($request, []);
    }

    public function preview(Request $request)
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }
        $built = $this->build($request);
        if ($built['errors']) {
            return $this->formPage($request, $built['errors']);
        }

        return $this->page('export.preview', $built + [
            'input'         => $request->except(['_token']),
            'mailbox_names' => Bridge::editableMailboxes(auth()->user())->pluck('name', 'id')->all(),
        ]);
    }

    public function download(Request $request)
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }
        $built = $this->build($request);
        if ($built['errors']) {
            return $this->formPage($request, $built['errors']);
        }
        $name = $built['pack']['key'].'.json';

        return response($built['json'], 200, [
            'Content-Type'        => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** "Group into a pack": the pack is stored and the selected rules are linked to it, then it can be downloaded. */
    public function group(Request $request)
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }
        $built = $this->build($request);
        if ($built['errors'] || empty($built['composed']) || $built['problems']) {
            return $this->formPage($request, $built['errors'] ?: $built['problems'] ?: [__('workflowpacks::messages.export_no_selection')]);
        }
        $result = (new Grouper())->apply($built['composed'], auth()->user());
        if (!$result['ok']) {
            return $this->formPage($request, [$result['error']]);
        }
        $message = __('workflowpacks::messages.group_done', ['name' => $built['pack']['name'], 'count' => $result['counts']['linked'], 'version' => $result['counts']['version']]);
        if ($result['removed_packs']) {
            $message .= ' '.__('workflowpacks::messages.group_packs_removed', ['packs' => implode(', ', $result['removed_packs'])]);
        }

        return redirect()->route('workflowpacks.export', ['pack_key' => $built['pack']['key']])->with('flash_success_floating', $message);
    }

    protected function formPage(Request $request, array $errors)
    {
        $user = auth()->user();
        $mailboxes = Bridge::editableMailboxes($user);
        // every mailbox at once (default), or one
        $mailboxId = (int) $request->input('mailbox_id', 0);
        $mailbox = $mailboxId ? $mailboxes->firstWhere('id', $mailboxId) : null;
        $ids = $mailbox ? [$mailbox->id] : $mailboxes->pluck('id')->all();
        $workflows = $ids ? Bridge::query()->whereIn('mailbox_id', $ids)->orderBy('mailbox_id')->orderBy('type')->orderBy('sort_order')->get() : collect();
        $linked = Link::whereIn('workflow_id', $workflows->pluck('id')->all() ?: [0])->get()->keyBy('workflow_id');
        $packKeys = Link::whereIn('mailbox_id', $mailboxes->pluck('id')->all() ?: [0])->distinct()->pluck('pack_key')->all();
        $packs = $user->isAdmin() ? Pack::orderBy('name')->get() : Pack::whereIn('pack_key', $packKeys ?: [''])->orderBy('name')->get();

        $old = $request->except(['_token']);
        // from the diagram (a mailbox's rules): every listed rule ticked
        if ($request->input('all') && empty($old['workflows'])) {
            $old['workflows'] = $workflows->pluck('id')->all();
        }

        return $this->page('export.form', [
            'mailboxes' => $mailboxes,
            'mailbox'   => $mailbox,
            'workflows' => $workflows,
            'linked'    => $linked,
            'packs'     => $packs,
            // packs the user may change: targets of "Add to an existing pack"
            'targets'   => $packs->filter(function ($pack) use ($user) {
                return Grouper::canModify($user, $pack);
            })->values(),
            'errors_list' => $errors,
            'old'       => $old,
        ]);
    }

    /**
     * @return array ['errors' => [], 'pack' => array, 'json' => string, 'report' => array, 'problems' => [],
     *                'composed' => Grouper::compose() result (selection of rules only)]
     */
    protected function build(Request $request)
    {
        $user = auth()->user();
        $errors = [];
        $exporter = new Exporter();
        $report = ['refs' => [], 'modules' => [], 'secrets' => [], 'replaced' => [], 'from_packs' => [], 'ignored' => []];

        if ($request->input('mode') === 'pack') {
            $key = (string) $request->input('pack_key');
            $pack = Pack::where('pack_key', $key)->first();
            $allowed = $pack && ($user->isAdmin() || Link::where('pack_key', $key)->whereIn('mailbox_id', Bridge::editableMailboxes($user)->pluck('id')->all() ?: [0])->exists());
            $data = $allowed ? $exporter->exportPack($key) : null;
            if ($data === null) {
                return ['errors' => [__('workflowpacks::messages.export_no_pack')], 'pack' => null, 'json' => '', 'report' => $report, 'problems' => [], 'composed' => null];
            }
        } else {
            if ($request->input('target') === 'existing') {
                $target = ['mode' => 'existing', 'pack_key' => (string) $request->input('target_pack'), 'pack_version' => trim((string) $request->input('target_version'))];
            } else {
                $target = [
                    'mode'         => 'new',
                    'key'          => trim((string) $request->input('key')),
                    'name'         => trim((string) $request->input('name')),
                    'description'  => trim((string) $request->input('description')),
                    'pack_version' => trim((string) $request->input('pack_version')) ?: '1.0.0',
                    'requires'     => array_values(array_filter(array_map('trim', explode(',', strtolower((string) $request->input('requires')))))),
                ];
                // the key is made from the name when left empty
                if ($target['key'] === '' && $target['name'] !== '') {
                    $target['key'] = $exporter->slug($target['name']);
                }
                if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $target['key'])) {
                    $errors[] = __('workflowpacks::messages.export_bad_key');
                }
                if ($target['name'] === '') {
                    $errors[] = __('workflowpacks::messages.export_no_name');
                }
            }

            // selected workflows, kept only in mailboxes the user may manage
            $ids = array_map('intval', (array) $request->input('workflows', []));
            $workflows = $ids ? Bridge::query()->whereIn('id', $ids)->get() : collect();
            $workflows = $workflows->filter(function ($wf) use ($user, &$report) {
                if (Bridge::canEdit($user, $wf->mailbox_id)) {
                    return true;
                }
                $report['ignored'][] = '#'.$wf->id;

                return false;
            })->values();
            if ($workflows->isEmpty()) {
                $errors[] = __('workflowpacks::messages.export_no_selection');
            }

            // explicit replacements: exact text -> variable (nothing is guessed)
            $replacements = [];
            $texts = (array) $request->input('replace_text', []);
            foreach ($texts as $i => $text) {
                $text = (string) $text;
                if ($text === '') {
                    continue;
                }
                $target = (string) ($request->input('replace_var')[$i] ?? '');
                if (in_array($target, ['mailbox.id', 'mailbox.email', 'mailbox.name'], true)) {
                    $replacements[] = ['text' => $text, 'variable' => $target];
                } elseif ($target === 'param') {
                    $pkey = (string) ($request->input('replace_param')[$i] ?? '');
                    $ptype = (string) ($request->input('replace_type')[$i] ?? 'string');
                    if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $pkey) || !in_array($ptype, PackValidator::PARAM_TYPES, true)) {
                        $errors[] = __('workflowpacks::messages.export_bad_param', ['text' => $text]);
                        continue;
                    }
                    $replacements[] = ['text' => $text, 'variable' => 'param.'.$pkey, 'type' => $ptype, 'label' => $pkey];
                } elseif ($target === 'resource') {
                    $rkey = (string) ($request->input('replace_param')[$i] ?? '');
                    $rtype = (string) ($request->input('replace_type')[$i] ?? '');
                    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $rkey) || !in_array($rtype, PackValidator::RESOURCE_TYPES, true)) {
                        $errors[] = __('workflowpacks::messages.export_bad_param', ['text' => $text]);
                        continue;
                    }
                    $replacements[] = ['text' => $text, 'variable' => 'resource.'.$rkey, 'type' => $rtype, 'label' => $rkey];
                }
            }
            if ($errors) {
                return ['errors' => $errors, 'pack' => null, 'json' => '', 'report' => $report, 'problems' => [], 'composed' => null];
            }
            $composed = (new Grouper())->compose($workflows, $target, $replacements, $user);
            if ($composed['errors'] && $composed['pack'] === null) {
                return ['errors' => $composed['errors'], 'pack' => null, 'json' => '', 'report' => $report, 'problems' => [], 'composed' => null];
            }
            $data = $composed['pack'];
            $report = $composed['report'] + ['ignored' => $report['ignored']];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // the file must be importable: same validation as the import
        $check = (new PackValidator((array) config('workflowpacks.limits')))->validateJson($json);

        return ['errors' => [], 'pack' => $data, 'json' => $json, 'report' => $report, 'problems' => $check['problems']->lines(),
            'composed' => isset($composed) && !$composed['errors'] ? $composed : null];
    }
}
