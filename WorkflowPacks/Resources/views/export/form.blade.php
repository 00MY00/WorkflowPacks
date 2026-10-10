@extends('workflowpacks::layout')

@section('wp_content')
    <h3>{{ __('workflowpacks::messages.export_title') }}</h3>
    @include('workflowpacks::partials.problems', ['problems' => $errors_list])

    @if ($packs->count())
        <form method="POST" action="{{ route('workflowpacks.export.preview') }}" class="wp-form panel panel-default">
            <div class="panel-heading"><strong>{{ __('workflowpacks::messages.export_mode_pack') }}</strong></div>
            <div class="panel-body form-inline">
                {{ csrf_field() }}
                <input type="hidden" name="mode" value="pack">
                <select name="pack_key" class="form-control">
                    @foreach ($packs as $pack)
                        <option value="{{ $pack->pack_key }}">{{ $pack->name }} ({{ $pack->pack_key }})</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-default">{{ __('workflowpacks::messages.export_preview') }}</button>
            </div>
        </form>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><strong>{{ __('workflowpacks::messages.export_mode_wf') }}</strong></div>
        <div class="panel-body">
            <form method="GET" action="{{ route('workflowpacks.export') }}" class="form-inline wp-inline">
                <label>{{ __('workflowpacks::messages.filter_mailbox') }}</label>
                <select name="mailbox_id" class="form-control input-sm wp-autosubmit">
                    @foreach ($mailboxes as $mb)
                        <option value="{{ $mb->id }}" @if ($mailbox && $mailbox->id == $mb->id) selected @endif>{{ $mb->name }}</option>
                    @endforeach
                </select>
            </form>

            <form method="POST" action="{{ route('workflowpacks.export.preview') }}" class="wp-form">
                {{ csrf_field() }}
                <input type="hidden" name="mode" value="workflows">
                <input type="hidden" name="mailbox_id" value="{{ $mailbox ? $mailbox->id : '' }}">
                <h4>{{ __('workflowpacks::messages.export_select') }}</h4>
                @forelse ($workflows as $wf)
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="workflows[]" value="{{ $wf->id }}" @if (in_array($wf->id, (array) ($old['workflows'] ?? []))) checked @endif>
                            {{ $wf->name }}
                            <span class="label label-default">{{ __('workflowpacks::messages.'.\Modules\WorkflowPacks\Services\Workflows\Bridge::typeName($wf->type)) }}</span>
                            <span class="wp-muted">{{ __('workflowpacks::messages.'.($wf->active ? 'active' : 'inactive')) }}</span>
                            @if ($linked->has($wf->id))<span class="label label-info">{{ __('workflowpacks::messages.from_pack', ['pack' => $linked->get($wf->id)->pack_key]) }}</span>@endif
                        </label>
                    </div>
                @empty
                    <p class="wp-muted">{{ __('workflowpacks::messages.no_workflows') }}</p>
                @endforelse

                <h4>{{ __('workflowpacks::messages.export_meta') }}</h4>
                <div class="row">
                    <div class="col-sm-4 form-group"><label>{{ __('workflowpacks::messages.export_key') }}</label><input name="key" class="form-control" value="{{ $old['key'] ?? '' }}" pattern="[a-z0-9][a-z0-9._-]{0,99}" required></div>
                    <div class="col-sm-4 form-group"><label>{{ __('workflowpacks::messages.export_name') }}</label><input name="name" class="form-control" value="{{ $old['name'] ?? '' }}" required></div>
                    <div class="col-sm-4 form-group"><label>{{ __('workflowpacks::messages.version') }}</label><input name="pack_version" class="form-control" value="{{ $old['pack_version'] ?? '1.0.0' }}"></div>
                </div>
                <div class="form-group"><label>{{ __('workflowpacks::messages.export_description') }}</label><input name="description" class="form-control" value="{{ $old['description'] ?? '' }}"></div>
                <div class="form-group"><label>{{ __('workflowpacks::messages.export_requires') }}</label><input name="requires" class="form-control" value="{{ $old['requires'] ?? '' }}"></div>

                <h4>{{ __('workflowpacks::messages.replacements') }}</h4>
                <p class="wp-muted">{{ __('workflowpacks::messages.replacements_help') }}</p>
                <table class="table table-condensed wp-replacements">
                    <tr><th>{{ __('workflowpacks::messages.replace_text') }}</th><th>{{ __('workflowpacks::messages.replace_by') }}</th><th></th><th></th></tr>
                    @for ($i = 0; $i < 4; $i++)
                        <tr>
                            <td><input name="replace_text[{{ $i }}]" class="form-control input-sm" value="{{ $old['replace_text'][$i] ?? '' }}" placeholder="{{ $i === 0 && $mailbox ? $mailbox->email : '' }}"></td>
                            <td>
                                <select name="replace_var[{{ $i }}]" class="form-control input-sm">
                                    @foreach (['mailbox.email', 'mailbox.name', 'mailbox.id', 'param'] as $v)
                                        <option value="{{ $v }}" @if (($old['replace_var'][$i] ?? '') === $v) selected @endif>{{ $v === 'param' ? __('workflowpacks::messages.new_param') : '{'.'{'.$v.'}'.'}' }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input name="replace_param[{{ $i }}]" class="form-control input-sm" placeholder="{{ __('workflowpacks::messages.param_key') }}" value="{{ $old['replace_param'][$i] ?? '' }}"></td>
                            <td>
                                <select name="replace_type[{{ $i }}]" class="form-control input-sm">
                                    @foreach (\Modules\WorkflowPacks\Services\Pack\PackValidator::PARAM_TYPES as $t)
                                        <option value="{{ $t }}" @if (($old['replace_type'][$i] ?? '') === $t) selected @endif>{{ $t }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endfor
                </table>
                <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.export_preview') }}</button>
            </form>
        </div>
    </div>
@endsection
