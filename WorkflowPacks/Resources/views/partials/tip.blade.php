{{-- A "?" next to a setting: its explanation in a bubble, on hover, on focus, and on a tap or click (workflowpacks.js). --}}
<span class="wp-tip">
    <button type="button" class="wp-tip-btn" aria-label="{{ __('workflowpacks::ai.tip_label') }}" aria-expanded="false" data-wp-tip>?</button>
    <span class="wp-tip-bubble" role="tooltip">{{ $text }}</span>
</span>
