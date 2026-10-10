@extends('workflowpacks::layout')

@section('wp_content')
    @include('workflowpacks::partials.steps', ['step' => 4])
    <div class="alert alert-success">
        <strong>{{ __('workflowpacks::messages.result_ok') }}</strong>
        @foreach ($outcome['counts'] as $key => $n)
            <span class="wp-status">{{ $n }} {{ __('workflowpacks::messages.'.$key) }}</span>
        @endforeach
    </div>
    <table class="table table-condensed">
        @foreach ($outcome['results'] as $r)
            <tr>
                <td><code>{{ $r['rule'] }}</code></td>
                <td>{{ $plan['mailboxes'][$r['mailbox_id']]['name'] ?? '#'.$r['mailbox_id'] }}</td>
                <td>{{ __('workflowpacks::messages.'.$r['outcome']) }}</td>
                <td>@if ($r['workflow_id'] && Route::has('mailboxes.workflows.update'))<a href="{{ route('mailboxes.workflows.update', ['mailbox_id' => $r['mailbox_id'], 'id' => $r['workflow_id']]) }}">#{{ $r['workflow_id'] }}</a>@endif</td>
            </tr>
        @endforeach
    </table>
    @if (Route::has('mailboxes.workflows'))
        @foreach (array_keys($plan['mailboxes']) as $id)
            <a class="btn btn-default btn-sm" href="{{ route('mailboxes.workflows', ['mailbox_id' => $id]) }}">{{ __('workflowpacks::messages.open_workflows') }} — {{ $plan['mailboxes'][$id]['name'] }}</a>
        @endforeach
    @endif
    <a class="btn btn-link" href="{{ route('workflowpacks.index') }}">{{ __('workflowpacks::messages.menu') }}</a>
@endsection
