@extends('workflowpacks::layout')

@section('wp_content')
    <p class="wp-muted">{{ __('workflowpacks::messages.intro') }}</p>
    @if ($compat['state'] === 'blocking')
        @include('workflowpacks::partials.blocked_inline')
    @endif
    <div class="wp-toolbar">
        <a class="btn btn-primary" href="{{ route('workflowpacks.import', $filter ? ['mailbox_id' => $filter] : []) }}">{{ __('workflowpacks::messages.import') }}</a>
        <a class="btn btn-default" href="{{ route('workflowpacks.export', $filter ? ['mailbox_id' => $filter] : []) }}">{{ __('workflowpacks::messages.export') }}</a>
        @if ($mailboxes->count() > 1)
            <form method="GET" action="{{ route('workflowpacks.index') }}" class="wp-inline">
                <select name="mailbox_id" class="form-control input-sm wp-autosubmit">
                    <option value="">{{ __('workflowpacks::messages.all_mailboxes') }}</option>
                    @foreach ($mailboxes as $mailbox)
                        <option value="{{ $mailbox->id }}" @if ($filter === (int) $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    @if ($packs && $compat['state'] !== 'blocking')
        {{-- several rules at once: the boxes of the rules below belong to this form (form="wp-bulk") --}}
        <form id="wp-bulk" method="POST" action="{{ route('workflowpacks.rules.toggle') }}" class="wp-bulk">
            {{ csrf_field() }}
            @if ($filter)<input type="hidden" name="mailbox_id" value="{{ $filter }}">@endif
            <label class="wp-bulk-all"><input type="checkbox" data-wp-check-all=".wp-bulk-check"> {{ __('workflowpacks::messages.select_all') }}</label>
            <span class="wp-bulk-count"><b data-wp-bulk-count>0</b> {{ __('workflowpacks::messages.selected_rules') }}</span>
            <button type="submit" name="active" value="1" class="btn btn-success btn-xs" data-wp-bulk-action>{{ __('workflowpacks::messages.enable_selected') }}</button>
            <button type="submit" name="active" value="0" class="btn btn-default btn-xs" data-wp-bulk-action>{{ __('workflowpacks::messages.disable_selected') }}</button>
            <button type="submit" name="previous" value="1" class="btn btn-default btn-xs" data-wp-bulk-action title="{{ __('workflowpacks::messages.previous_help') }}"
                data-wp-confirm="{{ __('workflowpacks::messages.previous_confirm') }}">{{ __('workflowpacks::messages.previous_selected_on') }}</button>
            <button type="submit" name="previous" value="0" class="btn btn-default btn-xs" data-wp-bulk-action>{{ __('workflowpacks::messages.previous_selected_off') }}</button>
        </form>
    @endif

    @forelse ($packs as $entry)
        <div class="panel panel-default wp-pack">
            <div class="panel-heading">
                @if ($compat['state'] !== 'blocking')
                    <input type="checkbox" class="wp-bulk-pack" data-wp-check-all=".wp-bulk-check" data-wp-scope=".wp-pack" title="{{ __('workflowpacks::messages.select_pack') }}" aria-label="{{ __('workflowpacks::messages.select_pack') }}">
                @endif
                <strong>{{ $entry['pack']->name }}</strong>
                <code>{{ $entry['pack']->pack_key }}</code>
                @if ($entry['pack']->pack_version !== '')<span class="label label-default">{{ __('workflowpacks::messages.version') }} {{ $entry['pack']->pack_version }}</span>@endif
                <span class="wp-muted">{{ count($entry['rules']) }} {{ __('workflowpacks::messages.rules') }}</span>
                @if ($compat['state'] !== 'blocking')
                    <a class="btn btn-link btn-xs pull-right text-danger" href="{{ route('workflowpacks.remove', ['key' => $entry['pack']->pack_key]) }}">{{ __('workflowpacks::messages.remove') }}</a>
                    <a class="btn btn-default btn-xs pull-right" href="{{ route('workflowpacks.reimport', ['key' => $entry['pack']->pack_key]) }}">{{ __('workflowpacks::messages.reimport') }}</a>
                @endif
            </div>
            <div class="panel-body">
                @if ($entry['pack']->description)<p class="wp-muted">{{ $entry['pack']->description }}</p>@endif
                @foreach ($entry['rules'] as $r)
                    @include('workflowpacks::partials.rule_schema', ['rule' => $r['rule'], 'states' => $r['states'], 'mailboxId' => null, 'toggles' => $compat['state'] !== 'blocking', 'filter' => $filter])
                @endforeach
            </div>
        </div>
    @empty
        <div class="wp-empty">{{ __('workflowpacks::messages.no_packs') }}</div>
    @endforelse
@endsection
