{{-- Common frame of the module's pages: FreeScout layout; Refresh's look when Refresh is active (its CSS variables). --}}
@extends('layouts.app')

@section('title', __('workflowpacks::messages.title'))

@section('stylesheets')
    <link href="{{ asset(\Module::getPublicPath('workflowpacks').'/css/workflowpacks.css') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" rel="stylesheet" type="text/css">
    <link href="{{ asset(\Module::getPublicPath('workflowpacks').'/css/workflowpacks-g.css') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" rel="stylesheet" type="text/css">
@endsection

@section('content')
<div class="@yield('wp_container', 'container') wp-page @if (!empty($wpRefresh)) wp-refresh @endif">
    <div class="wp-header">
        {{-- title + description on the left, the actions of the page on the right (one primary button) --}}
        <div class="wp-header-text">
            {{-- sub-pages: the way back at the top (on a phone the buttons at the bottom are far away) --}}
            @hasSection('wp_back')<a class="wp-back" href="@yield('wp_back')">‹ @yield('wp_back_label', __('workflowpacks::messages.back'))</a>@endif
            <h1>{{ __('workflowpacks::messages.title') }}</h1>
            @hasSection('wp_description')<p class="wp-header-desc">@yield('wp_description')</p>@endif
        </div>
        @hasSection('wp_actions')<div class="wp-header-actions">@yield('wp_actions')</div>@endif
        <div class="wp-header-row">
        @php
            // one "Diagram" tab: the editor of the flows when the engine is on (administrators), else the diagram of
            // the Workflows rules; without Workflows, import and export lead straight to the packs of flows
            $wp_flows = Auth::user()->isAdmin() && \Modules\WorkflowPacks\Services\Engine\Engine::enabled();
            $wp_direct = $wp_flows && !\Modules\WorkflowPacks\Services\Compat\Checker::workflowsReady();
        @endphp
        {{-- phones: the sections in a menu (the tabs would go off the screen); without JavaScript the tabs stay --}}
        <button type="button" class="wp-tabs-toggle" aria-expanded="false" aria-controls="wp-tabs" data-wp-tabs>
            <span>☰ {{ __('workflowpacks::messages.sections') }}</span> <span class="wp-tabs-current"></span> <span aria-hidden="true">▾</span></button>
        <ul class="nav nav-tabs wp-tabs" id="wp-tabs">
            <li class="{{ Route::is('workflowpacks.index') ? 'active' : '' }}"><a href="{{ route('workflowpacks.index') }}">{{ __('workflowpacks::messages.menu') }}</a></li>
            <li class="{{ Route::is('workflowpacks.import*') || Route::is('workflowpacks.reimport') || Route::is('workflowpacks.flowpack') || Route::is('workflowpacks.flowpack.preview') ? 'active' : '' }}"><a href="{{ route($wp_direct ? 'workflowpacks.flowpack' : 'workflowpacks.import') }}">{{ __('workflowpacks::messages.import') }}</a></li>
            <li class="{{ Route::is('workflowpacks.graph') || Route::is('workflowpacks.flows') || Route::is('workflowpacks.reapply*') ? 'active' : '' }}"><a href="{{ route($wp_flows ? 'workflowpacks.flows' : 'workflowpacks.graph') }}">{{ __('workflowpacks::graph.title') }}</a></li>
            <li class="{{ Route::is('workflowpacks.export*') || Route::is('workflowpacks.flowpack.export') ? 'active' : '' }}"><a href="{{ route($wp_direct ? 'workflowpacks.flowpack.export' : 'workflowpacks.export') }}">{{ __('workflowpacks::messages.export') }}</a></li>
            <li class="{{ Route::is('workflowpacks.history*') ? 'active' : '' }}"><a href="{{ route('workflowpacks.history') }}">{{ __('workflowpacks::messages.history') }}</a></li>
            @if ($wp_flows)
                <li class="{{ Route::is('workflowpacks.ai*') ? 'active' : '' }}"><a href="{{ route('workflowpacks.ai') }}">{{ __('workflowpacks::ai.tab') }}</a></li>
            @endif
            @if (Auth::user()->isAdmin())
                <li class="{{ Route::is('workflowpacks.diagnostic') ? 'active' : '' }}"><a href="{{ route('workflowpacks.diagnostic') }}">{{ __('workflowpacks::messages.diagnostic') }}</a></li>
                <li><a href="{{ route('settings', ['section' => 'workflowpacks']) }}">{{ __('workflowpacks::messages.settings') }}</a></li>
            @endif
        </ul>
        </div>
    </div>
    @php
        // warnings to show: the Workflows ones only when Workflows is there (otherwise an empty yellow box)
        $wp_warnings = (!empty($compat) && $compat['state'] === 'warning' && Auth::user()->isAdmin())
            ? array_filter($compat['checks'], function ($check) {
                return $check['state'] === 'warning'
                    && (strpos($check['code'], 'WP-WF-') !== 0 || \Modules\WorkflowPacks\Services\Compat\Checker::workflowsReady());
            })
            : [];
    @endphp
    @if ($wp_warnings)
        <div class="alert alert-warning">
            @foreach ($wp_warnings as $check)<div>[{{ $check['code'] }}] {{ $check['label'] }} — {{ $check['detail'] }}</div>@endforeach
        </div>
    @endif
    @yield('wp_content')
</div>
@endsection

@section('javascripts')
    <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/workflowpacks.js') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection
