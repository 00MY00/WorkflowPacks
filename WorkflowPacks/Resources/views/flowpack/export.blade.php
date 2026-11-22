{{-- Export of the flows of the internal engine as a pack: the flows to include (all ticked), the name, download. --}}
@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_description'){{ __('workflowpacks::flowpack.export_intro') }}@endsection

@section('wp_content')
    {{-- the flows by the pack they came from (the ones made here last): each pack exported again in one click --}}
    @foreach ($groups as $group)
        <section class="wp-pack-g wp-pack">
            <div class="wp-pack-head">
                <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon($group['key'] !== '' ? 'package' : 'layers') !!}</span>
                <div class="wp-pack-title">
                    <h2 class="wp-pack-name">{{ $group['key'] !== '' ? $group['name'] : __('workflowpacks::flowpack.home_local') }}</h2>
                    <div class="wp-pills">
                        @if ($group['key'] !== '')<span class="wp-pill wp-pill-slug">{{ $group['key'] }}</span>@endif
                        <span class="wp-pill wp-pill-indigo">{{ __('workflowpacks::flowpack.flows_count', ['count' => count($group['flows'])]) }}</span>
                    </div>
                </div>
                <div class="wp-pack-actions">
                    <a class="btn btn-default" href="{{ route('workflowpacks.flowpack.export', ['ids' => collect($group['flows'])->pluck('id')->all()]) }}">{!! $wp_ui::icon('download') !!} {{ __('workflowpacks::flowpack.home_export') }}</a>
                </div>
            </div>
            <div class="wp-pack-body">
                <div class="wp-tiles">
                    @foreach ($group['flows'] as $flow)
                        <a class="wp-tile" href="{{ route('workflowpacks.flows', ['flow' => $flow->id]) }}" title="{{ __('workflowpacks::flowpack.home_open') }}">
                            <span class="wp-initials wp-fam-{{ $wp_ui::family($flow->name) }}" aria-hidden="true">{{ $wp_ui::initials($flow->name) }}<span class="wp-state-dot {{ $flow->active ? 'is-synced' : ($flow->version ? 'is-modified' : 'is-missing') }}"></span></span>
                            <span class="wp-tile-name">{{ $flow->name }}
                                <span class="wp-tile-note">{{ $flow->version ? __('workflowpacks::flows.published', ['n' => $flow->version->number]) : __('workflowpacks::flows.unpublished') }} · {{ $flow->active ? __('workflowpacks::flows.active') : __('workflowpacks::reapply.inactive') }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach
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
