<div class="alert alert-danger">
    <strong>{{ __('workflowpacks::messages.blocked_title') }}</strong>
    <ul>
        @foreach (\Modules\WorkflowPacks\Services\Compat\Checker::blockingReasons() as $reason)
            <li>{{ $reason }}</li>
        @endforeach
    </ul>
</div>
