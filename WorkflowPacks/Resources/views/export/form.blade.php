@extends('workflowpacks::layout')

@section('wp_content')
    <h3>{{ __('workflowpacks::messages.export_title') }}</h3>
    @include('workflowpacks::partials.problems', ['problems' => $errors_list])

    @php
        $wp_target = $old['target'] ?? 'new';
        if ($wp_target === 'existing' && !$targets->count()) {
            $wp_target = 'new';
        }
        $wp_names = $mailboxes->pluck('name', 'id');
        $wp_custom = !empty($old['mode']) || !$packs->count();
    @endphp

    @foreach ($packs as $pack)
        @php
            $wp_new = $pending[$pack->pack_key]['new'] ?? [];
            $wp_changed = $pending[$pack->pack_key]['modified'] ?? [];
            $wp_can = $targets->contains('pack_key', $pack->pack_key);
            $wp_fresh = ($old['pack_key'] ?? '') === $pack->pack_key;
        @endphp
        <div class="wp-pack-card @if ($wp_fresh) is-fresh @endif">
            <div class="wp-pack-card-head">
                <div>
                    <strong>{{ $pack->name }}</strong>
                    <span class="wp-muted">v{{ $pack->pack_version }}</span>
                </div>
                <form method="POST" action="{{ route('workflowpacks.export.download') }}" class="wp-inline" data-wp-busy="off">
                    {{ csrf_field() }}
                    <input type="hidden" name="mode" value="pack">
                    <input type="hidden" name="pack_key" value="{{ $pack->pack_key }}">
                    <button type="submit" class="btn {{ $wp_fresh || (!$wp_new && !$wp_changed) ? 'btn-primary' : 'btn-default' }}">{{ __('workflowpacks::messages.pack_download', ['version' => $pack->pack_version]) }}</button>
                </form>
            </div>
            @if (($wp_new || $wp_changed) && $wp_can)
                <form method="POST" action="{{ route('workflowpacks.export.update_pack') }}" class="wp-pack-card-update">
                    {{ csrf_field() }}
                    <input type="hidden" name="pack_key" value="{{ $pack->pack_key }}">
                    <p>{{ __('workflowpacks::messages.pack_pending', ['count' => count($wp_new) + count($wp_changed)]) }}</p>
                    @foreach ([['new', $wp_new], ['modified', $wp_changed]] as $wp_group)
                        @foreach ($wp_group[1] as $wf)
                            <label class="wp-pack-rule">
                                <input type="checkbox" name="workflows[]" value="{{ $wf->id }}" checked>
                                {{ $wf->name }}
                                <span class="label {{ $wp_group[0] === 'new' ? 'label-success' : 'label-warning' }}">{{ __('workflowpacks::messages.pack_state_'.$wp_group[0]) }}</span>
                                <span class="wp-muted">{{ $wp_names[$wf->mailbox_id] ?? '' }}</span>
                            </label>
                        @endforeach
                    @endforeach
                    <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.pack_update_button', ['version' => \Modules\WorkflowPacks\Services\Export\Grouper::bump($pack->pack_version)]) }}</button>
                </form>
            @elseif (!$wp_new && !$wp_changed)
                <p class="wp-muted wp-pack-ok">✓ {{ __('workflowpacks::messages.pack_up_to_date') }}</p>
            @endif
        </div>
    @endforeach

    <details class="panel panel-default wp-custom-export" @if ($wp_custom) open @endif>
        <summary class="panel-heading"><strong>{{ $packs->count() ? __('workflowpacks::messages.export_custom') : __('workflowpacks::messages.export_mode_wf') }}</strong></summary>
        <div class="panel-body">
            <form method="GET" action="{{ route('workflowpacks.export') }}" class="form-inline wp-inline">
                <label>{{ __('workflowpacks::messages.filter_mailbox') }}</label>
                <select name="mailbox_id" class="form-control input-sm wp-autosubmit">
                    <option value="0">{{ __('workflowpacks::messages.all_mailboxes') }}</option>
                    @foreach ($mailboxes as $mb)
                        <option value="{{ $mb->id }}" @if ($mailbox && $mailbox->id == $mb->id) selected @endif>{{ $mb->name }}</option>
                    @endforeach
                </select>
            </form>

            <form method="POST" action="{{ route('workflowpacks.export.preview') }}" class="wp-form wp-export">
                {{ csrf_field() }}
                <input type="hidden" name="mode" value="workflows">
                <input type="hidden" name="mailbox_id" value="{{ $mailbox ? $mailbox->id : 0 }}">

                <h4>{{ __('workflowpacks::messages.export_select') }}</h4>
                @if ($workflows->count())
                    <label class="wp-check-all"><input type="checkbox" data-wp-check-all=".wp-export-rule" @if (count((array) ($old['workflows'] ?? [])) >= $workflows->count()) checked @endif> <strong>{{ __('workflowpacks::messages.select_all_rules', ['count' => $workflows->count()]) }}</strong></label>
                @endif
                @forelse ($workflows->groupBy('mailbox_id') as $mailboxId => $list)
                    @if (!$mailbox)<h5 class="wp-export-mailbox">{{ optional($mailboxes->firstWhere('id', $mailboxId))->name }}</h5>@endif
                    @foreach ($list as $wf)
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" class="wp-export-rule" name="workflows[]" value="{{ $wf->id }}" @if (in_array($wf->id, (array) ($old['workflows'] ?? []))) checked @endif>
                                {{ $wf->name }}
                                <span class="label label-default">{{ __('workflowpacks::messages.'.\Modules\WorkflowPacks\Services\Workflows\Bridge::typeName($wf->type)) }}</span>
                                <span class="wp-muted">{{ __('workflowpacks::messages.'.($wf->active ? 'active' : 'inactive')) }}</span>
                                @if ($linked->has($wf->id))<span class="label label-info">{{ __('workflowpacks::messages.from_pack', ['pack' => $linked->get($wf->id)->pack_key]) }}</span>@endif
                            </label>
                        </div>
                    @endforeach
                @empty
                    <p class="wp-muted">{{ __('workflowpacks::messages.no_workflows') }}</p>
                @endforelse

                <h4>{{ __('workflowpacks::messages.export_target') }}</h4>
                <div class="radio"><label><input type="radio" name="target" value="new" @if ($wp_target === 'new') checked @endif> <strong>{{ __('workflowpacks::messages.target_new') }}</strong></label></div>
                <div class="wp-target-fields">
                    <div class="row">
                        <div class="col-sm-4 form-group"><label>{{ __('workflowpacks::messages.export_name') }}</label><input name="name" class="form-control" value="{{ $old['name'] ?? '' }}"></div>
                        <div class="col-sm-4 form-group"><label>{{ __('workflowpacks::messages.export_key') }}</label><input name="key" class="form-control" value="{{ $old['key'] ?? '' }}" pattern="[a-z0-9][a-z0-9._-]{0,99}" placeholder="{{ __('workflowpacks::messages.export_key_auto') }}"></div>
                        <div class="col-sm-4 form-group"><label>{{ __('workflowpacks::messages.version') }}</label><input name="pack_version" class="form-control" value="{{ $old['pack_version'] ?? '1.0.0' }}"></div>
                    </div>
                    <div class="form-group"><label>{{ __('workflowpacks::messages.export_description') }}</label><input name="description" class="form-control" value="{{ $old['description'] ?? '' }}"></div>
                    <div class="form-group"><label>{{ __('workflowpacks::messages.export_requires') }}</label><input name="requires" class="form-control" value="{{ $old['requires'] ?? '' }}"></div>
                </div>
                @if ($targets->count())
                    <div class="radio"><label><input type="radio" name="target" value="existing" @if ($wp_target === 'existing') checked @endif> <strong>{{ __('workflowpacks::messages.target_existing') }}</strong></label></div>
                    <div class="row wp-target-fields">
                        <div class="col-sm-8 form-group">
                            <select name="target_pack" class="form-control" aria-label="{{ __('workflowpacks::messages.target_existing') }}">
                                @foreach ($targets as $pack)
                                    <option value="{{ $pack->pack_key }}" @if (($old['target_pack'] ?? '') === $pack->pack_key) selected @endif>{{ $pack->name }} ({{ $pack->pack_key }}, {{ $pack->pack_version }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-4 form-group"><input name="target_version" class="form-control" value="{{ $old['target_version'] ?? '' }}" placeholder="{{ __('workflowpacks::messages.target_version_auto') }}" aria-label="{{ __('workflowpacks::messages.version') }}"></div>
                    </div>
                @endif

                <details class="wp-replacements-box" @if (array_filter((array) ($old['replace_text'] ?? []))) open @endif>
                    <summary><strong>{{ __('workflowpacks::messages.replacements') }}</strong> <span class="wp-muted">({{ __('workflowpacks::messages.optional') }})</span></summary>
                    <p class="wp-muted">{{ __('workflowpacks::messages.replacements_help') }}</p>
                    <table class="table table-condensed wp-replacements">
                        <tr><th>{{ __('workflowpacks::messages.replace_text') }}</th><th>{{ __('workflowpacks::messages.replace_by') }}</th><th></th><th></th></tr>
                        @for ($i = 0; $i < 4; $i++)
                            <tr>
                                <td><input name="replace_text[{{ $i }}]" class="form-control input-sm" value="{{ $old['replace_text'][$i] ?? '' }}" placeholder="{{ $i === 0 && $mailbox ? $mailbox->email : '' }}"></td>
                                <td>
                                    <select name="replace_var[{{ $i }}]" class="form-control input-sm">
                                        @foreach (['mailbox.email', 'mailbox.name', 'mailbox.id', 'param', 'resource'] as $v)
                                            <option value="{{ $v }}" @if (($old['replace_var'][$i] ?? '') === $v) selected @endif>{{ $v === 'param' ? __('workflowpacks::messages.new_param') : ($v === 'resource' ? __('workflowpacks::messages.new_resource') : '{'.'{'.$v.'}'.'}') }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input name="replace_param[{{ $i }}]" class="form-control input-sm" placeholder="{{ __('workflowpacks::messages.param_key') }}" value="{{ $old['replace_param'][$i] ?? '' }}"></td>
                                <td>
                                    <select name="replace_type[{{ $i }}]" class="form-control input-sm">
                                        <optgroup label="{{ __('workflowpacks::messages.params') }}">
                                            @foreach (\Modules\WorkflowPacks\Services\Pack\PackValidator::PARAM_TYPES as $t)
                                                <option value="{{ $t }}" @if (($old['replace_type'][$i] ?? '') === $t) selected @endif>{{ $t }}</option>
                                            @endforeach
                                        </optgroup>
                                        <optgroup label="{{ __('workflowpacks::messages.resources') }}">
                                            @foreach (\Modules\WorkflowPacks\Services\Pack\PackValidator::RESOURCE_TYPES as $t)
                                                @if (!in_array($t, \Modules\WorkflowPacks\Services\Pack\PackValidator::PARAM_TYPES, true))<option value="{{ $t }}" @if (($old['replace_type'][$i] ?? '') === $t) selected @endif>{{ $t }}</option>@endif
                                            @endforeach
                                        </optgroup>
                                    </select>
                                </td>
                            </tr>
                        @endfor
                    </table>
                </details>
                <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.export_preview') }}</button>
            </form>
        </div>
    </details>

@endsection
