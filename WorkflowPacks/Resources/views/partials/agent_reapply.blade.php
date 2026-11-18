{{--
    Limits of a connected agent applying a flow to the tickets already there (Agent::cleanLimits): statuses, period,
    number of tickets, tags, assigned users, blocks that delete or notify. Open when the right is ticked.
    $limits: Agent::reapplyLimits(); $rights: the rights ticked; $wpUsers, $tagSource: from AiController::data().
--}}
@php
    $wp_on = (bool) array_intersect(['own.reapply', 'others.reapply'], $rights);
    $wp_assigned = $limits['assignees'];
@endphp
<input type="hidden" name="reapply_sent" value="1">
<details class="wp-reapply-limits" @if ($wp_on) open @endif>
    <summary>{{ __('workflowpacks::ai.reapply_limits') }} @include('workflowpacks::partials.tip', ['text' => __('workflowpacks::ai.reapply_limits_tip')])</summary>
    <div class="wp-ai-grid">
        <div class="wp-ai-field"><span>{{ __('workflowpacks::ai.limit_statuses') }}</span>
            <div class="wp-box-checks">
                @foreach (\Modules\WorkflowPacks\Services\Engine\TicketScope::STATUSES as $wp_s)
                    <label><input type="checkbox" name="reapply[statuses][]" value="{{ $wp_s }}" @if (in_array($wp_s, $limits['statuses'], true)) checked @endif> {{ __('workflowpacks::blocks.values.status.'.$wp_s) }}</label>
                @endforeach
            </div></div>
        <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.limit_period') }}</span>
            <select name="reapply[period_max]" class="form-control">
                @foreach (\Modules\WorkflowPacks\Services\Engine\Reapply::PERIODS as $wp_p)
                    <option value="{{ $wp_p }}" @if ($wp_p === $limits['period_max']) selected @endif>{{ $wp_p ? __('workflowpacks::reapply.days', ['n' => $wp_p]) : __('workflowpacks::reapply.period_all') }}</option>
                @endforeach
            </select></label>
        <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.limit_max') }}</span>
            <select name="reapply[max_tickets]" class="form-control">
                @foreach (\Modules\WorkflowPacks\Entities\Agent::REAPPLY_MAX as $wp_m)
                    <option value="{{ $wp_m }}" @if ($wp_m === $limits['max_tickets']) selected @endif>{{ number_format($wp_m, 0, '', ' ') }}</option>
                @endforeach
            </select></label>
        <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.limit_tags') }} @include('workflowpacks::partials.tip', ['text' => __('workflowpacks::ai.limit_tags_tip')])</span>
            <input type="text" name="reapply[tags]" class="form-control" maxlength="1300" value="{{ implode(', ', $limits['tags']) }}" @if (!$tagSource) disabled @endif
                placeholder="{{ __('workflowpacks::ai.limit_any') }}">
            @if (!$tagSource)<small class="wp-muted">{{ __('workflowpacks::ai.limit_tags_off') }}</small>@endif</label>
        <div class="wp-ai-field"><span>{{ __('workflowpacks::ai.limit_assignees') }} @include('workflowpacks::partials.tip', ['text' => __('workflowpacks::ai.limit_assignees_tip')])</span>
            <div class="wp-box-checks">
                <label><input type="checkbox" name="reapply[assignees][]" value="0" @if (in_array(0, $wp_assigned, true)) checked @endif> {{ __('workflowpacks::ai.limit_unassigned') }}</label>
                @foreach ($wpUsers as $wp_u)
                    <label><input type="checkbox" name="reapply[assignees][]" value="{{ $wp_u->id }}" @if (in_array((int) $wp_u->id, $wp_assigned, true)) checked @endif> {{ $wp_u->getFullName() }}</label>
                @endforeach
            </div>
            <small class="wp-muted">{{ __('workflowpacks::ai.limit_assignees_help') }}</small></div>
    </div>
    <label class="wp-ai-check"><input type="checkbox" name="reapply[risky]" value="1" @if ($limits['risky']) checked @endif> {{ __('workflowpacks::ai.limit_risky') }}</label>
    @include('workflowpacks::partials.tip', ['text' => __('workflowpacks::ai.limit_risky_tip')])
</details>
