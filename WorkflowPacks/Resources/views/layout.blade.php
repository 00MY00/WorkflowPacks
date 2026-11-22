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
        {{-- phones: the sections in a menu (the tabs would go off the screen); without JavaScript the tabs stay --}}
        <button type="button" class="wp-tabs-toggle" aria-expanded="false" aria-controls="wp-tabs" data-wp-tabs>
            <span>☰ {{ __('workflowpacks::messages.sections') }}</span> <span class="wp-tabs-current"></span> <span aria-hidden="true">▾</span></button>
        <ul class="nav nav-tabs wp-tabs" id="wp-tabs">
            <li class="{{ Route::is('workflowpacks.flows') || Route::is('workflowpacks.reapply*') || Route::is('workflowpacks.untreated*') ? 'active' : '' }}"><a href="{{ route('workflowpacks.flows') }}">{{ __('workflowpacks::messages.tab_flows') }}</a></li>
            <li class="{{ Route::is('workflowpacks.flowpack') || Route::is('workflowpacks.flowpack.preview') ? 'active' : '' }}"><a href="{{ route('workflowpacks.flowpack') }}">{{ __('workflowpacks::messages.import') }}</a></li>
            <li class="{{ Route::is('workflowpacks.flowpack.export') ? 'active' : '' }}"><a href="{{ route('workflowpacks.flowpack.export') }}">{{ __('workflowpacks::messages.export') }}</a></li>
            <li class="{{ Route::is('workflowpacks.ai*') ? 'active' : '' }}"><a href="{{ route('workflowpacks.ai') }}">{{ __('workflowpacks::ai.tab') }}</a></li>
            <li class="{{ Route::is('workflowpacks.history*') ? 'active' : '' }}"><a href="{{ route('workflowpacks.history') }}">{{ __('workflowpacks::messages.history') }}</a></li>
            <li class="{{ Route::is('workflowpacks.diagnostic') ? 'active' : '' }}"><a href="{{ route('workflowpacks.diagnostic') }}">{{ __('workflowpacks::messages.diagnostic') }}</a></li>
            <li><a href="{{ route('settings', ['section' => 'workflowpacks']) }}">{{ __('workflowpacks::messages.settings') }}</a></li>
        </ul>
        </div>
    </div>
    {{-- the separation from Workflows waits for a decision: said on every page until then --}}
    @if (!Route::is('workflowpacks.separation') && \Modules\WorkflowPacks\Services\Separation::pending())
        <div class="alert alert-warning wp-separation-notice">{{ __('workflowpacks::separation.notice') }}
            <a href="{{ route('workflowpacks.separation') }}"><strong>{{ __('workflowpacks::separation.notice_link') }}</strong></a></div>
    @endif
    @php $wp_warnings = !empty($compat) ? array_filter($compat['checks'], function ($check) { return $check['state'] !== 'ok'; }) : []; @endphp
    @if ($wp_warnings)
        <div class="alert alert-{{ $compat['state'] === 'blocking' ? 'danger' : 'warning' }}">
            @foreach ($wp_warnings as $check)<div>[{{ $check['code'] }}] {{ $check['label'] }} — {{ $check['detail'] }}</div>@endforeach
        </div>
    @endif
    @yield('wp_content')
</div>
@endsection

@section('javascripts')
    <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/workflowpacks.js') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection
