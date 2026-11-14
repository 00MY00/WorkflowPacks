{{-- Long list: filter by text, fold / unfold every item of `fold` (workflowpacks.js). items: selector of the
     filtered elements (each with data-wp-text, in lower case). --}}
<div class="wp-listbar" role="search">
    <input type="search" class="form-control input-sm" data-wp-filter="{{ $items }}" placeholder="{{ __('workflowpacks::messages.list_filter') }}" aria-label="{{ __('workflowpacks::messages.list_filter') }}">
    @if (!empty($fold))
        <button type="button" class="btn btn-default btn-xs" data-wp-fold-all="{{ $fold }}" data-wp-open="1">{{ __('workflowpacks::messages.list_unfold') }}</button>
        <button type="button" class="btn btn-default btn-xs" data-wp-fold-all="{{ $fold }}" data-wp-open="0">{{ __('workflowpacks::messages.list_fold') }}</button>
    @endif
    <span class="wp-muted" data-wp-filter-empty hidden>{{ __('workflowpacks::messages.list_none') }}</span>
</div>
