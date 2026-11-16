<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Entities\FlowVersion;
use Modules\WorkflowPacks\Services\Engine\BlockRegistry;
use Modules\WorkflowPacks\Services\Engine\Definition;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Publisher;

/**
 * Flows of the internal engine (3.0): page of the editor (Public/js/flows/editor.js) and its JSON
 * endpoints. Administrators only for now; everything is checked again on the server (publishing: Definition).
 */
class FlowsController extends Controller
{
    /** draft of the editor: at most this size (JSON) */
    const MAX_DRAFT = 262144;

    /** @var BlockRegistry */
    protected $registry;

    public function __construct()
    {
        $this->registry = new BlockRegistry();
    }

    public function show(Request $request)
    {
        $this->guardEngine();

        return $this->page('flows', [
            'config' => [
                'open'      => (int) $request->input('flow') ?: null,
                'urls'      => [
                    'data'     => route('workflowpacks.flows.data'),
                    'store'    => route('workflowpacks.flows.store'),
                    'save'     => route('workflowpacks.flows.save', ['id' => '__ID__']),
                    'publish'  => route('workflowpacks.flows.publish', ['id' => '__ID__']),
                    'toggle'   => route('workflowpacks.flows.toggle', ['id' => '__ID__']),
                    'destroy'  => route('workflowpacks.flows.destroy', ['id' => '__ID__']),
                    'destroy_many' => route('workflowpacks.flows.destroy_many'),
                    'bulk'     => route('workflowpacks.flows.bulk'),
                    'simulate' => route('workflowpacks.flows.simulate', ['id' => '__ID__']),
                    'path'     => route('workflowpacks.flows.path', ['id' => '__ID__']),
                    'reapply'  => route('workflowpacks.reapply', ['id' => '__ID__']),
                ],
                'catalog'   => $this->catalog(),
                'mailboxes' => \App\Mailbox::orderBy('name')->get(['id', 'name'])->map(function ($m) {
                    return ['id' => (int) $m->id, 'name' => (string) $m->name];
                })->values(),
                'users'     => \App\User::where('status', \App\User::STATUS_ACTIVE)->orderBy('first_name')->get()->map(function ($u) {
                    return ['id' => (int) $u->id, 'name' => $u->getFullName()];
                })->values(),
                'texts'     => trans('workflowpacks::flows'),
                'blockText' => [
                    'outputs'  => trans('workflowpacks::blocks.outputs'),
                    'families' => trans('workflowpacks::blocks.families'),
                    'ops'      => trans('workflowpacks::blocks.ops'),
                    'vars'     => $this->varLabels(),
                    'values'   => trans('workflowpacks::blocks.values'),
                ],
            ],
        ]);
    }

    public function data()
    {
        $this->guardEngine();
        $flows = Flow::orderBy('name')->get();
        $numbers = FlowVersion::whereIn('id', $flows->pluck('version_id')->filter()->all() ?: [0])->pluck('number', 'id');
        $waiting = FlowRun::where('state', FlowRun::WAITING)->groupBy('flow_id')->selectRaw('flow_id, count(*) as n')->pluck('n', 'flow_id');

        return response()->json(['flows' => $flows->map(function (Flow $flow) use ($numbers, $waiting) {
            return $this->describe($flow, $numbers[$flow->version_id] ?? null, (int) ($waiting[$flow->id] ?? 0));
        })->values()]);
    }

    public function store(Request $request)
    {
        $this->guardEngine();
        $name = trim((string) $request->input('name')) ?: __('workflowpacks::flows.new_name');
        $flow = Flow::create(['name' => mb_substr($name, 0, 191), 'mailbox_ids' => [], 'active' => false, 'once' => true,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail', 'params' => [], 'x' => 40, 'y' => 120]], 'links' => []],
            'created_by' => auth()->id()]);

        return response()->json(['ok' => true, 'flow' => $this->describe($flow, null, 0)]);
    }

    /** Draft saved (the editor saves as it goes); its problems and the titles of its blocks given back. */
    public function save(Request $request, $id)
    {
        $this->guardEngine();
        $flow = Flow::findOrFail((int) $id);
        $draft = $request->input('draft');
        if (!is_array($draft) || strlen(json_encode($draft)) > self::MAX_DRAFT) {
            return response()->json(['ok' => false, 'message' => __('workflowpacks::flows.too_big')], 422);
        }
        $mailboxes = array_values(array_intersect(array_map('intval', (array) $request->input('mailbox_ids', [])),
            \App\Mailbox::pluck('id')->map(function ($i) {
                return (int) $i;
            })->all()));
        $flow->update([
            'name'        => mb_substr(trim((string) $request->input('name', $flow->name)) ?: $flow->name, 0, 191),
            'mailbox_ids' => $mailboxes,
            'once'        => (bool) $request->input('once', true),
            'draft'       => self::clean($draft),
        ]);

        return response()->json(['ok' => true] + $this->check($flow->draft));
    }

    public function publish($id)
    {
        $this->guardEngine();
        $flow = Flow::findOrFail((int) $id);
        $result = (new Publisher($this->registry))->publish($flow, auth()->user());
        $version = $result['version'];

        return response()->json(['ok' => $result['ok'], 'problems' => $result['problems'],
            'version' => $version ? (int) $version->number : null], $result['ok'] ? 200 : 422);
    }

    public function toggle(Request $request, $id)
    {
        $this->guardEngine();
        $flow = Flow::findOrFail((int) $id);
        $on = (bool) $request->input('active');
        if ($on && !$flow->version_id) {
            return response()->json(['ok' => false, 'message' => __('workflowpacks::flows.publish_first')], 422);
        }
        $flow->update(['active' => $on]);

        return response()->json(['ok' => true, 'active' => $flow->active]);
    }

    /** Deleted with its versions; the tickets still in it are cancelled (they stay as they are). */
    public function destroy($id)
    {
        $this->guardEngine();
        self::remove(Flow::findOrFail((int) $id));

        return response()->json(['ok' => true]);
    }

    /**
     * The flows ticked in the list of the editor ("All" included), handled together: published, switched on (only the
     * published ones) or off. The ones refused are named (problems to fix, never published).
     */
    public function bulk(Request $request)
    {
        $this->guardEngine();
        $action = (string) $request->input('action');
        if (!in_array($action, ['publish', 'activate', 'deactivate'], true)) {
            return response()->json(['ok' => false], 422);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        $done = 0;
        $refused = [];
        foreach (($ids ? Flow::whereIn('id', $ids)->orderBy('name')->get() : []) as $flow) {
            if ($action === 'publish') {
                $result = (new Publisher($this->registry))->publish($flow, auth()->user());
                $result['ok'] ? $done++ : $refused[] = $flow->name;
            } elseif ($action === 'activate' && !$flow->version_id) {
                $refused[] = $flow->name;
            } else {
                $flow->update(['active' => $action === 'activate']);
                $done++;
            }
        }

        return response()->json(['ok' => true, 'done' => $done, 'refused' => $refused]);
    }

    /** The flows ticked in the list of the editor ("All" included), deleted together. */
    public function destroyMany(Request $request)
    {
        $this->guardEngine();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        $flows = $ids ? Flow::whereIn('id', $ids)->get() : collect();
        foreach ($flows as $flow) {
            self::remove($flow);
        }

        return response()->json(['ok' => true, 'deleted' => $flows->count()]);
    }

    /** A flow deleted: the tickets waiting in it leave it (they stay as they are), its versions go with it. */
    protected static function remove(Flow $flow)
    {
        \DB::transaction(function () use ($flow) {
            FlowRun::where('flow_id', $flow->id)->whereIn('state', [FlowRun::WAITING, FlowRun::RUNNING])->update(['state' => FlowRun::CANCELLED]);
            FlowVersion::where('flow_id', $flow->id)->delete();
            $flow->delete();
        });
    }

    /** The draft in the editor (saved or not), with a sample e-mail: every step, nothing written (Engine::simulate). */
    public function simulate(Request $request, $id)
    {
        $this->guardEngine();
        Flow::findOrFail((int) $id);
        $draft = self::clean((array) $request->input('draft'));
        $def = Definition::from($draft);
        $problems = $def->validate($this->registry);
        if ($problems) {
            return response()->json(['ok' => false, 'problems' => $problems], 422);
        }
        $mail = (array) $request->input('mail');
        $status = in_array($mail['status'] ?? '', ['active', 'pending', 'closed', 'spam'], true) ? $mail['status'] : 'active';
        $trace = (new Engine($this->registry))->simulate($def, [
            'subject' => mb_substr((string) ($mail['subject'] ?? ''), 0, 255),
            'from'    => mb_substr((string) ($mail['from'] ?? ''), 0, 191),
            'body'    => mb_substr((string) ($mail['body'] ?? ''), 0, 20000),
            'status'  => $status,
            'user_id' => 0,
            'replied' => !empty($mail['replied']),
            'folder'  => '',
        ]);

        return response()->json(['ok' => true] + $trace);
    }

    /**
     * Path of a ticket (by its number) in the flow: its last run, the blocks it went through with what each one decided
     * and changed, and where it is now. Shown on the flow, with the version that ran.
     */
    public function path(Request $request, $id)
    {
        $this->guardEngine();
        $flow = Flow::findOrFail((int) $id);
        // the ticket with this number that went through the flow; a ticket without a number shows its id instead
        $number = (int) $request->input('ticket');
        $runs = FlowRun::where('flow_id', $flow->id)->orderBy('id', 'desc');
        $ids = \App\Conversation::where('number', $number)->pluck('id')->all();
        $run = ($ids ? (clone $runs)->whereIn('conversation_id', $ids)->first() : null) ?: (clone $runs)->where('conversation_id', $number)->first();
        if (!$run) {
            return response()->json(['ok' => false]);
        }
        $version = FlowVersion::find($run->version_id);
        $events = \Modules\WorkflowPacks\Entities\FlowEvent::where('run_id', $run->id)->orderBy('id')->get()->map(function ($e) {
            return ['block' => $e->block, 'output' => $e->output, 'summary' => $e->summary, 'changes' => $e->changes ?: [],
                'level' => (int) $e->level, 'time' => (string) $e->created_at];
        })->values();

        return response()->json(['ok' => true, 'state' => $run->state, 'block' => $run->block,
            'wake_at' => $run->wake_at ? (string) $run->wake_at : null, 'events' => $events,
            'version' => $version ? (int) $version->number : null] + $this->check($version ? (array) $version->data : []));
    }

    /** Problems of a draft and the titles of its blocks (composed in the language of the user, never stored). */
    protected function check(array $draft)
    {
        $titles = [];
        foreach ((array) ($draft['blocks'] ?? []) as $block) {
            $class = $this->registry->get($block['type'] ?? '');
            $titles[$block['id'] ?? ''] = $class ? $class::title((array) ($block['params'] ?? [])) : (string) ($block['type'] ?? '?');
        }

        return ['problems' => Definition::from($draft)->validate($this->registry), 'titles' => $titles];
    }

    protected function describe(Flow $flow, $number, $waiting)
    {
        return ['id' => (int) $flow->id, 'name' => (string) $flow->name, 'active' => (bool) $flow->active, 'once' => (bool) $flow->once,
            'mailbox_ids' => array_map('intval', (array) $flow->mailbox_ids), 'version' => $number, 'waiting' => $waiting,
            'draft' => $flow->draft ?: ['blocks' => [], 'links' => []]] + $this->check($flow->draft ?: []);
    }

    /** Only the known keys of a draft (positions kept for the editor). */
    protected static function clean(array $draft)
    {
        $blocks = [];
        foreach (array_slice(array_values((array) ($draft['blocks'] ?? [])), 0, Definition::MAX_BLOCKS) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $blocks[] = ['id' => mb_substr((string) ($b['id'] ?? ''), 0, 40), 'type' => mb_substr((string) ($b['type'] ?? ''), 0, 60),
                'params' => (array) ($b['params'] ?? []), 'x' => (int) ($b['x'] ?? 0), 'y' => (int) ($b['y'] ?? 0)];
        }
        $links = [];
        foreach (array_slice(array_values((array) ($draft['links'] ?? [])), 0, Definition::MAX_BLOCKS * 3) as $l) {
            if (is_array($l)) {
                $links[] = ['from' => (string) ($l['from'] ?? ''), 'port' => (string) ($l['port'] ?? 'next'), 'to' => (string) ($l['to'] ?? '')];
            }
        }

        return ['blocks' => $blocks, 'links' => $links];
    }

    protected function catalog()
    {
        return array_map(function ($entry) {
            $class = $this->registry->get($entry['type']);

            return $entry + ['variables' => $class::variables([])];
        }, $this->registry->catalog());
    }

    /** Labels of the variables, flat ("ticket.subject" => "Subject"). */
    protected function varLabels()
    {
        $out = [];
        foreach ((array) trans('workflowpacks::blocks.vars') as $group => $vars) {
            foreach ((array) $vars as $key => $label) {
                $out[$group.'.'.$key] = $label;
            }
        }

        return $out;
    }

    /** Administrators, and only when the engine is on (Manage › Settings › Workflow Packs). */
    protected function guardEngine()
    {
        $this->guard();
        if (!auth()->user()->isAdmin() || !Engine::enabled()) {
            \Helper::denyAccess();
        }
    }
}
