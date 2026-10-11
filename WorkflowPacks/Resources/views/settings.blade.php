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

<form class="form-horizontal margin-top margin-bottom" method="POST" action="" autocomplete="off" id="wp-settings-form">
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

    @php $wp_I = '\Modules\WorkflowPacks\Services\Imap\ImapFolders'; @endphp
    <h3 class="subheader">{{ __('workflowpacks::messages.imap_title') }}</h3>
    <div class="form-group">
        <label for="wp-imap" class="col-sm-2 control-label">{{ __('workflowpacks::messages.imap_label') }}</label>
        <div class="col-sm-6">
            <input type="hidden" name="settings[{{ $wp_I::OPTION_ENABLED }}]" value="0">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[{{ $wp_I::OPTION_ENABLED }}]" value="1" id="wp-imap" class="onoffswitch-checkbox" @if (!empty($settings[$wp_I::OPTION_ENABLED])) checked="checked" @endif>
                        <label class="onoffswitch-label" for="wp-imap"></label>
                    </div>
                </div>
            </div>
            <p class="form-help">{{ __('workflowpacks::messages.imap_help') }}</p>
        </div>
    </div>
    <div class="form-group">
        <label for="wp-imap-prefix" class="col-sm-2 control-label">{{ __('workflowpacks::messages.imap_prefix_label') }}</label>
        <div class="col-sm-6">
            <input type="text" name="settings[{{ $wp_I::OPTION_PREFIX }}]" id="wp-imap-prefix" class="form-control input-sized" maxlength="20" value="{{ $settings[$wp_I::OPTION_PREFIX] ?? $wp_I::prefix() }}">
            <p class="form-help">{{ __('workflowpacks::messages.imap_prefix_help') }}</p>
        </div>
    </div>
    <div class="form-group">
        <label for="wp-imap-parent" class="col-sm-2 control-label">{{ __('workflowpacks::messages.imap_parent_label') }}</label>
        <div class="col-sm-6">
            @php $wp_parent = $settings[$wp_I::OPTION_PARENT] ?? $wp_I::parentMode(); @endphp
            <select name="settings[{{ $wp_I::OPTION_PARENT }}]" id="wp-imap-parent" class="form-control input-sized">
                @foreach ($wp_I::PARENT_MODES as $wp_mode)
                    <option value="{{ $wp_mode }}" @if ($wp_parent === $wp_mode) selected @endif>{{ __('workflowpacks::messages.imap_parent_'.$wp_mode) }}</option>
                @endforeach
            </select>
            <p class="form-help">{{ __('workflowpacks::messages.imap_parent_help') }}</p>
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.save') }}</button>
            <span class="text-muted margin-left">{{ __('workflowpacks::messages.settings_autosave') }}</span>
        </div>
    </div>
</form>
{{--
    Switches saved as soon as they change (and the prefix when the field is left): no change lost for a forgotten click.
    "Saving…" with a spinning icon (FreeScout's glyphicon-spin) next to the changed field until the page comes back.
    workflowpacks.js: the same indicator on the buttons of the update block below.
--}}
<script {!! \Helper::cspNonceAttr() !!}>
    (function () {
        var form = document.getElementById('wp-settings-form');
        if (!form) { return; }
        var saving = {!! json_encode(__('workflowpacks::messages.saving'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        Array.prototype.forEach.call(form.querySelectorAll('input[type=checkbox], input[type=text], select'), function (input) {
            input.addEventListener('change', function () {
                var place = input.closest('.col-sm-6') || input.parentNode;
                var note = document.createElement('span');
                note.className = 'text-muted';
                note.innerHTML = '<i class="glyphicon glyphicon-refresh glyphicon-spin" aria-hidden="true"></i> ';
                note.appendChild(document.createTextNode(saving));
                place.insertBefore(note, place.querySelector('.form-help'));
                Array.prototype.forEach.call(form.querySelectorAll('input'), function (i) { if (i !== input && i.type !== 'hidden') { i.readOnly = true; } });
                form.submit();
            });
        });
    })();
</script>
<script src="{{ asset(\Module::getPublicPath('workflowpacks').'/js/workflowpacks.js') }}?v={{ \Modules\WorkflowPacks\Services\Update\Updater::currentVersion() }}" {!! \Helper::cspNonceAttr() !!}></script>

{{-- automatic clean-up of the managed e-mail folders: set from the side panel of a folder in the diagram --}}
@php $wp_R = \Modules\WorkflowPacks\Services\Imap\Retention::all(); @endphp
<h3 class="subheader">{{ __('workflowpacks::messages.retention_list') }}</h3>
<div class="form-horizontal margin-bottom">
    <div class="form-group">
        <div class="col-sm-8 col-sm-offset-2">
            @forelse ($wp_R as $wp_r)
                <form method="POST" action="{{ route('workflowpacks.retention.remove') }}" class="margin-bottom-10">
                    {{ csrf_field() }}
                    <input type="hidden" name="folder" value="{{ $wp_r->folder }}">
                    <i class="glyphicon glyphicon-trash" aria-hidden="true"></i> <strong>{{ \Modules\WorkflowPacks\Services\Imap\FolderPath::label($wp_r->folder, \Modules\WorkflowPacks\Services\Imap\ImapFolders::prefix()) }}</strong> —
                    {{ __('workflowpacks::messages.'.($wp_r->mode === 'delete' ? 'retention_row_delete' : 'retention_row_trash'), ['delay' => \Modules\WorkflowPacks\Services\Pack\Timer::label(['number' => (int) $wp_r->number, 'metric' => $wp_r->metric])]) }}
                    <button type="submit" class="btn btn-link btn-xs">{{ __('workflowpacks::messages.retention_remove_btn') }}</button>
                </form>
            @empty
                <p class="text-muted">{{ __('workflowpacks::messages.retention_none') }}</p>
            @endforelse
            <p class="form-help">{{ __('workflowpacks::messages.retention_settings_help') }}</p>
        </div>
    </div>
</div>

<h3 class="subheader">{{ __('workflowpacks::messages.update') }}</h3>
<div class="form-horizontal margin-bottom">
    <div class="form-group">
        <label class="col-sm-2 control-label">Workflow Packs</label>
        <div class="col-sm-8 form-control-static">
            @include('workflowpacks::partials.update_state', ['back' => 'settings'])
        </div>
    </div>
</div>
