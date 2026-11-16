{{-- Export of the flows of the internal engine as a pack: the flows to include (all ticked), the name, download. --}}
@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_description'){{ __('workflowpacks::flowpack.export_intro') }}@endsection

@section('wp_content')
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('package') !!}</span>
            <div class="wp-pack-title"><h2 class="wp-pack-name">{{ __('workflowpacks::flowpack.button_export') }}</h2></div>
        </div>
        <div class="wp-pack-body">
            @if (!count($flows))
                <p class="wp-muted">{{ __('workflowpacks::flowpack.export_none') }}</p>
            @else
                <form method="GET" action="{{ route('workflowpacks.flowpack.export') }}">
                    <input type="hidden" name="download" value="1">
                    <div class="wp-ai-grid">
                        <label class="wp-ai-field"><span>{{ __('workflowpacks::flowpack.export_name_label') }}</span>
                            <input type="text" name="name" class="form-control" maxlength="120" value="{{ __('workflowpacks::flowpack.export_name') }}"></label>
                        <div class="wp-ai-field"><span>{{ __('workflowpacks::flowpack.flows_count', ['count' => count($flows)]) }}</span>
                            <div class="wp-box-checks">
                                @foreach ($flows as $f)<label><input type="checkbox" name="ids[]" value="{{ $f->id }}" @if (!$chosen || in_array((int) $f->id, $chosen, true)) checked @endif> {{ $f->name }}@if ($f->active) <span class="wp-muted">●</span>@endif</label>@endforeach
                            </div>
                            <small class="wp-muted">{{ __('workflowpacks::flowpack.export_help') }}</small></div>
                    </div>
                    <div class="wp-actionbar"><button type="submit" class="btn btn-primary">{!! $wp_ui::icon('download') !!} {{ __('workflowpacks::flowpack.download') }}</button></div>
                </form>
            @endif
        </div>
    </section>
@endsection
