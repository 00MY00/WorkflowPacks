@extends('workflowpacks::layout')

@section('wp_content')
    <h3>{{ __('workflowpacks::messages.export_title') }} — {{ $pack['name'] }} <code>{{ $pack['key'] }}</code></h3>
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

    @foreach ($pack['workflows'] as $rule)
        @include('workflowpacks::partials.rule_schema', ['rule' => $rule, 'mailboxId' => null])
    @endforeach

    <form method="POST" action="{{ route('workflowpacks.export.download') }}" class="wp-form" data-wp-busy="off">{{-- file download: the page stays, no loading indicator --}}
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
        <button type="submit" class="btn btn-primary" @if ($problems) disabled @endif>{{ __('workflowpacks::messages.download') }}</button>
        <a href="{{ route('workflowpacks.export') }}" class="btn btn-link">{{ __('workflowpacks::messages.back') }}</a>
    </form>
    <textarea class="form-control wp-mono wp-json" rows="18" readonly>{{ $json }}</textarea>
@endsection
