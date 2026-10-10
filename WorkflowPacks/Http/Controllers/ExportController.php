<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Export\Exporter;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Export of a selection of workflows, or of an imported pack, to a pack file. Only rules of mailboxes the user
 * may manage are exported, whatever the form sends.
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

        return $this->page('export.preview', $built + ['input' => $request->except(['_token'])]);
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

    protected function formPage(Request $request, array $errors)
    {
        $user = auth()->user();
        $mailboxes = Bridge::editableMailboxes($user);
        $mailboxId = (int) $request->input('mailbox_id', optional($mailboxes->first())->id);
        if (!$mailboxes->contains('id', $mailboxId)) {
            $mailboxId = (int) optional($mailboxes->first())->id;
        }
        $workflows = $mailboxId ? Bridge::query()->where('mailbox_id', $mailboxId)->orderBy('type')->orderBy('sort_order')->get() : collect();
        $linked = Link::whereIn('workflow_id', $workflows->pluck('id')->all() ?: [0])->get()->keyBy('workflow_id');
        $packKeys = Link::whereIn('mailbox_id', $mailboxes->pluck('id')->all() ?: [0])->distinct()->pluck('pack_key')->all();
        $packs = $user->isAdmin() ? Pack::orderBy('name')->get() : Pack::whereIn('pack_key', $packKeys ?: [''])->orderBy('name')->get();

        return $this->page('export.form', [
            'mailboxes' => $mailboxes,
            'mailbox'   => $mailboxes->firstWhere('id', $mailboxId),
            'workflows' => $workflows,
            'linked'    => $linked,
            'packs'     => $packs,
            'errors_list' => $errors,
            'old'       => $request->except(['_token']),
        ]);
    }

    /** @return array ['errors' => [], 'pack' => array, 'json' => string, 'report' => array, 'problems' => []] */
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
                return ['errors' => [__('workflowpacks::messages.export_no_pack')], 'pack' => null, 'json' => '', 'report' => $report, 'problems' => []];
            }
        } else {
            $meta = [
                'key'          => trim((string) $request->input('key')),
                'name'         => trim((string) $request->input('name')),
                'description'  => trim((string) $request->input('description')),
                'pack_version' => trim((string) $request->input('pack_version', '1.0.0')),
                'requires'     => array_values(array_filter(array_map('trim', explode(',', strtolower((string) $request->input('requires')))))),
            ];
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $meta['key'])) {
                $errors[] = __('workflowpacks::messages.export_bad_key');
            }
            if ($meta['name'] === '') {
                $errors[] = __('workflowpacks::messages.export_no_name');
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
                return ['errors' => $errors, 'pack' => null, 'json' => '', 'report' => $report, 'problems' => []];
            }
            $result = $exporter->exportWorkflows($workflows, $meta, $replacements);
            $data = $result['pack'];
            $report = $result['report'] + ['ignored' => $report['ignored']];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // the file must be importable: same validation as the import
        $check = (new PackValidator((array) config('workflowpacks.limits')))->validateJson($json);

        return ['errors' => [], 'pack' => $data, 'json' => $json, 'report' => $report, 'problems' => $check['problems']->lines()];
    }
}
