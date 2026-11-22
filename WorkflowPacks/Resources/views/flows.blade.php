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
    {{-- one line of actions; the grid of the flows gets the room, the settings are on sides that fold --}}
    <div class="wpf-toolbar" role="toolbar">
        <button type="button" class="btn btn-default btn-sm" data-act="new">+ {{ __('workflowpacks::flows.new_flow') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-act="add">+ {{ __('workflowpacks::flows.add_block') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-act="simulate">▶ {{ __('workflowpacks::flows.simulate') }}</button>
        <button type="button" class="btn btn-primary btn-sm" data-act="publish">{{ __('workflowpacks::flows.publish') }}</button>
        <span class="wpf-status" role="status" aria-live="polite"></span>
        <span class="wpf-grow"></span>
        <button type="button" class="btn btn-link btn-sm" data-act="fit">{{ __('workflowpacks::flows.zoom_fit') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-act="fullscreen" aria-pressed="false">{{ __('workflowpacks::flows.enlarge') }}</button>
    </div>
    <div class="wpf-main">
        {{-- left: every flow (which ones the grid shows, open one, tick several to delete them) --}}
        <aside class="wpf-side wpf-list" aria-label="{{ __('workflowpacks::flows.list_title') }}">
            <div class="wpf-side-head"><strong>{{ __('workflowpacks::flows.list_title') }}</strong> <span class="wpf-list-count"></span>
                <button type="button" class="wpf-side-close" data-act="toggle-list" title="{{ __('workflowpacks::flows.fold') }}" aria-label="{{ __('workflowpacks::flows.fold') }}">‹</button></div>
            <label class="wpf-list-show"><span>{{ __('workflowpacks::flows.show') }}</span>
                <select class="form-control input-sm wpf-show">
                    <option value="all">{{ __('workflowpacks::flows.show_all') }}</option>
                    <option value="checked">{{ __('workflowpacks::flows.show_checked') }}</option>
                    <option value="open">{{ __('workflowpacks::flows.show_open') }}</option>
                </select></label>
            <div class="wpf-list-tools">
                <label class="wpf-check"><input type="checkbox" class="wpf-list-all"> {{ __('workflowpacks::flows.list_all') }}</label>
                <button type="button" class="btn btn-default btn-sm" data-act="bulk-on" disabled title="{{ __('workflowpacks::flows.bulk_on_help') }}">{{ __('workflowpacks::flows.bulk_on') }}</button>
                <button type="button" class="btn btn-default btn-sm" data-act="bulk-off" disabled>{{ __('workflowpacks::flows.bulk_off') }}</button>
                <button type="button" class="btn btn-default btn-sm wpf-list-del" data-act="delete-many" disabled>{{ __('workflowpacks::flows.delete_many_none') }}</button>
            </div>
            <ul class="wpf-list-items"></ul>
            {{-- the tickets no flow has acted on: a temporary flow for them --}}
            <a class="btn btn-link btn-sm wpf-list-untreated" href="{{ route('workflowpacks.untreated') }}">{{ __('workflowpacks::temp.untreated_link') }}</a>
        </aside>
        <div class="wpf-stage">
            <svg class="wpf-canvas" xmlns="http://www.w3.org/2000/svg"><g class="wpf-vp"><g class="wpf-others"></g><g class="wpf-act"><g class="wpf-frames"></g><g class="wpf-links"></g><g class="wpf-blocks"></g></g></g></svg>
            <p class="wpf-empty" hidden>{{ __('workflowpacks::flows.empty') }}</p>
            {{-- folded sides open again from the grid --}}
            <button type="button" class="wpf-edge wpf-edge-list" data-act="toggle-list" aria-expanded="true">☰ {{ __('workflowpacks::flows.list_title') }}</button>
            <button type="button" class="wpf-edge wpf-edge-panel" data-act="toggle-panel" aria-expanded="true">⚙ {{ __('workflowpacks::flows.settings') }}</button>
            {{-- what is chosen on the grid: delete it, open its settings (with a finger, after a long press) --}}
            <div class="wpf-selbar" role="toolbar" hidden>
                <span class="wpf-selbar-count"></span>
                <button type="button" class="btn btn-sm btn-default" data-act="select-all" title="Ctrl+A">{{ __('workflowpacks::flows.sel_all') }}</button>
                <button type="button" class="btn btn-sm btn-danger-soft" data-act="delete-sel">{{ __('workflowpacks::flows.sel_delete') }}</button>
                <button type="button" class="btn btn-sm btn-default" data-act="settings-sel">⚙ {{ __('workflowpacks::flows.settings') }}</button>
                <button type="button" class="wpf-selbar-x" data-act="clear-sel" aria-label="{{ __('workflowpacks::flows.sel_clear') }}" title="{{ __('workflowpacks::flows.sel_clear') }}">×</button>
            </div>
        </div>
        {{-- right: the settings of the open flow, then of the chosen block (or the simulation, the path of a ticket) --}}
        <aside class="wpf-side wpf-side-panel" aria-label="{{ __('workflowpacks::flows.settings') }}">
            <div class="wpf-side-head"><strong>{{ __('workflowpacks::flows.settings') }}</strong>
                <button type="button" class="wpf-side-close" data-act="toggle-panel" title="{{ __('workflowpacks::flows.fold') }}" aria-label="{{ __('workflowpacks::flows.fold') }}">›</button></div>
            <details class="wpf-settings" open>
                <summary><span class="wpf-settings-name"></span></summary>
                <label class="wpf-field"><span>{{ __('workflowpacks::flows.name') }}</span>
                    <input type="text" class="form-control input-sm wpf-name" maxlength="191"></label>
                <details class="wpf-boxes">
                    <summary class="btn btn-default btn-sm">{{ __('workflowpacks::flows.mailboxes') }}: <span class="wpf-boxes-label"></span></summary>
                    <div class="wpf-boxes-list"></div>
                </details>
                <label class="wpf-check" title="{{ __('workflowpacks::flows.once_help') }}"><input type="checkbox" class="wpf-once"> {{ __('workflowpacks::flows.once') }}</label>
                <label class="wpf-check wpf-active-row"><input type="checkbox" class="wpf-active"> {{ __('workflowpacks::flows.active') }}</label>
                {{-- a temporary flow: its list of tickets, launched once, then deleted --}}
                <p class="wpf-temp" role="note" hidden></p>
                <div class="wpf-inline">
                    <button type="button" class="btn btn-default btn-sm" data-act="reapply" title="{{ __('workflowpacks::reapply.intro') }}">{{ __('workflowpacks::flows.reapply_button') }}</button>
                    <button type="button" class="btn btn-link btn-sm text-danger" data-act="delete-flow">{{ __('workflowpacks::flows.delete_flow') }}</button>
                </div>
            </details>
            <div class="wpf-panel" aria-live="polite"></div>
        </aside>
    </div>
</div>
@endsection

@section('javascripts')
    @parent
    <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/flows/icons.js') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
    <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/flows/editor.js') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
    <script {!! \Helper::cspNonceAttr() !!}>WPFlows.start(document.getElementById('wpf-app'), {!! json_encode($config + ['token' => csrf_token()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});</script>
@endsection
