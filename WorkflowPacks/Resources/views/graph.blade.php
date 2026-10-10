{{--
    Workflow Packs › Diagram: mailboxes, rules, destinations and packs as a network diagram (draw.io-like).
    Everything happens in Public/js/graph/*.js; the data comes from GraphController (JSON), positions are saved live.
--}}
@extends('workflowpacks::layout')

@section('wp_container', 'container-fluid')

@section('stylesheets')
    @parent
    <link href="{{ asset(\Module::getPublicPath('workflowpacks').'/css/graph.css') }}?v={{ \Modules\WorkflowPacks\Services\Update\Updater::currentVersion() }}" rel="stylesheet" type="text/css">
@endsection

@section('wp_content')
@php
    $wpg_i = function ($name) {
        // the same line icons as the diagram (Public/js/graph/icons.js); constant markup
        return '<svg class="wpg-svg-icon" viewBox="0 0 24 24" aria-hidden="true"><use href="#wpg-i-'.e($name).'"></use></svg>';
    };
    $wpg_config = [
        'token' => csrf_token(),
        'urls'  => [
            'data'      => route('workflowpacks.graph.data'),
            'layout'    => route('workflowpacks.graph.layout'),
            'rule'      => route('workflowpacks.graph.rule', ['id' => '__ID__']),
            'catalog'   => route('workflowpacks.graph.catalog', ['mailbox_id' => '__ID__']),
            'save'      => route('workflowpacks.graph.save'),
            'toggle'    => route('workflowpacks.graph.toggle', ['id' => '__ID__']),
            'apply'     => route('workflowpacks.graph.apply', ['id' => '__ID__']),
            'reimport'  => route('workflowpacks.reimport', ['key' => '__ID__']),
            'workflows' => \Route::has('mailboxes.workflows') ? route('mailboxes.workflows', ['mailbox_id' => '__ID__']) : '',
        ],
        'texts' => __('workflowpacks::graph'),
    ];
@endphp
<div class="wpg-app">
    <script type="application/json" id="wpg-config">{!! json_encode($wpg_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>

    {{-- icons used in the toolbar (the diagram defines them in its own <defs>) --}}
    <svg width="0" height="0" class="wpg-defs" aria-hidden="true"><defs id="wpg-toolbar-defs"></defs></svg>

    <div class="wpg-toolbar" role="toolbar">
        <div class="wpg-group">
            <button type="button" class="wpg-btn wpg-btn-primary" data-act="new" title="{{ __('workflowpacks::graph.new_rule') }} (N)">{!! $wpg_i('plus') !!}<span>{{ __('workflowpacks::graph.new_rule') }}</span></button>
        </div>
        <div class="wpg-group">
            <label class="wpg-label" for="wpg-layout">{{ __('workflowpacks::graph.arrange') }}</label>
            <select id="wpg-layout" class="wpg-select">
                <option value="columns">{{ __('workflowpacks::graph.layout_columns') }}</option>
                <option value="radial">{{ __('workflowpacks::graph.layout_radial') }}</option>
                <option value="grid">{{ __('workflowpacks::graph.layout_grid') }}</option>
                <option value="free">{{ __('workflowpacks::graph.layout_free') }}</option>
            </select>
            <label class="wpg-label" for="wpg-edges">{{ __('workflowpacks::graph.edges') }}</label>
            <select id="wpg-edges" class="wpg-select">
                <option value="orthogonal">{{ __('workflowpacks::graph.edges_orthogonal') }}</option>
                <option value="curved">{{ __('workflowpacks::graph.edges_curved') }}</option>
                <option value="straight">{{ __('workflowpacks::graph.edges_straight') }}</option>
            </select>
            <label class="wpg-toggle"><input type="checkbox" id="wpg-packs"> {{ __('workflowpacks::graph.show_packs') }}</label>
            <label class="wpg-toggle"><input type="checkbox" id="wpg-dests"> {{ __('workflowpacks::graph.show_dests') }}</label>
        </div>
        <div class="wpg-group">
            <button type="button" class="wpg-btn" data-act="undo" title="{{ __('workflowpacks::graph.undo') }} (Ctrl+Z)">{!! $wpg_i('undo') !!}</button>
            <button type="button" class="wpg-btn" data-act="redo" title="{{ __('workflowpacks::graph.redo') }} (Ctrl+Y)">{!! $wpg_i('redo') !!}</button>
            <button type="button" class="wpg-btn" data-act="zoom-out" title="{{ __('workflowpacks::graph.zoom_out') }} (-)">{!! $wpg_i('minus') !!}</button>
            <button type="button" class="wpg-btn" data-act="zoom-in" title="{{ __('workflowpacks::graph.zoom_in') }} (+)">{!! $wpg_i('plus') !!}</button>
            <button type="button" class="wpg-btn" data-act="fit" title="{{ __('workflowpacks::graph.fit') }} (0)">{!! $wpg_i('fit') !!}<span>{{ __('workflowpacks::graph.fit') }}</span></button>
        </div>
        <div class="wpg-group wpg-grow">
            <select id="wpg-goto" class="wpg-select" title="{{ __('workflowpacks::graph.goto_help') }}"></select>
            <span class="wpg-search">{!! $wpg_i('search') !!}<input type="search" id="wpg-search" placeholder="{{ __('workflowpacks::graph.search_ph') }}"></span>
            <span class="wpg-status is-saved">{{ __('workflowpacks::graph.status_saved') }}</span>
        </div>
    </div>

    <div class="wpg-focus" role="status">
        {!! $wpg_i('focus') !!} <span class="wpg-focus-label"></span>
        <button type="button" class="wpg-btn" data-act="unfocus">{{ __('workflowpacks::graph.unfocus') }}</button>
    </div>

    <div class="wpg-stage">
        <svg class="wpg-canvas" role="application" aria-label="{{ __('workflowpacks::graph.title') }}"></svg>
        <div class="wpg-empty">{{ __('workflowpacks::graph.empty') }}</div>
        <aside class="wpg-inspector" aria-live="polite"></aside>
        <div class="wpg-minimap" title="{{ __('workflowpacks::graph.minimap') }}"><svg></svg></div>
        <div class="wpg-legend">
            <span class="lg lg-active">{{ __('workflowpacks::graph.legend_active') }}</span>
            <span class="lg lg-inactive">{{ __('workflowpacks::graph.legend_inactive') }}</span>
            <span class="lg lg-modified">{{ __('workflowpacks::graph.legend_modified') }}</span>
            <span class="lg lg-missing">{{ __('workflowpacks::graph.legend_missing') }}</span>
            <span class="lg lg-sends">{{ __('workflowpacks::graph.legend_sends') }}</span>
        </div>
        <div class="wpg-toasts" aria-live="polite"></div>
    </div>
    <p class="wpg-help">{{ __('workflowpacks::graph.help') }}</p>
    <div class="wpg-overlay"></div>
</div>
@endsection

@section('javascripts')
    @parent
    @foreach (['icons', 'layout', 'model', 'api', 'view', 'minimap', 'inspector', 'editor', 'app'] as $wpg_js)
        <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/graph/'.$wpg_js.'.js') }}?v={{ \Modules\WorkflowPacks\Services\Update\Updater::currentVersion() }}" {!! \Helper::cspNonceAttr() !!}></script>
    @endforeach
    <script {!! \Helper::cspNonceAttr() !!}>WPGraph.icons.define(document.getElementById('wpg-toolbar-defs'));</script>
@endsection
