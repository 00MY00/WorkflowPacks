{{--
    Manage › Settings › Workflow Packs (included by resources/views/settings/view.blade.php through the "settings.view"
    filter). Same layout as RefreshGlobal's settings page: intro + links, sections with a grey band (subheader),
    switches with their help below, Save button, then the version / update block (its own form, outside the settings
    form). The settings form posts to FreeScout's own settings route, which saves the values in its options table.
--}}
@php $wp_U = '\Modules\WorkflowPacks\Services\Update\Updater'; @endphp
<div class="margin-top">@include('workflowpacks::partials.update_banner', ['back' => 'settings'])</div>
<p class="margin-top">{{ __('workflowpacks::messages.settings_intro') }}</p>
<p>
    <a href="{{ route('workflowpacks.index') }}">{{ __('workflowpacks::messages.menu') }}</a>
    · <a href="{{ route('workflowpacks.diagnostic') }}">{{ __('workflowpacks::messages.diagnostic') }}</a>
</p>

<form class="form-horizontal margin-top margin-bottom" method="POST" action="" autocomplete="off">
    {{ csrf_field() }}

    <h3 class="subheader">{{ __('workflowpacks::messages.auto_update') }}</h3>
    <div class="form-group">
        <label for="wp-auto-update" class="col-sm-2 control-label">{{ __('workflowpacks::messages.auto_update_label') }}</label>
        <div class="col-sm-6">
            {{-- the hidden field is posted when the switch is off (an unchecked checkbox is not sent) --}}
            <input type="hidden" name="settings[{{ $wp_U::OPTION }}]" value="0">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[{{ $wp_U::OPTION }}]" value="1" id="wp-auto-update" class="onoffswitch-checkbox" @if (!empty($settings[$wp_U::OPTION])) checked="checked" @endif>
                        <label class="onoffswitch-label" for="wp-auto-update"></label>
                    </div>
                </div>
            </div>
            <p class="form-help">{{ __('workflowpacks::messages.auto_update_help', ['time' => (string) config('workflowpacks.auto_update_time', '03:40')]) }}</p>
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.save') }}</button>
        </div>
    </div>
</form>

<h3 class="subheader">{{ __('workflowpacks::messages.update') }}</h3>
<div class="form-horizontal margin-bottom">
    <div class="form-group">
        <label class="col-sm-2 control-label">Workflow Packs</label>
        <div class="col-sm-8 form-control-static">
            @include('workflowpacks::partials.update_state', ['back' => 'settings'])
        </div>
    </div>
</div>
