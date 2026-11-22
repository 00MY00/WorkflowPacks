<?php

namespace Modules\WorkflowPacks\Http\Controllers;

use Illuminate\Http\Request;
use Modules\WorkflowPacks\Entities\Activity;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;
use Modules\WorkflowPacks\Services\Imap\ImapInspector;

/**
 * Workflow Packs › History: what the module did (flows, connected agents, e-mails filed, folders cleaned), newest first,
 * with filters, and a diagnostic of the e-mail filing (option, FreeScout's queue, mail server). Administrators. The
 * entries written before 4.0 (packs of Workflows rules) stay readable.
 */
class HistoryController extends Controller
{
    const PAGE = 100;
    /** kinds offered in the filter; older entries (import, remove, group, simulate, rules) are still shown */
    const KINDS = ['flow', 'agent', 'imap_file', 'imap_clean', 'separation'];

    public function index(Request $request)
    {
        $this->guard();
        $query = Activity::orderBy('id', 'desc');
        $filters = [
            'kind'       => in_array($request->input('kind'), self::KINDS, true) ? $request->input('kind') : '',
            'status'     => preg_match('/^[a-z_]{1,20}$/', (string) $request->input('status')) ? (string) $request->input('status') : '',
            'mailbox_id' => (int) $request->input('mailbox_id'),
        ];
        if ($filters['kind'] !== '') {
            $query->where('kind', $filters['kind']);
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['mailbox_id']) {
            $query->where('mailbox_id', $filters['mailbox_id']);
        }
        if ((int) $request->input('before')) {
            $query->where('id', '<', (int) $request->input('before'));
        }
        $entries = $query->limit(self::PAGE + 1)->get();
        $more = $entries->count() > self::PAGE;
        $entries = $entries->take(self::PAGE);

        $names = \App\Mailbox::whereIn('id', $entries->pluck('mailbox_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $users = \App\User::whereIn('id', $entries->pluck('user_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');

        return $this->page('history', [
            'entries'   => $entries,
            'more'      => $more,
            'filters'   => $filters,
            'mailboxes' => \App\Mailbox::orderBy('name')->get(),
            'names'     => $names,
            'users'     => $users,
            'diagnostic' => $this->diagnostic(),
            'inspection' => null,
            'trace'      => null,
        ]);
    }

    /** Administrators: the folders of a mailbox as its mail server lists them (read only). */
    public function inspect(Request $request)
    {
        $this->guard();
        // its own parameter: "mailbox_id" filters the history
        $mailbox = \App\Mailbox::find((int) $request->input('imap_mailbox'));
        abort_if(!$mailbox, 404);
        $result = (new ImapInspector())->inspect($mailbox, ImapFolders::prefix());

        return $this->index($request)->with('inspection', ['mailbox' => $mailbox, 'result' => $result]);
    }

    /** Administrators: how the e-mail of a ticket is looked for on the mail server, step by step (read only). */
    public function trace(Request $request)
    {
        $this->guard();
        // "conversation" (id, links of the history) or "number" (shown in FreeScout: the id, or its own number when
        // custom numbering is on)
        $conversation = $request->input('conversation')
            ? \App\Conversation::find((int) $request->input('conversation'))
            : \App\Conversation::where(self::numberField(), (int) $request->input('number'))->first();
        if (!$conversation || !$conversation->mailbox) {
            return redirect()->route('workflowpacks.history')->with('flash_error_floating', __('workflowpacks::messages.history_trace_unknown'));
        }
        $result = (new ImapInspector())->trace($conversation->mailbox, $conversation);

        return $this->index($request)->with('trace', ['conversation' => $conversation, 'number' => $conversation->{self::numberField()},
            'mailbox' => $conversation->mailbox, 'result' => $result]);
    }

    protected static function numberField()
    {
        return method_exists(\App\Conversation::class, 'numberFieldName') ? \App\Conversation::numberFieldName() : 'number';
    }

    /** Option, FreeScout's queue (the moves are done there), last filings. */
    protected function diagnostic()
    {
        $out = [
            'enabled' => ImapFolders::enabled(),
            'prefix'  => ImapFolders::prefix(),
            'parent'  => ImapFolders::parentMode(),
            'pending' => null, 'oldest' => null, 'failed' => null, 'failed_error' => '',
            'last_call' => Activity::where('kind', 'imap_file')->orderBy('id', 'desc')->first(),
            'last_moved' => Activity::where('kind', 'imap_file')->where('status', 'moved')->orderBy('id', 'desc')->first(),
            'imap_mailboxes' => \App\Mailbox::whereNotNull('in_server')->where('in_server', '!=', '')->orderBy('name')->get(['id', 'name']),
        ];
        try {
            if (\Schema::hasTable('jobs')) {
                $jobs = \DB::table('jobs')->where('payload', 'like', '%FileIntoImapFolder%');
                $out['pending'] = (clone $jobs)->count();
                $oldest = (clone $jobs)->min('created_at');
                $out['oldest'] = $oldest ? (int) round((time() - (int) $oldest) / 60) : null;
            }
            if (\Schema::hasTable('failed_jobs')) {
                $failed = \DB::table('failed_jobs')->where('payload', 'like', '%FileIntoImapFolder%');
                $out['failed'] = (clone $failed)->count();
                $last = (clone $failed)->orderBy('id', 'desc')->value('exception');
                $out['failed_error'] = $last ? strtok((string) $last, "\n") : '';
            }
        } catch (\Throwable $e) {
            // queue not stored in the database: nothing to show
        }

        return $out;
    }
}
