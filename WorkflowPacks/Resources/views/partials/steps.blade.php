<ol class="wp-steps">
    @foreach ([1, 2, 3, 4] as $n)
        <li class="@if ($n === $step) active @elseif ($n < $step) done @endif">{{ __('workflowpacks::messages.step'.$n) }}</li>
    @endforeach
</ol>
