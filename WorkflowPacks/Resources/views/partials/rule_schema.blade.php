{{-- Schematic view of one rule: IF (AND of OR groups) THEN (actions), with variables and sensitive actions marked.
     $rule: pack-shaped rule; $mailboxId: optional (labels from the catalog); $states: optional mailbox chips. --}}
@php
    $groups = \Modules\WorkflowPacks\Services\Ui::groups($rule['conditions'] ?? null);
    $mb = $mailboxId ?? null;
@endphp
<div class="wp-rule">
    <div class="wp-rule-head">
        <span class="wp-rule-name">{!! \Modules\WorkflowPacks\Services\Ui::value($rule['name']) !!}</span>
        <span class="label {{ ($rule['type'] ?? 'automatic') === 'manual' ? 'label-default' : 'label-info' }}">{{ __('workflowpacks::messages.'.(($rule['type'] ?? 'automatic') === 'manual' ? 'manual' : 'automatic')) }}</span>
        @foreach (($rule['requires'] ?? []) as $module)
            <span class="label label-warning">{{ __('workflowpacks::messages.requires') }} {{ $module }}</span>
        @endforeach
        @if (!empty($rule['mailboxes']))
            <span class="label label-primary">{{ \Modules\WorkflowPacks\Services\Pack\MailboxSelector::describe($rule['mailboxes']) }}</span>
        @endif
        @if (!empty($rule['settings']['apply_to_prev']))
            <span class="label label-default">{{ __('workflowpacks::messages.apply_to_prev') }}</span>
        @endif
        @if (($rule['settings']['max_executions'] ?? 1) > 1)
            <span class="label label-default">{{ __('workflowpacks::messages.max_executions') }} : {{ (int) $rule['settings']['max_executions'] }}</span>
        @endif
    </div>
    @if (!empty($rule['description']))
        <div class="wp-rule-desc">{{ $rule['description'] }}</div>
    @endif
    <div class="wp-flow">
        <div class="wp-flow-if">
            <span class="wp-flow-label">{{ __('workflowpacks::messages.if') }}</span>
            @if (!$groups)
                <span class="wp-muted">{{ __('workflowpacks::messages.manual_no_cond') }}</span>
            @endif
            @foreach ($groups as $g => $group)
                @if ($g > 0)<span class="wp-op wp-op-and">{{ __('workflowpacks::messages.and') }}</span>@endif
                <span class="wp-group">
                    @foreach ($group as $c => $condition)
                        @if ($c > 0)<span class="wp-op wp-op-or">{{ __('workflowpacks::messages.or') }}</span>@endif
                        <span class="wp-cond">
                            <b>{{ \Modules\WorkflowPacks\Services\Ui::label('conditions', $condition['type'] ?? '', $mb) }}</b>
                            <i>{{ $condition['operator'] ?? '' }}</i>
                            @if (array_key_exists('value', $condition))<code>{!! \Modules\WorkflowPacks\Services\Ui::value($condition['value']) !!}</code>@endif
                        </span>
                    @endforeach
                </span>
            @endforeach
        </div>
        <div class="wp-flow-then">
            <span class="wp-flow-label">{{ __('workflowpacks::messages.then') }}</span>
            @foreach (($rule['actions'] ?? []) as $action)
                @php $sensitive = \Modules\WorkflowPacks\Services\Ui::sensitive($action['type'] ?? ''); @endphp
                <span class="wp-action @if ($sensitive) wp-sensitive @endif">
                    <b>{{ \Modules\WorkflowPacks\Services\Ui::label('actions', $action['type'] ?? '', $mb) }}</b>
                    @if (array_key_exists('value', $action))<code>{!! \Modules\WorkflowPacks\Services\Ui::value($action['value']) !!}</code>@endif
                    @if ($sensitive)<span class="label label-danger">⚠ {{ __('workflowpacks::messages.sensitive_'.$sensitive) }}</span>@endif
                </span>
            @endforeach
        </div>
    </div>
    @if (isset($states))
        <div class="wp-chips">
            @forelse ($states as $s)
                <span class="wp-chip wp-chip-{{ $s['state'] }}" title="{{ __('workflowpacks::messages.version') }} {{ $s['version'] }}">
                    @if (!empty($toggles) && !empty($s['workflow_id']))
                        <input type="checkbox" form="wp-bulk" name="workflow_ids[]" value="{{ $s['workflow_id'] }}" class="wp-bulk-check" aria-label="{{ $s['mailbox'] }}">
                    @endif
                    @if ($s['state'] !== 'missing')<span class="wp-dot {{ $s['active'] ? 'is-on' : 'is-off' }}" aria-hidden="true"></span>@endif
                    @if ($s['url'])<a href="{{ $s['url'] }}">{{ $s['mailbox'] }}</a>@else{{ $s['mailbox'] }}@endif
                    · {{ __('workflowpacks::messages.state_'.$s['state']) }}
                    @if ($s['state'] !== 'missing') · {{ __('workflowpacks::messages.'.($s['active'] ? 'active' : 'inactive')) }}@endif
                    @if (!empty($toggles) && !empty($s['workflow_id']))
                        {{-- one click: this rule only --}}
                        <form method="POST" action="{{ route('workflowpacks.rules.toggle') }}" class="wp-inline wp-chip-toggle">
                            {{ csrf_field() }}
                            <input type="hidden" name="workflow_ids[]" value="{{ $s['workflow_id'] }}">
                            <input type="hidden" name="active" value="{{ $s['active'] ? 0 : 1 }}">
                            @if (!empty($filter))<input type="hidden" name="mailbox_id" value="{{ $filter }}">@endif
                            <button type="submit" class="btn btn-link btn-xs">{{ __('workflowpacks::messages.'.($s['active'] ? 'disable' : 'enable')) }}</button>
                        </form>
                    @endif
                </span>
            @empty
                <span class="wp-muted">{{ __('workflowpacks::messages.not_applied') }}</span>
            @endforelse
        </div>
    @endif
</div>
