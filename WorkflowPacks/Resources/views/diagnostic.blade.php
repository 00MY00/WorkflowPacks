@extends('workflowpacks::layout')

@section('wp_content')
    <h3>{{ __('workflowpacks::messages.checks') }} — {{ __('workflowpacks::messages.state') }} :
        <span class="wp-state wp-state-{{ $result['state'] }}">{{ strtoupper($result['state']) }}</span></h3>
    <table class="table table-condensed wp-checks">
        @foreach ($result['checks'] as $check)
            <tr class="wp-check-{{ $check['state'] }}">
                <td><span class="wp-state wp-state-{{ $check['state'] }}">{{ strtoupper($check['state']) }}</span></td>
                <td><code>{{ $check['code'] }}</code></td>
                <td>{{ $check['label'] }}</td>
                <td class="wp-muted">{{ $check['detail'] }}</td>
            </tr>
        @endforeach
    </table>

    <h4>{{ __('workflowpacks::messages.versions') }}</h4>
    <ul class="wp-versions">
        @foreach ($result['versions'] as $name => $version)
            <li><strong>{{ $name }}</strong> : {{ $version !== '' ? $version : '—' }}</li>
        @endforeach
    </ul>

    <h4>{{ __('workflowpacks::messages.update') }}</h4>
    <p>
        {{ __('workflowpacks::messages.update_installed') }} : <strong>{{ \Modules\WorkflowPacks\Services\Update\Updater::currentVersion() }}</strong>
        @if (!empty($update['latest'])) — {{ __('workflowpacks::messages.update_latest') }} : <strong>{{ $update['latest'] }}</strong>@endif
        — {{ __('workflowpacks::messages.update_state_'.$updateState) }}
        @if (!empty($update['last_result']))<br><span class="wp-muted">{{ __('workflowpacks::messages.last_result') }} : {{ $update['last_result'] }} {{ $update['last_reason'] ?? '' }}</span>@endif
    </p>
    <div class="wp-toolbar">
        <form method="POST" action="{{ route('workflowpacks.check_update') }}" class="wp-inline">{{ csrf_field() }}<button class="btn btn-default btn-sm">{{ __('workflowpacks::messages.check_update') }}</button></form>
        @if ($updateState === 'available')
            <form method="POST" action="{{ route('workflowpacks.update_now') }}" class="wp-inline">{{ csrf_field() }}<button class="btn btn-primary btn-sm">{{ __('workflowpacks::messages.update_now') }}</button></form>
        @endif
        <form method="POST" action="{{ route('workflowpacks.auto_update') }}" class="wp-inline">
            {{ csrf_field() }}
            <input type="hidden" name="enabled" value="{{ $autoUpdate ? 0 : 1 }}">
            {{ __('workflowpacks::messages.auto_update') }} :
            <strong>{{ $autoUpdate ? 'ON' : 'OFF' }}</strong>
            <button class="btn btn-default btn-xs">{{ __('workflowpacks::messages.'.($autoUpdate ? 'disable' : 'enable')) }}</button>
        </form>
    </div>

    @if ($catalog)
        <h4 class="wp-section">{{ __('workflowpacks::messages.catalog') }}</h4>
        <p class="wp-muted">{{ __('workflowpacks::messages.catalog_help') }}</p>
        <form method="GET" action="{{ route('workflowpacks.diagnostic') }}" class="form-inline wp-inline">
            <select name="mailbox_id" class="form-control input-sm wp-autosubmit">
                @foreach ($mailboxes as $mb)
                    <option value="{{ $mb->id }}" @if ($mailbox && $mailbox->id == $mb->id) selected @endif>{{ $mb->name }}</option>
                @endforeach
            </select>
        </form>
        @foreach (['conditions', 'actions'] as $kind)
            <h5>{{ __('workflowpacks::messages.'.$kind) }}</h5>
            <table class="table table-condensed table-striped wp-catalog">
                <tr><th>type</th><th></th><th>{{ __('workflowpacks::messages.operators') }}</th><th>{{ __('workflowpacks::messages.values') }}</th></tr>
                @foreach ($catalog[$kind] as $type => $item)
                    <tr>
                        <td><code>{{ $type }}</code></td>
                        <td>{{ $item['title'] }} <span class="wp-muted">({{ $item['group'] }})</span></td>
                        <td>@if ($item['operators'])@foreach ($item['operators'] as $op)<code>{{ $op }}</code> @endforeach @endif</td>
                        <td>
                            @if ($item['values'] === null){{ __('workflowpacks::messages.free_value') }}
                            @elseif ($item['values'] === []){{ __('workflowpacks::messages.no_value') }}
                            @else @foreach (array_slice($item['values'], 0, 12) as $v)<code>{{ $v }}</code> @endforeach @if (count($item['values']) > 12)…@endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endif
@endsection
