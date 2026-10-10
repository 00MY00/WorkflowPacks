@extends('workflowpacks::layout')

@section('wp_content')
    @include('workflowpacks::partials.steps', ['step' => 3])
    @if ($warning)<div class="alert alert-warning">{{ $warning }}</div>@endif

    <div class="wp-counts">
        <strong>{{ __('workflowpacks::messages.summary') }}</strong>
        @foreach ($plan['counts'] as $status => $n)
            @if ($n)<span class="wp-status wp-status-{{ $status }}">{{ $n }} × {{ __('workflowpacks::messages.status_'.$status) }}</span>@endif
        @endforeach
    </div>

    <form method="POST" action="{{ route('workflowpacks.import.confirm') }}" class="wp-form">
        {{ csrf_field() }}
        <input type="hidden" name="pack_b64" value="{{ base64_encode($json) }}">
        <input type="hidden" name="token" value="{{ $plan['token'] }}">
        @foreach (array_keys($plan['mailboxes']) as $id)
            <input type="hidden" name="mailboxes[]" value="{{ $id }}">
        @endforeach
        @foreach ((array) ($input['params'] ?? []) as $k => $v)
            @if (is_scalar($v))<input type="hidden" name="params[{{ $k }}]" value="{{ $v }}">@endif
        @endforeach
        @foreach ((array) ($input['resources'] ?? []) as $k => $v)
            @if (is_scalar($v))<input type="hidden" name="resources[{{ $k }}]" value="{{ $v }}">@endif
        @endforeach

        @if (!empty($plan['resources']))
            <table class="table table-condensed wp-resources">
                <tr><th>{{ __('workflowpacks::messages.resources') }}</th><th></th><th></th><th>{{ __('workflowpacks::messages.risk') }}</th></tr>
                @foreach ($plan['resources'] as $key => $r)
                    <tr>
                        <td><code>{{ $key }}</code> {{ $r['decl']['name'] }}</td>
                        <td><span class="label label-default">{{ $r['decl']['type'] }}</span></td>
                        <td>
                            @if ($r['status'] === 'mapped'){{ __('workflowpacks::messages.res_use_existing', ['label' => '#'.$r['local_id'].' '.$r['label']]) }}
                            @else{{ __('workflowpacks::messages.res_blocked') }} @foreach ($r['notes'] as $note)<div class="wp-muted">{{ $note }}</div>@endforeach
                            @endif
                        </td>
                        <td><span class="wp-risk wp-risk-{{ $r['status'] === 'mapped' ? 'safe' : 'warning' }}">{{ __('workflowpacks::messages.risk_'.($r['status'] === 'mapped' ? 'safe' : 'warning')) }}</span></td>
                    </tr>
                @endforeach
            </table>
        @endif

        {{-- schematic matrix: rules x mailboxes, with the choice for every cell --}}
        <div class="table-responsive wp-matrix-wrap">
            <table class="table table-bordered wp-matrix">
                <thead>
                    <tr>
                        <th></th>
                        @foreach ($plan['mailboxes'] as $mailbox)
                            <th>{{ $mailbox['name'] }}<br><small class="wp-muted">{{ $mailbox['email'] }}</small></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plan['rules'] as $ruleKey => $entry)
                        <tr>
                            <th><a href="#wp-rule-{{ $loop->index }}">{!! \Modules\WorkflowPacks\Services\Ui::value($entry['rule']['name']) !!}</a></th>
                            @foreach ($entry['items'] as $mailboxId => $item)
                                <td class="wp-cell wp-status-{{ $item['status'] }}" data-label="{{ $plan['mailboxes'][$mailboxId]['name'] }}">
                                    <div class="wp-cell-status">{{ __('workflowpacks::messages.status_'.$item['status']) }}
                                        @if (!in_array($item['status'], ['not_selected', 'denied', 'unchanged'], true))<span class="wp-risk wp-risk-{{ $item['risk'] }}">{{ __('workflowpacks::messages.risk_'.$item['risk']) }}</span>@endif
                                        @foreach ($item['sensitive'] as $kind)<span class="label label-danger" title="{{ __('workflowpacks::messages.sensitive_'.$kind) }}">⚠</span>@endforeach
                                    </div>
                                    <select name="decision[{{ $ruleKey }}][{{ $mailboxId }}]" class="form-control input-sm">
                                        @foreach ($item['choices'] as $choice)
                                            <option value="{{ $choice }}" @if ($choice === $item['default']) selected @endif>{{ __('workflowpacks::messages.choice_'.$choice) }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- details per rule: template, then per mailbox the resolved rule, the reasons and the differences --}}
        @foreach ($plan['rules'] as $ruleKey => $entry)
            <div class="panel panel-default" id="wp-rule-{{ $loop->index }}">
                <div class="panel-body">
                    @include('workflowpacks::partials.rule_schema', ['rule' => $entry['rule'], 'mailboxId' => null])
                    @foreach ($entry['items'] as $mailboxId => $item)
                        <details class="wp-item wp-status-{{ $item['status'] }}" @if (in_array($item['status'], ['conflict', 'blocked', 'update', 'conflict_name'], true)) open @endif>
                            <summary><strong>{{ $plan['mailboxes'][$mailboxId]['name'] }}</strong> — {{ __('workflowpacks::messages.status_'.$item['status']) }}</summary>
                            @if ($item['reasons'])
                                <ul class="wp-reasons">@foreach ($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                            @endif
                            @if ($item['attributes'])
                                <div class="wp-sub">{{ __('workflowpacks::messages.resolved') }}</div>
                                @include('workflowpacks::partials.rule_schema', ['rule' => \Modules\WorkflowPacks\Services\Ui::ruleFromAttributes($item['attributes'], $entry['rule']), 'mailboxId' => $mailboxId])
                            @endif
                            @if ($item['diff'])
                                <div class="wp-sub">{{ __('workflowpacks::messages.show_diff') }}</div>
                                <table class="table table-condensed wp-diff">
                                    <tr><th></th><th>{{ __('workflowpacks::messages.before') }}</th><th>{{ __('workflowpacks::messages.after') }}</th></tr>
                                    @foreach ($item['diff'] as $field => $d)
                                        <tr><th>{{ $field }}</th><td data-label="{{ __('workflowpacks::messages.before') }}"><pre>{{ $d['before'] }}</pre></td><td data-label="{{ __('workflowpacks::messages.after') }}"><pre>{{ $d['after'] }}</pre></td></tr>
                                    @endforeach
                                </table>
                            @endif
                        </details>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="panel panel-default">
            <div class="panel-heading"><strong>{{ __('workflowpacks::messages.options') }}</strong></div>
            <div class="panel-body">
                <div class="checkbox"><label><input type="checkbox" name="activate_new" value="1"> {{ __('workflowpacks::messages.opt_activate_new') }}</label></div>
                <div class="checkbox"><label><input type="checkbox" name="allow_apply_to_prev" value="1"> {{ __('workflowpacks::messages.opt_apply_prev') }}</label></div>
                <div class="form-group form-inline">
                    <label>{{ __('workflowpacks::messages.opt_active_mode') }}</label>
                    <select name="active_mode" class="form-control input-sm">
                        <option value="keep" selected>{{ __('workflowpacks::messages.opt_keep') }}</option>
                        <option value="activate">{{ __('workflowpacks::messages.opt_activate') }}</option>
                        <option value="deactivate">{{ __('workflowpacks::messages.opt_deactivate') }}</option>
                    </select>
                </div>
                @if ($plan['counts']['blocked'] + $plan['counts']['denied'])
                    <div class="checkbox wp-ack"><label><input type="checkbox" name="ack_blocked" value="1" required> {{ __('workflowpacks::messages.opt_ack_blocked') }}</label></div>
                @endif
                <p class="wp-muted">{{ __('workflowpacks::messages.atomic') }} {{ __('workflowpacks::messages.no_processing') }}</p>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.confirm') }}</button>
        <a href="{{ route('workflowpacks.import') }}" class="btn btn-link">{{ __('workflowpacks::messages.back') }}</a>
    </form>
@endsection
