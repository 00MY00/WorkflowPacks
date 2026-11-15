{{--
    Workflow Packs › Flows (internal engine): each block an object, linked into a flow. Everything
    happens in Public/js/flows/editor.js; the data comes from FlowsController (JSON), the draft is saved as it goes.
--}}
@extends('workflowpacks::layout')

@section('wp_container', 'container-fluid')

@section('stylesheets')
    @parent
    <link href="{{ asset(\Module::getPublicPath('workflowpacks').'/css/flows.css') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" rel="stylesheet" type="text/css">
@endsection

@section('wp_content')
<div class="wpf-app" id="wpf-app">
    <p class="wpf-intro">{{ __('workflowpacks::flows.intro') }}
        <a class="btn btn-default btn-sm" href="{{ route('workflowpacks.flowpack') }}">{{ __('workflowpacks::flowpack.button_import') }}</a>
        <a class="btn btn-default btn-sm" href="{{ route('workflowpacks.flowpack.export') }}">{{ __('workflowpacks::flowpack.button_export') }}</a></p>
    <div class="wpf-toolbar" role="toolbar">
        <select class="form-control input-sm wpf-flow-select" aria-label="{{ __('workflowpacks::flows.tab') }}"></select>
        <button type="button" class="btn btn-default btn-sm" data-act="new">+ {{ __('workflowpacks::flows.new_flow') }}</button>
        <span class="wpf-sep"></span>
        <input type="text" class="form-control input-sm wpf-name" maxlength="191" aria-label="{{ __('workflowpacks::flows.name') }}" placeholder="{{ __('workflowpacks::flows.name') }}">
        <details class="wpf-boxes">
            <summary class="btn btn-default btn-sm">{{ __('workflowpacks::flows.mailboxes') }}: <span class="wpf-boxes-label"></span></summary>
            <div class="wpf-boxes-list"></div>
        </details>
        <label class="wpf-check" title="{{ __('workflowpacks::flows.once_help') }}"><input type="checkbox" class="wpf-once"> {{ __('workflowpacks::flows.once') }}</label>
        <label class="wpf-check"><input type="checkbox" class="wpf-active"> {{ __('workflowpacks::flows.active') }}</label>
        <span class="wpf-sep"></span>
        <button type="button" class="btn btn-default btn-sm" data-act="add">+ {{ __('workflowpacks::flows.add_block') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-act="simulate">▶ {{ __('workflowpacks::flows.simulate') }}</button>
        <button type="button" class="btn btn-primary btn-sm" data-act="publish">{{ __('workflowpacks::flows.publish') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-act="reapply" title="{{ __('workflowpacks::reapply.intro') }}">{{ __('workflowpacks::flows.reapply_button') }}</button>
        <span class="wpf-status" role="status" aria-live="polite"></span>
        <span class="wpf-grow"></span>
        <button type="button" class="btn btn-link btn-sm" data-act="fit">{{ __('workflowpacks::flows.zoom_fit') }}</button>
        <button type="button" class="btn btn-link btn-sm text-danger" data-act="delete-flow">{{ __('workflowpacks::flows.delete_flow') }}</button>
    </div>
    <div class="wpf-main">
        <div class="wpf-stage">
            <svg class="wpf-canvas" xmlns="http://www.w3.org/2000/svg"><g class="wpf-vp"><g class="wpf-links"></g><g class="wpf-blocks"></g></g></svg>
            <p class="wpf-empty" hidden>{{ __('workflowpacks::flows.empty') }}</p>
        </div>
        <aside class="wpf-panel" aria-live="polite"></aside>
    </div>
    <p class="wpf-help">{{ __('workflowpacks::flows.help') }}</p>
</div>
@endsection

@section('javascripts')
    @parent
    <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/graph/icons.js') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
    <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/flows/editor.js') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
    <script {!! \Helper::cspNonceAttr() !!}>WPFlows.start(document.getElementById('wpf-app'), {!! json_encode($config + ['token' => csrf_token()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});</script>
@endsection
