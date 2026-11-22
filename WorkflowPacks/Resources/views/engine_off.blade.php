{{-- The flow engine is switched off (Manage › Settings › Workflow Packs): said plainly, with the way to switch it on. --}}
@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_content')
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('power') !!}</span>
            <div class="wp-pack-title"><h2 class="wp-pack-name">{{ __('workflowpacks::messages.engine_off_title') }}</h2></div>
        </div>
        <div class="wp-pack-body">
            <p>{{ __('workflowpacks::messages.engine_off') }}</p>
            <p><a class="btn btn-primary" href="{{ route('settings', ['section' => 'workflowpacks']) }}">{{ __('workflowpacks::messages.engine_off_settings') }}</a></p>
        </div>
    </section>
@endsection
