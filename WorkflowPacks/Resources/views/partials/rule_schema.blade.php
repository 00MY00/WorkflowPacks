{{-- One rule: its category, name and pills, then IF -> THEN side by side (one token per condition group / action),
     the full IF (AND of OR groups) THEN folded under "Details", sensitive actions always visible.
     $rule: pack-shaped rule; $mailboxId: optional (labels from the catalog); $states: optional mailboxes (summary line
     + one tile each); $open: true to show the details unfolded. --}}
@php
    $wp_ui = '\Modules\WorkflowPacks\Services\Ui';
    $groups = $wp_ui::groups($rule['conditions'] ?? null);
    $mb = $mailboxId ?? null;
    $wp_ifs = [];
    foreach ($groups as $group) {
        $wp_ifs[] = implode(' / ', array_unique(array_map(function ($c) use ($mb, $wp_ui) {
            return $wp_ui::title('conditions', $c['type'] ?? '', $mb);
        }, $group)));
    }
    list($wp_catIcon, $wp_catFam) = $wp_ui::category($rule);
@endphp
<div class="wp-rule">
    <div class="wp-rule-top">
        <span class="wp-square wp-cat-{{ $wp_catFam }}">{!! $wp_ui::icon($wp_catIcon) !!}</span>
        <div class="wp-rule-title">
            <div class="wp-rule-name">{!! $wp_ui::value($rule['name']) !!}</div>
            @if (!empty($rule['description']))<div class="wp-rule-desc">{{ $rule['description'] }}</div>@endif
        </div>
        <div class="wp-rule-meta">
            @php $wp_manual = ($rule['type'] ?? 'automatic') === 'manual'; @endphp
            <span class="wp-pill {{ $wp_manual ? '' : 'wp-pill-info' }}">{{ __('workflowpacks::messages.'.($wp_manual ? 'manual' : 'automatic')) }}</span>
            @foreach (($rule['requires'] ?? []) as $module)
                <span class="label label-warning">{{ __('workflowpacks::messages.requires') }} {{ $module }}</span>
            @endforeach
            @if (!empty($rule['mailboxes']))
                <span class="wp-pill wp-pill-indigo">{{ \Modules\WorkflowPacks\Services\Pack\MailboxSelector::describe($rule['mailboxes']) }}</span>
            @endif
            @if (!empty($rule['settings']['apply_to_prev']))
                <span class="wp-pill">{{ __('workflowpacks::messages.apply_to_prev') }}</span>
            @endif
            @if (($rule['settings']['max_executions'] ?? 1) > 1)
                <span class="wp-pill">{{ __('workflowpacks::messages.max_executions') }} : {{ (int) $rule['settings']['max_executions'] }}</span>
            @endif
        </div>
    </div>
    <div class="wp-ifthen">
        <div class="wp-box wp-box-if">
            <span class="wp-box-label">{!! $wp_ui::icon('zap') !!} {{ __('workflowpacks::messages.if') }}</span>
            @forelse ($wp_ifs as $i => $if)
                @if ($i > 0)<span class="wp-token-plus" aria-label="{{ __('workflowpacks::messages.and') }}">+</span>@endif
                <span class="wp-token">{{ $if }}</span>
            @empty
                <span class="wp-token">{{ __('workflowpacks::messages.manual_no_cond') }}</span>
            @endforelse
        </div>
        <div class="wp-ifthen-arrow" aria-hidden="true">{!! $wp_ui::icon('arrow') !!}</div>
        <div class="wp-box wp-box-then">
            <span class="wp-box-label">{!! $wp_ui::icon('note') !!} {{ __('workflowpacks::messages.then') }}</span>
            @foreach (($rule['actions'] ?? []) as $action)
                @php $sensitive = $wp_ui::sensitive($action['type'] ?? ''); @endphp
                <span class="wp-token @if ($sensitive) is-sensitive @endif" @if ($sensitive) title="{{ __('workflowpacks::messages.sensitive_'.$sensitive) }}" @endif>
                    @if ($sensitive){!! $wp_ui::icon('shield') !!}@endif
                    {{ $wp_ui::title('actions', $action['type'] ?? '', $mb) }}
                    @if ($sensitive)<span class="sr-only">— {{ __('workflowpacks::messages.sensitive_'.$sensitive) }}</span>@endif
                </span>
            @endforeach
        </div>
    </div>
    <details class="wp-rule-details" @if (!empty($open)) open @endif>
    <summary>{{ __('workflowpacks::messages.details') }}</summary>
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
                            <b>{{ $wp_ui::label('conditions', $condition['type'] ?? '', $mb) }}</b>
                            <i>{{ $condition['operator'] ?? '' }}</i>
                            @if (array_key_exists('value', $condition))<code>{!! $wp_ui::value($condition['value']) !!}</code>@endif
                        </span>
                    @endforeach
                </span>
            @endforeach
        </div>
        <div class="wp-flow-then">
            <span class="wp-flow-label">{{ __('workflowpacks::messages.then') }}</span>
            @foreach (($rule['actions'] ?? []) as $action)
                @php $sensitive = $wp_ui::sensitive($action['type'] ?? ''); @endphp
                <span class="wp-action @if ($sensitive) wp-sensitive @endif">
                    <b>{{ $wp_ui::label('actions', $action['type'] ?? '', $mb) }}</b>
                    @if (array_key_exists('value', $action))<code>{!! $wp_ui::value($action['value']) !!}</code>@endif
                    @if ($sensitive)<span class="label label-danger">{!! $wp_ui::icon('shield') !!} {{ __('workflowpacks::messages.sensitive_'.$sensitive) }}</span>@endif
                </span>
            @endforeach
        </div>
    </div>
    </details>
    @if (isset($states))
        @php
            // states shared by every mailbox: written once in the summary, never repeated in each tile
            $wp_total = count($states);
            $wp_counts = array_count_values(array_column($states, 'state'));
            $wp_on = count(array_filter($states, function ($s) { return $s['state'] !== 'missing' && !empty($s['active']); }));
            $wp_off = count(array_filter($states, function ($s) { return $s['state'] !== 'missing' && empty($s['active']); }));
            $wp_prev = count(array_filter($states, function ($s) { return !empty($s['previous']); }));
            $wp_present = $wp_total - ($wp_counts['missing'] ?? 0);
        @endphp
        @if ($wp_total)
            <div class="wp-boxes-sum">
                <span class="wp-boxes-title">{{ __('workflowpacks::messages.mailboxes_count', ['count' => $wp_total]) }}</span>
                @if (!empty($wp_counts['synced']))
                    <span class="wp-pill wp-pill-ok"><span class="wp-dot-ok" aria-hidden="true"></span>{{ __('workflowpacks::messages.conform_count', ['count' => $wp_counts['synced']]) }}</span>
                @endif
                @foreach (['modified', 'missing', 'local'] as $wp_st)
                    @if (!empty($wp_counts[$wp_st]))<span class="label label-warning">{{ $wp_counts[$wp_st] }} × {{ __('workflowpacks::messages.state_'.$wp_st) }}</span>@endif
                @endforeach
                <span class="wp-pill"><span class="wp-mini-switch" aria-hidden="true"></span>{{ __('workflowpacks::messages.active_count', ['off' => $wp_off, 'on' => $wp_on]) }}</span>
                <span class="wp-pill">{!! $wp_ui::icon('clock') !!}
                    @if ($wp_prev === 0){{ __('workflowpacks::messages.previous_off') }}@elseif ($wp_prev >= $wp_present){{ __('workflowpacks::messages.previous_on') }}@else{{ __('workflowpacks::messages.previous_mixed', ['yes' => $wp_prev, 'no' => $wp_present - $wp_prev]) }}@endif
                </span>
                <span class="wp-grow"></span>
                @if (!empty($toggles))
                    <label class="wp-select-all"><input type="checkbox" data-wp-check-all=".wp-bulk-check" data-wp-scope=".wp-rule"> {{ __('workflowpacks::messages.select_all') }}</label>
                @endif
            </div>
            <div class="wp-tiles">
                @foreach ($states as $s)
                    @php
                        $wp_state = __('workflowpacks::messages.state_'.$s['state']);
                        $wp_active = $s['state'] !== 'missing' ? __('workflowpacks::messages.'.($s['active'] ? 'active' : 'inactive')) : '';
                        $wp_can = !empty($toggles) && !empty($s['workflow_id']);
                    @endphp
                    <div class="wp-tile" title="{{ $s['mailbox'] }} · {{ $wp_state }}{{ $wp_active ? ' · '.$wp_active : '' }} · {{ __('workflowpacks::messages.version') }} {{ $s['version'] }}">
                        @if ($wp_can)
                            <input type="checkbox" form="wp-bulk" name="workflow_ids[]" value="{{ $s['workflow_id'] }}" class="wp-bulk-check" aria-label="{{ $s['mailbox'] }}">
                        @endif
                        <span class="wp-initials wp-fam-{{ $wp_ui::family($s['mailbox']) }}" aria-hidden="true">{{ $wp_ui::initials($s['mailbox']) }}<span class="wp-state-dot is-{{ $s['state'] }}"></span></span>
                        <span class="wp-tile-name">
                            @if ($s['url'])<a href="{{ $s['url'] }}">{{ $s['mailbox'] }}</a>@else{{ $s['mailbox'] }}@endif
                            {{-- what differs from the summary is said here; "conforme" is the green dot + the summary pill --}}
                            @if ($s['state'] !== 'synced')<span class="wp-tile-note">{{ $wp_state }}</span>@else<span class="sr-only">{{ $wp_state }}</span>@endif
                        </span>
                        @if ($wp_can)
                            {{-- also the conversations created before the rule (only those it has not handled yet) --}}
                            <form method="POST" action="{{ route('workflowpacks.rules.toggle') }}">
                                {{ csrf_field() }}
                                <input type="hidden" name="workflow_ids[]" value="{{ $s['workflow_id'] }}">
                                <input type="hidden" name="previous" value="{{ !empty($s['previous']) ? 0 : 1 }}">
                                @if (!empty($filter))<input type="hidden" name="mailbox_id" value="{{ $filter }}">@endif
                                @php $wp_prevLabel = __('workflowpacks::messages.'.(!empty($s['previous']) ? 'previous_on' : 'previous_off')); @endphp
                                <button type="submit" class="wp-icon-btn {{ !empty($s['previous']) ? 'is-on' : '' }}" title="{{ $wp_prevLabel }} — {{ __('workflowpacks::messages.previous_help') }}" aria-label="{{ $wp_prevLabel }}"
                                    @if (empty($s['previous'])) data-wp-confirm="{{ __('workflowpacks::messages.previous_confirm') }}" @endif>{!! $wp_ui::icon('clock') !!}</button>
                            </form>
                            {{-- one click: this rule only --}}
                            <form method="POST" action="{{ route('workflowpacks.rules.toggle') }}">
                                {{ csrf_field() }}
                                <input type="hidden" name="workflow_ids[]" value="{{ $s['workflow_id'] }}">
                                <input type="hidden" name="active" value="{{ $s['active'] ? 0 : 1 }}">
                                @if (!empty($filter))<input type="hidden" name="mailbox_id" value="{{ $filter }}">@endif
                                @php $wp_switchLabel = __('workflowpacks::messages.'.($s['active'] ? 'switch_on' : 'switch_off')); @endphp
                                <button type="submit" class="wp-switch {{ $s['active'] ? 'is-on' : '' }}" role="switch" aria-checked="{{ $s['active'] ? 'true' : 'false' }}" title="{{ $wp_switchLabel }}" aria-label="{{ $wp_switchLabel }}"><span></span></button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="wp-boxes-sum"><span class="wp-muted">{{ __('workflowpacks::messages.not_applied') }}</span></div>
        @endif
    @endif
</div>
