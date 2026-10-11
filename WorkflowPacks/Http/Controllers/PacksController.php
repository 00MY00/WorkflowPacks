<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\MailboxSelector;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Resources\ResourceResolver;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Plan\Importer;
use Modules\WorkflowPacks\Services\Plan\Planner;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Import path: 1. load the JSON -> 2. validation + mailboxes + parameters -> 3. preview (plan, schematic view,
 * choices per rule and mailbox) -> 4. confirmation (plan computed again and compared with the preview).
 * The pack travels between the steps as a form field and is validated again at every step.
 */
class PacksController extends Controller
{
    /** Imported packs, schematic view of their rules and of the mailboxes they were applied to. */
    public function index(Request $request)
    {
        $this->guard();
        $user = auth()->user();
        $mailboxes = Checker::blocking() ? collect() : Bridge::editableMailboxes($user);
        $mailboxIds = $mailboxes->pluck('id')->all();
        $filter = (int) $request->input('mailbox_id');
        $jsonActions = (array) Bridge::config('json_value_actions');

        $packs = [];
        foreach (Pack::orderBy('name')->get() as $pack) {
            $source = json_decode($pack->source, true) ?: [];
            $links = Link::where('pack_key', $pack->pack_key)->whereIn('mailbox_id', $mailboxIds ?: [0])->get();
            if ($filter) {
                $links = $links->where('mailbox_id', $filter);
            }
            if (!$user->isAdmin() && $links->isEmpty()) {
                continue;
            }
            $workflows = $links->pluck('workflow_id')->filter()->all();
            $existing = $workflows && !Checker::blocking() ? Bridge::query()->whereIn('id', $workflows)->get()->keyBy('id') : collect();
            $rules = [];
            foreach ((array) ($source['workflows'] ?? []) as $rule) {
                $states = [];
                foreach ($links->where('workflow_key', $rule['key']) as $link) {
                    $wf = $existing->get($link->workflow_id);
                    $state = 'missing';
                    if ($wf && (int) $wf->mailbox_id === (int) $link->mailbox_id) {
                        $state = Fingerprint::of(Bridge::attributesOf($wf), $jsonActions) === $link->fingerprint ? 'synced' : 'modified';
                    }
                    $states[] = [
                        'mailbox'  => optional($mailboxes->firstWhere('id', $link->mailbox_id))->name ?: '#'.$link->mailbox_id,
                        'state'    => $state,
                        'active'   => $wf ? (bool) $wf->active : false,
                        'previous' => $wf ? (bool) $wf->apply_to_prev : false,
                        'workflow_id' => $state !== 'missing' ? (int) $wf->id : null,
                        'url'      => $wf && \Route::has('mailboxes.workflows.update') ? route('mailboxes.workflows.update', ['mailbox_id' => $wf->mailbox_id, 'id' => $wf->id]) : '',
                        'version'  => $link->pack_version,
                    ];
                }
                $rules[] = ['rule' => $rule, 'states' => $states];
            }
            $packs[] = ['pack' => $pack, 'source' => $source, 'rules' => $rules];
        }

        return $this->page('index', ['packs' => $packs, 'filter' => $filter, 'mailboxes' => $mailboxes]);
    }

    /** Enables / disables the rules ticked in the list (or the one whose switch was clicked), then back to the list. */
    public function toggleRules(Request $request)
    {
        $this->guard();
        $ids = array_map('intval', (array) $request->input('workflow_ids', []));
        $active = (bool) $request->input('active');
        $back = redirect()->route('workflowpacks.index', array_filter(['mailbox_id' => (int) $request->input('mailbox_id')]));
        if (!$ids) {
            return $back->with('flash_error_floating', __('workflowpacks::messages.rules_none_selected'));
        }
        $editor = new \Modules\WorkflowPacks\Services\Graph\RuleEditor();
        if ($request->has('refile')) {
            // "File again": the e-mails of the tickets already handled go back into the folder of each rule
            $result = (new \Modules\WorkflowPacks\Services\Imap\Refiler())->rules(auth()->user(), $ids);
            if (!$result['ok']) {
                return $back->with('flash_error_floating', $result['error']);
            }
            $back = $back->with('flash_success_floating', __('workflowpacks::messages.refile_queued', ['count' => $result['queued'], 'rules' => $result['rules']])
                .($result['more'] ? ' '.__('workflowpacks::messages.refile_more') : ''));
        } elseif ($request->has('previous')) {
            // "apply to previous conversations" on / off instead of enabled / disabled
            $on = (bool) $request->input('previous');
            $result = $editor->applyToPreviousMany(auth()->user(), $ids, $on);
            $back = $back->with('flash_success_floating', __('workflowpacks::messages.'.($on ? 'rules_previous_on' : 'rules_previous_off'), ['count' => $result['changed']]));
        } else {
            $result = $editor->toggleMany(auth()->user(), $ids, $active);
            $back = $back->with('flash_success_floating', __('workflowpacks::messages.'.($active ? 'rules_enabled' : 'rules_disabled'), ['count' => $result['changed']]));
        }
        if ($result['refused']) {
            $back = $back->with('flash_error_floating', __('workflowpacks::messages.rules_refused', ['list' => implode(' · ', array_slice($result['refused'], 0, 10))]));
        }

        return $back;
    }

    public function upload(Request $request)
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }

        return $this->page('import.upload', ['problems' => [], 'mailbox_id' => (int) $request->input('mailbox_id')]);
    }

    /** Step 2: file validated; choice of the mailboxes and values of the parameters. */
    public function configure(Request $request)
    {
        $this->guard();
        // before anything reads the Workflows catalog (Workflows keeps it for the whole request)
        $this->enableImapOnRequest($request);
        if (Checker::blocking()) {
            return $this->blockedPage();
        }
        list($json, $result) = $this->loadPack($request);
        if ($result['pack'] === null) {
            return $this->page('import.upload', ['problems' => $result['problems']->all(), 'mailbox_id' => (int) $request->input('mailbox_id')]);
        }

        return $this->configurePage($json, $result['pack'], (array) $request->input('mailboxes', []), (array) $request->input('params', []), (int) $request->input('mailbox_id'), [], (array) $request->input('resources', []));
    }

    /** Update of an imported pack: same path, starting from the stored template. */
    public function reimport($key)
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }
        $pack = Pack::where('pack_key', $key)->firstOrFail();
        $result = $this->validator()->validateJson($pack->source);
        if ($result['pack'] === null) {
            return $this->page('import.upload', ['problems' => $result['problems']->all(), 'mailbox_id' => 0]);
        }

        return $this->configurePage($pack->source, $result['pack'], [], [], 0);
    }

    /** Step 3: what the import would do, per rule and mailbox. */
    public function preview(Request $request)
    {
        $this->guard();
        // before anything reads the Workflows catalog (Workflows keeps it for the whole request)
        $this->enableImapOnRequest($request);
        if (Checker::blocking()) {
            return $this->blockedPage();
        }
        list($json, $result) = $this->loadPack($request);
        if ($result['pack'] === null) {
            return $this->page('import.upload', ['problems' => $result['problems']->all(), 'mailbox_id' => 0]);
        }
        $plan = (new Planner())->plan($result['pack'], (array) $request->input('mailboxes', []), (array) $request->input('params', []), auth()->user(), (array) $request->input('resources', []));
        if (!$plan['problems']->isEmpty()) {
            return $this->configurePage($json, $result['pack'], (array) $request->input('mailboxes', []), (array) $request->input('params', []), 0, $plan['problems']->all(), (array) $request->input('resources', []));
        }

        return $this->page('import.preview', ['plan' => $plan, 'json' => $json, 'input' => $request->only(['mailboxes', 'params', 'resources']), 'warning' => '',
            'imapOff' => ImapFolders::usedBy($result['pack']) && !ImapFolders::enabled()]);
    }

    /**
     * "Turn it on" button of the import, shown when the pack files e-mails into folders while the option is off
     * (administrators only: it is a setting of Manage › Settings › Workflow Packs).
     */
    protected function enableImapOnRequest(Request $request)
    {
        if ($request->input('enable_imap') && auth()->user()->isAdmin() && !ImapFolders::enabled()) {
            ImapFolders::setEnabled(true);
            \Session::flash('flash_success_floating', __('workflowpacks::messages.imap_enabled_now'));
        }
    }

    /** Step 4: the plan is computed again; it must be the one that was previewed. */
    public function confirm(Request $request)
    {
        $this->guard();
        if (Checker::blocking()) {
            return $this->blockedPage();
        }
        list($json, $result) = $this->loadPack($request);
        if ($result['pack'] === null) {
            return $this->page('import.upload', ['problems' => $result['problems']->all(), 'mailbox_id' => 0]);
        }
        $user = auth()->user();
        $plan = (new Planner())->plan($result['pack'], (array) $request->input('mailboxes', []), (array) $request->input('params', []), $user, (array) $request->input('resources', []));
        $input = $request->only(['mailboxes', 'params', 'resources']);
        if (!hash_equals($plan['token'], (string) $request->input('token'))) {
            return $this->page('import.preview', ['plan' => $plan, 'json' => $json, 'input' => $input,
                'warning' => __('workflowpacks::messages.plan_changed')]);
        }

        $decisions = [];
        foreach ((array) $request->input('decision', []) as $ruleKey => $byMailbox) {
            foreach ((array) $byMailbox as $mailboxId => $choice) {
                $decisions[(string) $ruleKey][(int) $mailboxId] = (string) $choice;
            }
        }
        $options = [
            'activate_new'        => (bool) $request->input('activate_new'),
            'allow_apply_to_prev' => (bool) $request->input('allow_apply_to_prev'),
            'active_mode'         => in_array($request->input('active_mode'), ['keep', 'activate', 'deactivate'], true) ? $request->input('active_mode') : 'keep',
            'ack_blocked'         => (bool) $request->input('ack_blocked'),
        ];
        $outcome = (new Importer())->apply($plan, $decisions, $options, $user);
        if (!$outcome['ok']) {
            return $this->page('import.preview', ['plan' => $plan, 'json' => $json, 'input' => $input, 'warning' => $outcome['error']]);
        }
        \Log::info('[WorkflowPacks] pack '.$plan['pack']['key'].' imported by user #'.$user->id.': '.json_encode($outcome['counts']));

        return $this->page('import.result', ['plan' => $plan, 'outcome' => $outcome]);
    }

    protected function configurePage($json, array $pack, array $selected, array $params, $mailboxId, array $problems = [], array $resourceInput = [])
    {
        $user = auth()->user();
        $mailboxes = Bridge::editableMailboxes($user);
        $previous = Link::where('pack_key', $pack['key'])->whereIn('mailbox_id', $mailboxes->pluck('id')->all() ?: [0])->get();

        // v2 selectors, evaluated here on the mailboxes the user may manage (and nowhere else)
        $selectors = [];
        if ($pack['mailboxes'] !== null) {
            $selectors['*'] = $pack['mailboxes'];
        }
        foreach ($pack['workflows'] as $rule) {
            if ($rule['mailboxes'] !== null) {
                $selectors[$rule['key']] = $rule['mailboxes'];
            }
        }
        $selectorPreview = [];
        foreach ($selectors as $ruleKey => $selector) {
            $matches = [];
            foreach ($mailboxes as $mailbox) {
                $matches[(int) $mailbox->id] = MailboxSelector::matches($selector, $mailbox->email, $mailbox->name);
            }
            $selectorPreview[$ruleKey] = ['text' => MailboxSelector::describe($selector), 'matches' => $matches];
        }

        if (!$selected) {
            $selected = $previous->pluck('mailbox_id')->unique()->values()->all();
            if (!$selected && $selectorPreview) {
                // first import: the mailboxes matching at least one selector are ticked; the user can untick them
                foreach ($selectorPreview as $preview) {
                    $selected = array_merge($selected, array_keys(array_filter($preview['matches'])));
                }
                $selected = array_values(array_unique($selected));
            }
            if (!$selected && $mailboxId) {
                $selected = [$mailboxId];
            }
        }
        if (!$params) {
            $last = $previous->sortByDesc('updated_at')->first();
            $params = $last ? (json_decode((string) $last->params, true) ?: []) : [];
        }
        $requires = $pack['requires'];
        foreach ($pack['workflows'] as $rule) {
            $requires = array_merge($requires, $rule['requires']);
        }
        $modules = [];
        foreach (array_unique($requires) as $alias) {
            $modules[$alias] = Bridge::moduleActive($alias);
        }

        return $this->page('import.configure', [
            'json'      => $json,
            'pack'      => $pack,
            'mailboxes' => $mailboxes,
            'selected'  => array_map('intval', $selected),
            'params'    => $params,
            'modules'   => $modules,
            'problems'  => $problems,
            'existing'  => Pack::where('pack_key', $pack['key'])->first(),
            'selectors' => $selectorPreview,
            'resources' => ResourceResolver::resolve($pack, $resourceInput, $user, new Problems()),
            'imapOff'   => ImapFolders::usedBy($pack) && !ImapFolders::enabled(),
        ]);
    }

    /** JSON of the pack from the uploaded file, the text area or the field carried between the steps. */
    protected function loadPack(Request $request)
    {
        $json = '';
        $max = (int) config('workflowpacks.limits.file_kb', 512) * 1024;
        if ($request->hasFile('pack_file')) {
            $file = $request->file('pack_file');
            if ($file->isValid() && $file->getSize() <= $max) {
                $json = (string) file_get_contents($file->getRealPath());
            } else {
                $json = str_repeat(' ', $max + 1);
            }
        } elseif (is_string($request->input('bundled')) && in_array($request->input('bundled'), array_keys(self::bundledPacks()), true)) {
            // packs shipped with the module, chosen by name only (never a path)
            $json = (string) file_get_contents(self::bundledPacks()[$request->input('bundled')]);
        } elseif (is_string($request->input('pack_b64')) && $request->input('pack_b64') !== '') {
            $json = (string) base64_decode($request->input('pack_b64'), true);
        } elseif (is_string($request->input('pack_json'))) {
            $json = $request->input('pack_json');
        }

        return [$json, $this->validator()->validateJson($json)];
    }

    /** Packs shipped in Resources/packs: file name => path. */
    public static function bundledPacks()
    {
        $out = [];
        foreach (glob(__DIR__.'/../../Resources/packs/*.json') ?: [] as $path) {
            $out[basename($path)] = $path;
        }

        return $out;
    }

    protected function validator()
    {
        return new PackValidator((array) config('workflowpacks.limits'));
    }
}
