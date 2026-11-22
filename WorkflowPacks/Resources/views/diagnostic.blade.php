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

    {{-- the blocks of the flows here: the types (and fields) to use in a pack of flows written by hand --}}
    <details class="wp-fold wp-section">
        <summary>{{ __('workflowpacks::messages.blocks_catalog', ['count' => count($blocks)]) }}</summary>
        <p class="wp-muted">{{ __('workflowpacks::messages.blocks_catalog_help') }}</p>
        <div class="table-responsive">
            <table class="table table-condensed table-striped wp-catalog">
                <tr><th>type</th><th></th><th>{{ __('workflowpacks::messages.blocks_fields') }}</th><th>{{ __('workflowpacks::messages.blocks_outputs') }}</th></tr>
                @foreach ($blocks as $b)
                    <tr>
                        <td><code>{{ $b['type'] }}</code></td>
                        <td>{{ $b['name'] }} <span class="wp-muted">({{ __('workflowpacks::blocks.families.'.$b['family']) }})</span></td>
                        <td>@foreach ($b['fields'] as $key => $field)<code>{{ $key }}</code>@if (!empty($field['values'])) <span class="wp-muted">({{ implode(' | ', array_slice(array_map('strval', $field['values']), 0, 8)) }})</span>@endif @endforeach</td>
                        <td>@foreach ($b['outputs'] as $o)<code>{{ $o['key'] }}</code> @endforeach</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </details>
@endsection
