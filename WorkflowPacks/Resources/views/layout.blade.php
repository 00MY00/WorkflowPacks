{{-- Common frame of the module's pages: FreeScout layout; Refresh's look when Refresh is active (its CSS variables). --}}
@extends('layouts.app')

@section('title', __('workflowpacks::messages.title'))

@section('stylesheets')
    <link href="{{ asset(\Module::getPublicPath('workflowpacks').'/css/workflowpacks.css') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" rel="stylesheet" type="text/css">
@endsection

@section('content')
<div class="@yield('wp_container', 'container') wp-page @if (!empty($wpRefresh)) wp-refresh @endif">
    <div class="wp-header">
        <h1>{{ __('workflowpacks::messages.title') }}</h1>
        <ul class="nav nav-tabs wp-tabs">
            <li class="{{ Route::is('workflowpacks.index') ? 'active' : '' }}"><a href="{{ route('workflowpacks.index') }}">{{ __('workflowpacks::messages.menu') }}</a></li>
            <li class="{{ Route::is('workflowpacks.import*') || Route::is('workflowpacks.reimport') ? 'active' : '' }}"><a href="{{ route('workflowpacks.import') }}">{{ __('workflowpacks::messages.import') }}</a></li>
            <li class="{{ Route::is('workflowpacks.graph') ? 'active' : '' }}"><a href="{{ route('workflowpacks.graph') }}">{{ __('workflowpacks::graph.title') }}</a></li>
            <li class="{{ Route::is('workflowpacks.export*') ? 'active' : '' }}"><a href="{{ route('workflowpacks.export') }}">{{ __('workflowpacks::messages.export') }}</a></li>
            <li class="{{ Route::is('workflowpacks.history*') ? 'active' : '' }}"><a href="{{ route('workflowpacks.history') }}">{{ __('workflowpacks::messages.history') }}</a></li>
            @if (Auth::user()->isAdmin())
                <li class="{{ Route::is('workflowpacks.diagnostic') ? 'active' : '' }}"><a href="{{ route('workflowpacks.diagnostic') }}">{{ __('workflowpacks::messages.diagnostic') }}</a></li>
                <li><a href="{{ route('settings', ['section' => 'workflowpacks']) }}">{{ __('workflowpacks::messages.settings') }}</a></li>
            @endif
        </ul>
    </div>
    @if (!empty($compat) && $compat['state'] === 'warning' && Auth::user()->isAdmin())
        <div class="alert alert-warning">
            @foreach ($compat['checks'] as $check)
                @if ($check['state'] === 'warning')<div>[{{ $check['code'] }}] {{ $check['label'] }} — {{ $check['detail'] }}</div>@endif
            @endforeach
        </div>
    @endif
    @yield('wp_content')
</div>
@endsection

@section('javascripts')
    <script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/workflowpacks.js') }}?v={{ \Modules\WorkflowPacks\Services\ModuleInfo::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection
