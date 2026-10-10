@extends('workflowpacks::layout')

@section('wp_content')
    @include('workflowpacks::partials.steps', ['step' => 2])
    @include('workflowpacks::partials.problems', ['problems' => $problems])
    <div class="alert alert-success">
        <strong>{{ __('workflowpacks::messages.pack_valid') }}</strong> :
        {{ $pack['name'] }} <code>{{ $pack['key'] }}</code>
        @if ($pack['pack_version'] !== '') — {{ __('workflowpacks::messages.version') }} {{ $pack['pack_version'] }}@endif
        — {{ count($pack['workflows']) }} {{ __('workflowpacks::messages.rules') }}
        @if ($existing)<div>{{ __('workflowpacks::messages.already_imported', ['version' => $existing->pack_version ?: '—']) }}</div>@endif
    </div>
    @if ($pack['description'] !== '')<p class="wp-muted">{{ $pack['description'] }}</p>@endif

    <form method="POST" action="{{ route('workflowpacks.import.preview') }}" class="wp-form">
        {{ csrf_field() }}
        <input type="hidden" name="pack_b64" value="{{ base64_encode($json) }}">

        @if ($modules)
            <h4>{{ __('workflowpacks::messages.dependencies') }}</h4>
            <ul class="wp-deps">
                @foreach ($modules as $alias => $active)
                    <li class="{{ $active ? 'text-success' : 'text-danger' }}"><code>{{ $alias }}</code> — {{ __('workflowpacks::messages.'.($active ? 'module_active' : 'module_missing')) }}</li>
                @endforeach
            </ul>
        @endif

        <h4>{{ __('workflowpacks::messages.mailboxes') }}</h4>
        <p class="wp-muted">{{ __('workflowpacks::messages.mailboxes_help') }}</p>
        <div class="wp-mailboxes">
            @foreach ($mailboxes as $mailbox)
                <label class="wp-mailbox">
                    <input type="checkbox" name="mailboxes[]" value="{{ $mailbox->id }}" @if (in_array((int) $mailbox->id, $selected, true)) checked @endif>
                    <span>{{ $mailbox->name }}</span> <small class="wp-muted">{{ $mailbox->email }}</small>
                </label>
            @endforeach
        </div>

        <h4>{{ __('workflowpacks::messages.params') }}</h4>
        @forelse ($pack['params'] as $param)
            <div class="form-group">
                <label for="wp-p-{{ $param['key'] }}">{{ $param['label'] }} <code>{{ '{'.'{param.'.$param['key'].'}'.'}' }}</code>
                    @if ($param['required'])<span class="label label-danger">{{ __('workflowpacks::messages.required') }}</span>@endif
                    @if ($param['sensitive'])<span class="label label-warning">{{ __('workflowpacks::messages.sensitive_param') }}</span>@endif
                </label>
                <input id="wp-p-{{ $param['key'] }}" name="params[{{ $param['key'] }}]" class="form-control"
                       type="{{ $param['sensitive'] ? 'password' : ($param['type'] === 'email' ? 'email' : (in_array($param['type'], ['integer', 'user', 'mailbox'], true) ? 'number' : 'text')) }}"
                       value="{{ $param['sensitive'] ? '' : ($params[$param['key']] ?? $param['default'] ?? '') }}" autocomplete="off">
                @if ($param['description'] !== '')<p class="help-block">{{ $param['description'] }} ({{ $param['type'] }})</p>@endif
            </div>
        @empty
            <p class="wp-muted">{{ __('workflowpacks::messages.no_params') }}</p>
        @endforelse

        <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.preview_btn') }}</button>
    </form>

    <h4 class="wp-section">{{ __('workflowpacks::messages.rules') }}</h4>
    @foreach ($pack['workflows'] as $rule)
        @include('workflowpacks::partials.rule_schema', ['rule' => $rule, 'mailboxId' => null])
    @endforeach
@endsection
