@extends('workflowpacks::layout')

{{-- Workflow Packs › History: what the module did, newest first (HistoryController). --}}
@section('wp_content')
    @php
        $wp_t = function ($key, array $params = []) { return __('workflowpacks::messages.'.$key, $params); };
        $wp_label = function ($status) use ($wp_t) {
            $key = 'history_status_'.$status;
            $text = __('workflowpacks::messages.'.$key);

            return $text === 'workflowpacks::messages.'.$key ? $status : $text;
        };
        $wp_class = ['moved' => 'success', 'ok' => 'success', 'enabled' => 'success', 'created' => 'success', 'already' => 'info',
            'queued' => 'warning', 'skipped' => 'default', 'not_found' => 'default', 'failed' => 'danger', 'disabled' => 'default',
            'modified' => 'info', 'previous_on' => 'info', 'previous_off' => 'default', 'refile' => 'info'];
        // details stored as JSON (counts) shown as text
        $wp_details = function ($message) use ($wp_t) {
            $data = json_decode((string) $message, true);
            if (!is_array($data)) {
                return (string) $message;
            }
            $parts = [];
            foreach ((array) ($data['counts'] ?? $data) as $k => $v) {
                if (is_scalar($v) && $v !== '' && $v !== 0 && $k !== 'error') {
                    $parts[] = $v.' × '.$wp_t('history_count_'.$k);
                }
            }
            if (!empty($data['error'])) {
                $parts[] = $data['error'];
            }

            return implode(' · ', $parts);
        };
    @endphp

    <p class="wp-muted">{{ $wp_t('history_intro') }}</p>

    @if ($diagnostic)
        <div class="panel panel-default">
            <div class="panel-heading"><strong>{{ $wp_t('history_diag_title') }}</strong></div>
            <div class="panel-body">
                <ul class="wp-diag">
                    <li>
                        <span class="label label-{{ $diagnostic['enabled'] ? 'success' : 'default' }}">{{ $diagnostic['enabled'] ? 'ON' : 'OFF' }}</span>
                        {{ $wp_t('imap_label') }} — {{ $wp_t('imap_prefix_label') }} <code>{{ $diagnostic['prefix'] }}</code>,
                        {{ $wp_t('imap_parent_label') }} : {{ $wp_t('imap_parent_'.$diagnostic['parent']) }}
                    </li>
                    <li>
                        @if ($diagnostic['last_call'])
                            {{ $wp_t('history_diag_last_call', ['time' => $diagnostic['last_call']->created_at->format('Y-m-d H:i')]) }}
                            @if ($diagnostic['last_moved']) — {{ $wp_t('history_diag_last_moved', ['time' => $diagnostic['last_moved']->updated_at->format('Y-m-d H:i')]) }}@endif
                        @else
                            {{ $wp_t('history_diag_no_call') }}
                        @endif
                    </li>
                    @if ($diagnostic['pending'] !== null)
                        <li class="{{ $diagnostic['pending'] && $diagnostic['oldest'] > 10 ? 'text-danger' : '' }}">
                            {{ $wp_t('history_diag_queue', ['count' => $diagnostic['pending']]) }}
                            @if ($diagnostic['pending'] && $diagnostic['oldest'] !== null) — {{ $wp_t('history_diag_oldest', ['minutes' => $diagnostic['oldest']]) }}@endif
                            @if ($diagnostic['pending'] && $diagnostic['oldest'] > 10)<br><small>{{ $wp_t('history_diag_queue_stuck') }}</small>@endif
                        </li>
                    @endif
                    @if ($diagnostic['failed'])
                        <li class="text-danger">{{ $wp_t('history_diag_failed', ['count' => $diagnostic['failed']]) }} <small>{{ $diagnostic['failed_error'] }}</small></li>
                    @endif
                </ul>
                @if ($diagnostic['imap_mailboxes']->count())
                    <form method="GET" action="{{ route('workflowpacks.history.inspect') }}" class="form-inline wp-inline">
                        <label>{{ $wp_t('history_inspect') }}</label>
                        <select name="imap_mailbox" class="form-control input-sm">
                            @foreach ($diagnostic['imap_mailboxes'] as $mb)
                                <option value="{{ $mb->id }}" @if (!empty($inspection) && $inspection['mailbox']->id === $mb->id) selected @endif>{{ $mb->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-default btn-sm">{{ $wp_t('history_inspect_btn') }}</button>
                    </form>
                @endif
                @if (!empty($inspection))
                    @php $wp_r = $inspection['result']; @endphp
                    <div class="wp-inspect margin-top">
                        <strong>{{ $inspection['mailbox']->name }}</strong>
                        @if (!$wp_r['ok'])
                            <div class="alert alert-danger">{{ $wp_t('history_inspect_error') }} {{ $wp_r['error'] }}</div>
                        @else
                            <p class="wp-muted">
                                {{ $wp_t('history_inspect_delimiter') }} <code>{{ $wp_r['delimiter'] }}</code> ·
                                {{ $wp_t('history_inspect_namespace') }} <code>{{ $wp_r['namespace'] ? ($wp_r['namespace']['prefix'] === '' ? '""' : $wp_r['namespace']['prefix']) : '—' }}</code> ·
                                {{ $wp_t('history_inspect_parent') }} <code>{{ $wp_r['parent'] === '' ? $wp_t('history_inspect_root') : $wp_r['parent'] }}</code> ·
                                {{ $wp_t('history_inspect_trash') }} <code>{{ $wp_r['trash'] ? implode(', ', $wp_r['trash']) : '—' }}</code>
                            </p>
                            <ul class="wp-folders">
                                @foreach ($wp_r['folders'] as $f)
                                    <li class="@if ($f['managed']) wp-folder-managed @endif">
                                        <i class="glyphicon glyphicon-{{ $f['trash'] ? 'trash' : 'folder-close' }}" aria-hidden="true"></i>
                                        {{ $f['path'] }}
                                        @if ($f['managed'])<span class="label label-primary">{{ $wp_t('history_inspect_managed') }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
                @if ($diagnostic['imap_mailboxes']->count())
                    <form method="GET" action="{{ route('workflowpacks.history.trace') }}" class="form-inline wp-inline">
                        <label for="wp-trace-number">{{ $wp_t('history_trace') }}</label>
                        <input type="number" min="1" id="wp-trace-number" name="number" class="form-control input-sm" placeholder="{{ $wp_t('history_trace_number') }}"
                            value="{{ !empty($trace) ? $trace['number'] : '' }}">
                        <button type="submit" class="btn btn-default btn-sm">{{ $wp_t('history_trace_btn') }}</button>
                    </form>
                @endif
                @if (!empty($trace))
                    @php $wp_x = $trace['result']; @endphp
                    <div class="wp-inspect margin-top">
                        <strong>#{{ $trace['number'] }} {{ $trace['conversation']->subject }}</strong>
                        <span class="wp-muted">— {{ $trace['mailbox']->name }}</span>
                        @if (!empty($wp_x['message_id']))
                            <p class="wp-muted">{{ $wp_t('history_trace_id') }} <code>{{ $wp_x['message_id'] }}</code> · {{ $wp_t('history_trace_date') }} {{ $wp_x['date'] }}</p>
                        @endif
                        @if (!$wp_x['ok'])
                            <div class="alert alert-danger">{{ $wp_t('history_inspect_error') }} {{ $wp_x['error'] }}</div>
                        @elseif ($wp_x['found_in'] !== null)
                            <div class="alert alert-success">{{ $wp_t('history_trace_found', ['folder' => $wp_x['found_in']]) }}</div>
                        @else
                            <div class="alert alert-warning">{{ $wp_t('history_trace_not_found') }}</div>
                        @endif
                        @if (!empty($wp_x['trace']))
                            <table class="table table-condensed">
                                @foreach ($wp_x['trace'] as $s)
                                    <tr>
                                        <td>{{ $s['folder'] }}</td>
                                        <td>{{ $wp_t('history_trace_step_'.$s['step']) }}</td>
                                        <td><span class="label label-{{ ['found' => 'success', 'none' => 'default', 'error' => 'danger'][$s['result']] ?? 'default' }}">{{ $wp_t('history_trace_result_'.$s['result']) }}</span></td>
                                        <td class="wp-muted"><small>{{ $s['detail'] }}</small></td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                        @if (!empty($wp_x['recent']))
                            <div class="wp-sub">{{ $wp_t('history_trace_recent', ['folder' => $wp_x['recent_folder']]) }}</div>
                            <table class="table table-condensed">
                                @foreach ($wp_x['recent'] as $m)
                                    <tr>
                                        <td class="text-nowrap"><small>{{ $m['date'] }}</small></td>
                                        <td>{{ $m['subject'] }}</td>
                                        <td><code class="@if ($m['message_id'] !== '' && strcasecmp($m['message_id'], $wp_x['message_id']) === 0) text-success @endif">{{ $m['message_id'] }}</code></td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif

    <form method="GET" action="{{ route('workflowpacks.history') }}" class="form-inline wp-toolbar">
        <select name="kind" class="form-control input-sm wp-autosubmit">
            <option value="">{{ $wp_t('history_all_kinds') }}</option>
            @foreach (['imap_file', 'imap_clean', 'import', 'remove', 'group', 'rules'] as $k)
                <option value="{{ $k }}" @if ($filters['kind'] === $k) selected @endif>{{ $wp_t('history_kind_'.$k) }}</option>
            @endforeach
        </select>
        <select name="status" class="form-control input-sm wp-autosubmit">
            <option value="">{{ $wp_t('history_all_states') }}</option>
            @foreach (['queued', 'moved', 'already', 'not_found', 'skipped', 'failed', 'ok', 'enabled', 'disabled', 'previous_on', 'previous_off', 'refile', 'created', 'modified'] as $s)
                <option value="{{ $s }}" @if ($filters['status'] === $s) selected @endif>{{ $wp_label($s) }}</option>
            @endforeach
        </select>
        @if ($mailboxes->count() > 1)
            <select name="mailbox_id" class="form-control input-sm wp-autosubmit">
                <option value="">{{ $wp_t('all_mailboxes') }}</option>
                @foreach ($mailboxes as $mb)
                    <option value="{{ $mb->id }}" @if ($filters['mailbox_id'] === (int) $mb->id) selected @endif>{{ $mb->name }}</option>
                @endforeach
            </select>
        @endif
        <a href="{{ route('workflowpacks.history') }}" class="btn btn-link btn-sm">{{ $wp_t('history_refresh') }}</a>
    </form>

    <table class="table table-condensed wp-history">
        <tr>
            <th>{{ $wp_t('history_when') }}</th>
            <th>{{ $wp_t('history_what') }}</th>
            <th>{{ $wp_t('history_state') }}</th>
            <th>{{ $wp_t('history_mailbox') }}</th>
            <th>{{ $wp_t('history_subject') }}</th>
            <th>{{ $wp_t('history_details') }}</th>
        </tr>
        @forelse ($entries as $e)
            <tr>
                <td class="text-nowrap" title="{{ $e->updated_at }}">{{ $e->created_at->format('Y-m-d H:i:s') }}</td>
                <td>{{ $wp_t('history_kind_'.$e->kind) }}</td>
                <td><span class="label label-{{ $wp_class[$e->status] ?? 'default' }}">{{ $wp_label($e->status) }}</span></td>
                <td>{{ $e->mailbox_id ? ($names[$e->mailbox_id] ?? '#'.$e->mailbox_id) : '' }}</td>
                <td>
                    {{ $e->subject }}
                    @if ($e->conversation_id && \Route::has('conversations.view'))
                        <a href="{{ route('conversations.view', ['id' => $e->conversation_id]) }}" class="wp-muted">#{{ $e->conversation_id }}</a>
                    @endif
                    @if ($e->conversation_id && $diagnostic && in_array($e->status, ['not_found', 'failed'], true))
                        <a href="{{ route('workflowpacks.history.trace', ['conversation' => $e->conversation_id]) }}" class="btn btn-link btn-xs">{{ $wp_t('history_trace_btn') }}</a>
                    @endif
                </td>
                <td class="wp-muted">
                    {{ $wp_details($e->message) }}
                    @if ($e->user_id && isset($users[$e->user_id]))<br><small>{{ $users[$e->user_id]->getFullName() }}</small>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="wp-muted">{{ $wp_t('history_none') }}</td></tr>
        @endforelse
    </table>
    @if ($more)
        <a class="btn btn-default btn-sm" href="{{ route('workflowpacks.history', array_filter($filters) + ['before' => $entries->last()->id]) }}">{{ $wp_t('history_older') }}</a>
    @endif
@endsection
