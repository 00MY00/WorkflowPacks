{{--
    A number of days back (Services\Engine\Reapply::period): any number from 1 to MAX_DAYS, 0 for all; the usual
    choices offered as suggestions. $wp_name: name of the field; $wp_value: days (0: all).
--}}
@php
    $wp_R = \Modules\WorkflowPacks\Services\Engine\Reapply::class;
    // several on one page (one form per agent): an id of its own each time
    $wp_id = 'wp-days-'.strtolower(\Illuminate\Support\Str::random(8));
@endphp
<span class="wp-days">
    <input type="number" name="{{ $wp_name }}" class="form-control" min="0" max="{{ $wp_R::MAX_DAYS }}" step="1" inputmode="numeric" required
        value="{{ (int) $wp_value }}" list="{{ $wp_id }}-choices" aria-describedby="{{ $wp_id }}-help">
    <span class="wp-days-unit">{{ __('workflowpacks::reapply.days_unit') }}</span>
</span>
<small class="wp-muted" id="{{ $wp_id }}-help">{{ __('workflowpacks::reapply.days_help', ['max' => $wp_R::MAX_DAYS]) }}</small>
<datalist id="{{ $wp_id }}-choices">@foreach ($wp_R::PERIODS as $wp_d)<option value="{{ $wp_d }}">{{ $wp_d ? __('workflowpacks::reapply.days', ['n' => $wp_d]) : __('workflowpacks::reapply.period_all') }}</option>@endforeach</datalist>
