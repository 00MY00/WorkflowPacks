{{--
    Rights of a connected agent: each action on its own flows and on the other flows of its mailboxes (Agent::PERMISSIONS).
    $rights: the rights ticked; $wp_id: a prefix keeping the boxes of several forms apart.
--}}
@php
    $wp_rows = [
        ['see', false, true], ['test', false, true], ['propose', true, false], ['edit', true, true], ['rename', true, true],
        ['delete', true, true], ['activate', true, true], ['deactivate', true, true], ['publish', true, true], ['reapply', true, true],
    ];
@endphp
<input type="hidden" name="permissions_sent" value="1">
<table class="wp-rights">
    <thead><tr><th>{{ __('workflowpacks::ai.rights_action') }}</th><th>{{ __('workflowpacks::ai.rights_own') }}</th><th>{{ __('workflowpacks::ai.rights_others') }}</th></tr></thead>
    <tbody>
        @foreach ($wp_rows as $wp_row)
            <tr>
                <th scope="row">{{ __('workflowpacks::ai.right_'.$wp_row[0]) }} @include('workflowpacks::partials.tip', ['text' => __('workflowpacks::ai.right_'.$wp_row[0].'_tip')])</th>
                <td>
                    @if ($wp_row[1])
                        <input type="checkbox" name="permissions[]" value="own.{{ $wp_row[0] }}" aria-label="{{ __('workflowpacks::ai.right_'.$wp_row[0]) }} — {{ __('workflowpacks::ai.rights_own') }}"
                            @if (in_array('own.'.$wp_row[0], $rights, true)) checked @endif>
                    @elseif (in_array($wp_row[0], ['see', 'test'], true))
                        <span class="wp-muted" title="{{ __('workflowpacks::ai.rights_always') }}">✓</span>
                    @else
                        <span class="wp-muted">—</span>
                    @endif
                </td>
                <td>
                    @if ($wp_row[2])
                        <input type="checkbox" name="permissions[]" value="others.{{ $wp_row[0] }}" aria-label="{{ __('workflowpacks::ai.right_'.$wp_row[0]) }} — {{ __('workflowpacks::ai.rights_others') }}"
                            @if (in_array('others.'.$wp_row[0], $rights, true)) checked @endif>
                    @else
                        <span class="wp-muted">—</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
<small class="wp-muted">{{ __('workflowpacks::ai.rights_help') }}</small>
