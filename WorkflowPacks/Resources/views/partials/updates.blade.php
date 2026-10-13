{{--
    Who updates the module (Manage › Settings › Workflow Packs, Diagnostic): UpdateGuard when it is installed (safe
    updates), else FreeScout's own Manage › Modules page, with UpdateGuard recommended.
--}}
@php $wp_M = \Modules\WorkflowPacks\Services\ModuleInfo::class; @endphp
<p>{{ __('workflowpacks::messages.updates_version', ['version' => $wp_M::version()]) }}</p>
@if ($wp_M::updateGuard())
    <p class="wp-updates wp-updates-guard">
        <i class="glyphicon glyphicon-ok-sign text-success" aria-hidden="true"></i>
        {{ __('workflowpacks::messages.updates_guard') }}
        @if ($wp_M::updateGuardUrl())
            <a href="{{ $wp_M::updateGuardUrl() }}">{{ __('workflowpacks::messages.updates_guard_open') }}</a>
        @endif
    </p>
@else
    <p class="wp-updates wp-updates-native">
        {{ __('workflowpacks::messages.updates_native') }}
        <a href="{{ $wp_M::modulesUrl() }}">{{ __('workflowpacks::messages.updates_modules_open') }}</a>
    </p>
    <p class="alert alert-info wp-updates-advice">
        <i class="glyphicon glyphicon-info-sign" aria-hidden="true"></i>
        {{ __('workflowpacks::messages.updates_advice') }}
        <a href="{{ $wp_M::UPDATEGUARD_URL }}" target="_blank" rel="noopener noreferrer">UpdateGuard</a>
    </p>
@endif
