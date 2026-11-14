{{-- The packs of Workflows rules cannot work here: why, and what still works (the internal engine). --}}
@php
    // only Workflows is missing (engine on or off): say what still works, or how to make it work
    $wp_wfMissing = !\Modules\WorkflowPacks\Services\Compat\Checker::workflowsReady() && !\Modules\WorkflowPacks\Services\Compat\Checker::coreBlocking();
    $wp_engine = \Modules\WorkflowPacks\Services\Engine\Engine::enabled();
@endphp
@if ($wp_wfMissing)
    <div class="alert alert-info wp-blocked">
        <strong>{{ __('workflowpacks::messages.wf_missing_title') }}</strong>
        <p>{{ __('workflowpacks::messages.wf_missing_help') }}</p>
        @if ($wp_engine)
            <p>{{ __('workflowpacks::messages.wf_missing_engine_on') }}</p>
            @if (Auth::user()->isAdmin())
                <a class="btn btn-primary" href="{{ route('workflowpacks.flows') }}">{{ __('workflowpacks::flows.tab') }}</a>
                <a class="btn btn-default" href="{{ route('workflowpacks.ai') }}">{{ __('workflowpacks::ai.tab') }}</a>
            @endif
        @else
            <p>{{ __('workflowpacks::messages.wf_missing_engine_off') }}</p>
            @if (Auth::user()->isAdmin())
                <a class="btn btn-primary" href="{{ route('settings', ['section' => 'workflowpacks']) }}">{{ __('workflowpacks::messages.settings') }}</a>
            @endif
        @endif
        <details class="wp-help"><summary>{{ __('workflowpacks::messages.more_info') }}</summary>
            <ul>@foreach (\Modules\WorkflowPacks\Services\Compat\Checker::blockingReasons() as $reason)<li>{{ $reason }}</li>@endforeach</ul></details>
    </div>
@else
    <div class="alert alert-danger wp-blocked">
        <strong>{{ __('workflowpacks::messages.blocked_title') }}</strong>
        <p>{{ __('workflowpacks::messages.blocked_help') }}</p>
        <ul>@foreach (\Modules\WorkflowPacks\Services\Compat\Checker::blockingReasons() as $reason)<li>{{ $reason }}</li>@endforeach</ul>
    </div>
@endif
