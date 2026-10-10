{{--
    Version and update state, same block on Manage › Settings › Workflow Packs and on the diagnostic page.
    States (Updater::state()): running | requested | available | up_to_date | unknown. Parameter: back ('settings' or '').
    Works on FreeScout's settings page too, where the module's stylesheet is not loaded (Bootstrap classes only).
--}}
@php
    $wp_U = '\Modules\WorkflowPacks\Services\Update\Updater';
    $wp_status = $wp_U::status();
    $wp_state = $wp_U::state();
    $wp_busy = in_array($wp_state, ['running', 'requested'], true);
    $wp_time = function ($iso) {
        try {
            return $iso ? \Carbon\Carbon::parse($iso)->format('Y-m-d H:i') : '';
        } catch (\Exception $e) {
            return (string) $iso;
        }
    };
@endphp
<div class="wp-update-state" data-wp-update-state="{{ $wp_state }}">
    <p>
        {{ __('workflowpacks::messages.update_installed') }} <strong>{{ $wp_U::currentVersion() }}</strong>
        @if ($wp_state === 'available' && !empty($wp_status['latest']))
            · {{ __('workflowpacks::messages.update_latest') }} <strong>{{ $wp_status['latest'] }}</strong>
            @if (($wp_status['source'] ?? '') === 'branch')<small>({{ __('workflowpacks::messages.update_source_branch') }})</small>@endif
        @endif
        <span class="label {{ $wp_state === 'up_to_date' ? 'label-success' : ($wp_state === 'available' ? 'label-info' : ($wp_busy ? 'label-warning' : 'label-default')) }}">{{ __('workflowpacks::messages.update_state_'.$wp_state) }}</span>
        @if (!$wp_busy && !empty($wp_status['last_check_at']))
            <small class="text-muted">({{ __('workflowpacks::messages.last_check', ['time' => $wp_time($wp_status['last_check_at'])]) }})</small>
        @endif
    </p>
    @if ($wp_busy)
        <p class="text-warning">{{ __('workflowpacks::messages.update_auto_reload') }}</p>
        {{-- reload until the update is over (state read from the server on every load) --}}
        <script {!! \Helper::cspNonceAttr() !!}>setTimeout(function () { window.location.reload(); }, 10000);</script>
    @elseif (!empty($wp_status['last_result']))
        <p class="@if (in_array($wp_status['last_result'], ['rolled_back', 'failed'], true)) text-danger @else text-muted @endif">
            {{ __('workflowpacks::messages.last_result') }} :
            <strong>{{ __('workflowpacks::messages.update_result_'.$wp_status['last_result']) }}</strong>
            @if (!empty($wp_status['last_to']) && ($wp_status['last_from'] ?? '') !== $wp_status['last_to'])({{ $wp_status['last_from'] ?? '' }} → {{ $wp_status['last_to'] }})@endif
            — {{ $wp_time($wp_status['last_run_at'] ?? '') }}
            @if (!empty($wp_status['last_reason']))<br><small>{{ $wp_status['last_reason'] }}</small>@endif
        </p>
    @endif
    <form method="POST" action="{{ route('workflowpacks.check_update') }}" class="margin-bottom-10">
        {{ csrf_field() }}
        @if (!empty($back))<input type="hidden" name="back" value="{{ $back }}">@endif
        <button type="submit" class="btn btn-default btn-sm" @if ($wp_busy) disabled @endif><i class="glyphicon glyphicon-search"></i> {{ __('workflowpacks::messages.check_update') }}</button>
        <button type="submit" formaction="{{ route('workflowpacks.update_now') }}" class="btn btn-sm {{ $wp_state === 'available' ? 'btn-primary' : 'btn-default' }}" @if ($wp_busy) disabled @endif><i class="glyphicon glyphicon-download-alt"></i> {{ __('workflowpacks::messages.update_now') }}</button>
    </form>
    <p class="form-help text-muted">{{ __('workflowpacks::messages.update_help') }}</p>
</div>
