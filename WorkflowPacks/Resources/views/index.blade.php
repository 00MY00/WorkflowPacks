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

    @forelse ($packs as $entry)
        <div class="panel panel-default wp-pack">
            <div class="panel-heading">
                <strong>{{ $entry['pack']->name }}</strong>
                <code>{{ $entry['pack']->pack_key }}</code>
                @if ($entry['pack']->pack_version !== '')<span class="label label-default">{{ __('workflowpacks::messages.version') }} {{ $entry['pack']->pack_version }}</span>@endif
                <span class="wp-muted">{{ count($entry['rules']) }} {{ __('workflowpacks::messages.rules') }}</span>
                @if ($compat['state'] !== 'blocking')
                    <a class="btn btn-default btn-xs pull-right" href="{{ route('workflowpacks.reimport', ['key' => $entry['pack']->pack_key]) }}">{{ __('workflowpacks::messages.reimport') }}</a>
                @endif
            </div>
            <div class="panel-body">
                @if ($entry['pack']->description)<p class="wp-muted">{{ $entry['pack']->description }}</p>@endif
                @foreach ($entry['rules'] as $r)
                    @include('workflowpacks::partials.rule_schema', ['rule' => $r['rule'], 'states' => $r['states'], 'mailboxId' => null])
                @endforeach
            </div>
        </div>
    @empty
        <div class="wp-empty">{{ __('workflowpacks::messages.no_packs') }}</div>
    @endforelse
@endsection
