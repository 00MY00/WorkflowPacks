@extends('workflowpacks::layout')

{{-- "Remove the pack": what will be deleted, mailbox by mailbox (Services/Plan/Remover). --}}
@section('wp_content')
    @php
        $wp_pack = $plan['pack'];
        $wp_visible = array_values(array_filter($plan['items'], function ($i) { return $i['status'] !== 'denied'; }));
        $wp_boxes = [];
        foreach ($wp_visible as $i) {
            $wp_boxes[$i['mailbox_id']] = ['name' => $i['mailbox'], 'on' => $i['status'] !== 'not_selected'];
        }
        $wp_todo = $plan['counts']['delete'] + $plan['counts']['missing'] + ($deleteModified ? $plan['counts']['modified'] : 0);
    @endphp

    <h3>{{ __('workflowpacks::messages.remove_title', ['name' => $wp_pack->name]) }} <code>{{ $wp_pack->pack_key }}</code></h3>
    @if ($warning)<div class="alert alert-warning">{{ $warning }}</div>@endif
    <p class="wp-muted">{{ __('workflowpacks::messages.remove_intro') }}</p>

    @if (count($wp_boxes) > 1)
        <form method="GET" action="{{ route('workflowpacks.remove', ['key' => $wp_pack->pack_key]) }}" class="form-inline margin-bottom">
            <input type="hidden" name="mailboxes_sent" value="1">
            <strong>{{ __('workflowpacks::messages.remove_mailboxes') }}</strong>
            @foreach ($wp_boxes as $id => $box)
                <label class="checkbox-inline"><input type="checkbox" name="mailboxes[]" value="{{ $id }}" @if ($box['on']) checked @endif> {{ $box['name'] }}</label>
            @endforeach
            <button type="submit" class="btn btn-default btn-xs">{{ __('workflowpacks::messages.remove_refresh') }}</button>
        </form>
    @endif

    <table class="table table-condensed">
        <tr>
            <th>{{ __('workflowpacks::messages.remove_rule') }}</th>
            <th>{{ __('workflowpacks::messages.remove_mailbox') }}</th>
            <th>{{ __('workflowpacks::messages.remove_state') }}</th>
        </tr>
        @foreach ($wp_visible as $i)
            <tr class="{{ $i['status'] === 'delete' || ($i['status'] === 'modified' && $deleteModified) ? 'danger' : '' }}">
                <td>{{ $i['name'] }} <code>{{ $i['rule'] }}</code></td>
                <td>{{ $i['mailbox'] }}</td>
                <td>
                    @if ($i['status'] === 'modified')
                        {{ __('workflowpacks::messages.rm_modified') }}
                    @else
                        {{ __('workflowpacks::messages.rm_'.$i['status']) }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
    @if ($plan['counts']['denied'])
        <p class="wp-muted">{{ __('workflowpacks::messages.rm_denied', ['count' => $plan['counts']['denied']]) }}</p>
    @endif

    <form method="POST" action="{{ route('workflowpacks.remove.confirm', ['key' => $wp_pack->pack_key]) }}" class="wp-form">
        {{ csrf_field() }}
        <input type="hidden" name="token" value="{{ $plan['token'] }}">
        <input type="hidden" name="mailboxes_sent" value="1">
        @foreach ($wp_boxes as $id => $box)
            @if ($box['on'])<input type="hidden" name="mailboxes[]" value="{{ $id }}">@endif
        @endforeach
        @if ($plan['counts']['modified'])
            <div class="checkbox">
                <label><input type="checkbox" name="delete_modified" value="1" @if ($deleteModified) checked @endif>
                    {{ __('workflowpacks::messages.remove_delete_modified', ['count' => $plan['counts']['modified']]) }}</label>
            </div>
        @endif
        <p class="alert alert-info">{{ __('workflowpacks::messages.remove_backup_note') }}</p>
        <button type="submit" class="btn btn-danger" @if (!$wp_todo && !$plan['counts']['modified']) disabled @endif
            data-wp-confirm="{{ __('workflowpacks::messages.remove_confirm_js') }}">{{ __('workflowpacks::messages.remove_confirm') }}</button>
        <a class="btn btn-link" href="{{ route('workflowpacks.index') }}">{{ __('workflowpacks::messages.back') }}</a>
        @if (!$wp_todo && !$plan['counts']['modified'])<span class="wp-muted">{{ __('workflowpacks::messages.remove_nothing') }}</span>@endif
    </form>
@endsection
