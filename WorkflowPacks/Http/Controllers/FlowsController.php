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
                // a simulation shared by an agent: its e-mail, played on the diagram when the page opens
                'replay'    => self::replay($request),
                'urls'      => [
                    'data'     => route('workflowpacks.flows.data'),
                    'store'    => route('workflowpacks.flows.store'),
                    'save'     => route('workflowpacks.flows.save', ['id' => '__ID__']),
                    'publish'  => route('workflowpacks.flows.publish', ['id' => '__ID__']),
                    'toggle'   => route('workflowpacks.flows.toggle', ['id' => '__ID__']),
                    'destroy'  => route('workflowpacks.flows.destroy', ['id' => '__ID__']),
                    'destroy_many' => route('workflowpacks.flows.destroy_many'),
                    'bulk'     => route('workflowpacks.flows.bulk'),
                    'imap_folders' => route('workflowpacks.flows.imap_folders'),
                    'tickets'  => route('workflowpacks.flows.tickets'),
                    'similar_test' => route('workflowpacks.flows.similar_test'),
                    'simulate' => route('workflowpacks.flows.simulate', ['id' => '__ID__']),
                    'sample'   => route('workflowpacks.flows.sample', ['id' => '__ID__']),
                    'path'     => route('workflowpacks.flows.path', ['id' => '__ID__']),
                    'reapply'  => route('workflowpacks.reapply', ['id' => '__ID__']),
                ],
                'catalog'   => $this->catalog(),
                'mailboxes' => \App\Mailbox::orderBy('name')->get(['id', 'name', 'email', 'in_server', 'in_username'])->map(function ($m) {
                    return ['id' => (int) $m->id, 'name' => (string) $m->name, 'email' => (string) $m->email, 'imap' => $m->in_server && $m->in_username];
                })->values(),
                // "file into a folder": the folders of the module carry this prefix (Manage › Settings › Workflow Packs)
                'imap'      => ['enabled' => \Modules\WorkflowPacks\Services\Imap\ImapFolders::enabled(), 'prefix' => \Modules\WorkflowPacks\Services\Imap\ImapFolders::prefix(),
                    'settings' => route('settings', ['section' => 'workflowpacks'])],
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

    const TICKETS_FOUND = 30;
    const TEST_TICKETS = 100;
    const TEST_SHOWN = 50;

    /**
     * Tickets to pick as examples of "Looks like": searched by subject, sender or number in the mailboxes of the flow
     * (all when it has none), newest first; or the ones already chosen (ids). Nothing published, nothing changed.
     */
    public function tickets(Request $request)
    {
        $this->guardEngine();
        $q = \App\Conversation::where('state', \App\Conversation::STATE_PUBLISHED)->with('mailbox');
        $ids = \Modules\WorkflowPacks\Blocks\Conditions\Similar::ids($request->input('ids', []));
        if ($ids) {
            $q->whereIn('id', array_slice($ids, 0, \Modules\WorkflowPacks\Blocks\Conditions\Similar::MAX_EXAMPLES));
        } else {
            $mailboxes = array_filter(array_map('intval', (array) $request->input('mailbox_ids', [])));
            if ($mailboxes) {
                $q->whereIn('mailbox_id', $mailboxes);
            }
            $text = trim(mb_substr((string) $request->input('q', ''), 0, 100));
            if ($text !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';
                $q->where(function ($w) use ($text, $like) {
                    $w->where('subject', 'like', $like)->orWhere('customer_email', 'like', $like);
                    if (ctype_digit($text)) {
                        $w->orWhere('number', (int) $text);
                    }
                });
            }
            $q->orderBy('id', 'desc')->limit(self::TICKETS_FOUND);
        }

        return response()->json(['tickets' => $q->get()->map(function ($c) {
            return self::ticketRow($c);
        })->values()]);
    }

    /**
     * "Test": the examples (their profile) against the last tickets of the mailboxes of the flow, with the threshold and
     * the weights of the block: how many would answer "yes", the scores. The examples themselves are left out.
     */
    public function similarTest(Request $request)
    {
        $this->guardEngine();
        $similar = \Modules\WorkflowPacks\Blocks\Conditions\Similar::class;
        $ids = array_slice($similar::ids($request->input('examples', [])), 0, $similar::MAX_EXAMPLES);
        if (!$ids) {
            return response()->json(['ok' => false, 'message' => __('workflowpacks::blocks.errors.examples', ['max' => $similar::MAX_EXAMPLES])], 422);
        }
        $threshold = max(1, min(100, (int) $request->input('threshold', 75)));
        $ws = max(0, min(100, (float) $request->input('subject', 50)));
        $wb = max(0, min(100, (float) $request->input('body', 50)));
        $profile = $similar::profile($ids);
        $q = \App\Conversation::where('state', \App\Conversation::STATE_PUBLISHED)->whereNotIn('id', $ids)->with('mailbox');
        $mailboxes = array_filter(array_map('intval', (array) $request->input('mailbox_ids', [])));
        if ($mailboxes) {
            $q->whereIn('mailbox_id', $mailboxes);
        }
        $rows = [];
        foreach ($q->orderBy('id', 'desc')->limit(self::TEST_TICKETS)->get() as $c) {
            $text = \Modules\WorkflowPacks\Services\Ai\TicketText::of($c, false);
            $s = \Modules\WorkflowPacks\Services\Ai\Vectorizer::similarity(\Modules\WorkflowPacks\Services\Ai\Vectorizer::vector($text['subject']), $profile['subject']);
            $b = \Modules\WorkflowPacks\Services\Ai\Vectorizer::similarity(\Modules\WorkflowPacks\Services\Ai\Vectorizer::vector($text['body']), $profile['body']);
            $rows[] = self::ticketRow($c) + ['score' => (int) round(100 * ($ws * $s + $wb * $b) / max(1, $ws + $wb))];
        }
        usort($rows, function ($x, $y) {
            return $y['score'] - $x['score'];
        });
        $yes = count(array_filter($rows, function ($r) use ($threshold) {
            return $r['score'] >= $threshold;
        }));

        return response()->json(['ok' => true, 'tested' => count($rows), 'yes' => $yes, 'threshold' => $threshold,
            'tickets' => array_slice($rows, 0, self::TEST_SHOWN)]);
    }

    protected static function ticketRow($c)
    {
        return ['id' => (int) $c->id, 'number' => (int) $c->number, 'subject' => mb_substr((string) $c->subject, 0, 120),
            'from' => (string) $c->customer_email, 'mailbox' => (string) optional($c->mailbox)->name,
            'date' => $c->created_at ? $c->created_at->format('Y-m-d') : ''];
    }

    /** The simulation of an agent to replay (its address holds a random key, kept a day), only for the flow it was made on. */
    protected static function replay(Request $request)
    {
        $key = (string) $request->input('replay');
        if (!preg_match('/^[0-9a-f]{24}$/', $key)) {
            return null;
        }
        $data = \Cache::get('workflowpacks.replay.'.$key);

        return $data && (int) $data['flow'] === (int) $request->input('flow') ? $data : null;
    }

    /** The folders of the module already on the mail server of a mailbox, for the "file into a folder" block. */
    public function imapFolders(Request $request)
    {
        $this->guardEngine();
        $mailbox = \App\Mailbox::find((int) $request->input('mailbox_id'));
        if (!$mailbox) {
            return response()->json(['ok' => false, 'folders' => [], 'error' => ''], 404);
        }
        if (!$mailbox->in_server || !$mailbox->in_username) {
            return response()->json(['ok' => false, 'folders' => [], 'error' => __('workflowpacks::flows.folders_no_imap')]);
        }

        return response()->json(\Modules\WorkflowPacks\Services\Imap\ImapFolders::managedFolders($mailbox));
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
        $trace = (new Engine($this->registry))->simulate($def, (new \Modules\WorkflowPacks\Services\Engine\SampleMail($this->registry))->ticketOf([
            'subject' => (string) ($mail['subject'] ?? ''), 'from' => (string) ($mail['from'] ?? ''), 'body' => (string) ($mail['body'] ?? ''),
            'status' => $status, 'assigned' => !empty($mail['assigned']), 'replied' => !empty($mail['replied'])]));

        return response()->json(['ok' => true] + $trace);
    }

    /**
     * "An e-mail that goes through": a sample e-mail for the simulation, made to take the positive path of the draft
     * (Services\Engine\SampleMail), with whether it reached the end and what could not be made true. Nothing written.
     */
    public function sampleMail(Request $request, $id)
    {
        $this->guardEngine();
        Flow::findOrFail((int) $id);
        $draft = self::clean((array) $request->input('draft'));
        $problems = Definition::from($draft)->validate($this->registry);
        if ($problems) {
            return response()->json(['ok' => false, 'problems' => $problems], 422);
        }

        return response()->json(['ok' => true] + (new \Modules\WorkflowPacks\Services\Engine\SampleMail($this->registry))->build($draft));
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
