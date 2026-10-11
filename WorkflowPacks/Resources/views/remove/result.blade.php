@extends('workflowpacks::layout')

@section('wp_content')
    <h3>{{ __('workflowpacks::messages.remove_title', ['name' => $plan['pack']->name]) }} <code>{{ $plan['pack']->pack_key }}</code></h3>
    <div class="alert alert-success">
        <strong>{{ __('workflowpacks::messages.remove_done') }}</strong>
        {{ __('workflowpacks::messages.remove_result', ['deleted' => $outcome['deleted'], 'kept' => $outcome['kept'], 'cleaned' => $outcome['cleaned']]) }}
        <br>{{ __('workflowpacks::messages.'.($outcome['pack_removed'] ? 'remove_pack_gone' : 'remove_pack_left')) }}
    </div>
    @if ($outcome['backup_id'])
        <p>
            <a class="btn btn-default btn-sm" href="{{ route('workflowpacks.backup', ['id' => $outcome['backup_id'], 'part' => 'pack']) }}"><i class="glyphicon glyphicon-download-alt"></i> {{ __('workflowpacks::messages.backup_pack') }}</a>
            <a class="btn btn-default btn-sm" href="{{ route('workflowpacks.backup', ['id' => $outcome['backup_id']]) }}"><i class="glyphicon glyphicon-download-alt"></i> {{ __('workflowpacks::messages.backup_full') }}</a>
        </p>
        <p class="wp-muted">{{ __('workflowpacks::messages.backup_help') }}</p>
    @endif
    <a class="btn btn-link" href="{{ route('workflowpacks.index') }}">{{ __('workflowpacks::messages.menu') }}</a>
@endsection
