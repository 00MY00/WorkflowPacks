@extends('workflowpacks::layout')

@section('wp_content')
    <h3>{{ __('workflowpacks::messages.export_title') }} — {{ $pack['name'] }} <code>{{ $pack['key'] }}</code> <span class="wp-muted">{{ $pack['pack_version'] ?? '' }}</span></h3>
    @include('workflowpacks::partials.problems', ['problems' => $problems, 'title' => __('workflowpacks::messages.export_invalid')])

    @foreach (['secrets' => 'report_secrets', 'refs' => 'report_refs', 'modules' => 'report_modules', 'from_packs' => 'report_packs', 'ignored' => 'report_ignored'] as $key => $label)
        @if (!empty($report[$key]))
            <div class="alert {{ $key === 'secrets' || $key === 'refs' ? 'alert-warning' : 'alert-info' }}">
                <strong>{{ __('workflowpacks::messages.'.$label) }}</strong>
                <ul>@foreach (array_unique($report[$key]) as $line)<li>{{ $line }}</li>@endforeach</ul>
            </div>
        @endif
    @endforeach
    @if (!empty($report['replaced']))
        <div class="alert alert-info">
            <strong>{{ __('workflowpacks::messages.report_replaced') }}</strong>
            <ul>@foreach ($report['replaced'] as $text => $n)<li><code>{{ $text }}</code> : {{ $n }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- what "Group into the pack" would do, rule by rule --}}
    @if (!empty($composed))
        <div class="panel panel-default">
            <div class="panel-heading"><strong>{{ __('workflowpacks::messages.group_title', ['name' => $pack['name']]) }}</strong></div>
            <div class="panel-body">
                <p class="wp-muted">{{ __('workflowpacks::messages.group_help') }}</p>
                @if ($composed['warnings'])
                    <div class="alert alert-warning"><ul>@foreach ($composed['warnings'] as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                @endif
                <div class="table-responsive">
                    <table class="table table-condensed">
                        <tr><th>{{ __('workflowpacks::messages.group_rule') }}</th><th>{{ __('workflowpacks::messages.filter_mailbox') }}</th><th>{{ __('workflowpacks::messages.group_key') }}</th><th>{{ __('workflowpacks::messages.group_effect') }}</th></tr>
                        @foreach ($composed['assign'] as $a)
                            <tr>
                                <td>{{ $a['name'] }}</td>
                                <td>{{ $mailbox_names[$a['mailbox_id']] ?? '#'.$a['mailbox_id'] }}</td>
                                <td><code>{{ $a['key'] }}</code></td>
                                <td>
                                    {{ __('workflowpacks::messages.group_state_'.$a['state']) }}
                                    @if ($a['from'])<br><span class="label label-warning">{{ __('workflowpacks::messages.group_moved', ['packs' => implode(', ', $a['from'])]) }}</span>@endif
                                    @if (!empty($a['differs']))<br><span class="label label-default">{{ __('workflowpacks::messages.group_state_local') }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>
    @endif

    @foreach ($pack['workflows'] as $rule)
        @include('workflowpacks::partials.rule_schema', ['rule' => $rule, 'mailboxId' => null])
    @endforeach

    <form method="POST" action="{{ route('workflowpacks.export.download') }}" class="wp-form wp-export-actions" data-wp-busy="off">{{-- file download: the page stays, no loading indicator --}}
        {{ csrf_field() }}
        @foreach ($input as $k => $v)
            @if (is_array($v))
                @foreach ($v as $i => $item)
                    @if (is_scalar($item))<input type="hidden" name="{{ $k }}[{{ $i }}]" value="{{ $item }}">@endif
                @endforeach
            @elseif (is_scalar($v))
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endif
        @endforeach
        <button type="submit" class="btn {{ empty($composed) ? 'btn-primary' : 'btn-default' }}" @if ($problems) disabled @endif>{{ __('workflowpacks::messages.download') }}</button>
        @if (!empty($composed))
            <button type="submit" class="btn btn-primary" formaction="{{ route('workflowpacks.export.group') }}" @if ($problems) disabled @endif>{{ __('workflowpacks::messages.group_button', ['name' => $pack['name']]) }}</button>
        @endif
        <a href="{{ route('workflowpacks.export') }}" class="btn btn-link">{{ __('workflowpacks::messages.back') }}</a>
    </form>
    <textarea class="form-control wp-mono wp-json" rows="18" readonly>{{ $json }}</textarea>
@endsection
