{{--
    Banner shown to administrators when a newer version of Workflow Packs is available (found by the daily check of the
    scheduler or by "Check for updates"). "Update now" asks the scheduler to install it within a minute, with the usual
    checks (SHA-256, backup, automatic rollback). Parameter: back ('settings' or '').
--}}
@php
    $wp_U = '\Modules\WorkflowPacks\Services\Update\Updater';
    $wp_state = Auth::user() && Auth::user()->isAdmin() ? $wp_U::state() : '';
    $wp_status = $wp_state === 'available' ? $wp_U::status() : [];
@endphp
@if ($wp_state === 'available' && !empty($wp_status['latest']))
    <div class="alert alert-info wp-update-banner" role="status">
        <form method="POST" action="{{ route('workflowpacks.update_now') }}" class="pull-right">
            {{ csrf_field() }}
            @if (!empty($back))<input type="hidden" name="back" value="{{ $back }}">@endif
            <button type="submit" class="btn btn-primary btn-sm"><i class="glyphicon glyphicon-download-alt"></i> {{ __('workflowpacks::messages.update_now') }}</button>
        </form>
        <strong>{{ __('workflowpacks::messages.update_banner', ['latest' => $wp_status['latest'], 'current' => $wp_U::currentVersion()]) }}</strong>
        <div class="wp-muted">{{ __('workflowpacks::messages.update_banner_help') }}</div>
    </div>
@elseif (in_array($wp_state, ['requested', 'running'], true))
    <div class="alert alert-warning wp-update-banner" role="status">{{ __('workflowpacks::messages.update_state_'.$wp_state) }} {{ __('workflowpacks::messages.update_auto_reload') }}</div>
    <script {!! \Helper::cspNonceAttr() !!}>setTimeout(function () { window.location.reload(); }, 10000);</script>
@endif
