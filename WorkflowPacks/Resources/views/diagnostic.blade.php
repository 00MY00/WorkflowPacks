@extends('workflowpacks::layout')

@section('wp_content')
    <h3>{{ __('workflowpacks::messages.checks') }} — {{ __('workflowpacks::messages.state') }} :
        <span class="wp-state wp-state-{{ $result['state'] }}">{{ strtoupper($result['state']) }}</span></h3>
    @php
        $wp_problems = array_values(array_filter($result['checks'], function ($c) { return $c['state'] !== 'ok'; }));
        $wp_ok = array_values(array_filter($result['checks'], function ($c) { return $c['state'] === 'ok'; }));
    @endphp
    @if ($wp_problems)
        @include('workflowpacks::partials.checks', ['checks' => $wp_problems])
    @endif
    <details class="wp-fold">
        <summary>{{ __('workflowpacks::messages.checks_ok', ['count' => count($wp_ok)]) }} · {{ __('workflowpacks::messages.versions') }}</summary>
        @include('workflowpacks::partials.checks', ['checks' => $wp_ok])
        <ul class="wp-versions">
            @foreach ($result['versions'] as $name => $version)
                <li><strong>{{ $name }}</strong> : {{ $version !== '' ? $version : '—' }}</li>
            @endforeach
        </ul>
    </details>

    <h4>{{ __('workflowpacks::messages.update') }}</h4>
    @include('workflowpacks::partials.updates')

    @if ($catalog)
        <details class="wp-fold wp-section" @if (request()->has('mailbox_id')) open @endif>
        <summary>{{ __('workflowpacks::messages.catalog') }}</summary>
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
        </details>
    @endif
@endsection
