{{-- Rows of the compatibility checks (Diagnostic): state, code, what, detail. $checks: list of checks. --}}
<table class="table table-condensed wp-checks">
    @foreach ($checks as $check)
        <tr class="wp-check-{{ $check['state'] }}">
            <td><span class="wp-state wp-state-{{ $check['state'] }}">{{ strtoupper($check['state']) }}</span></td>
            <td><code>{{ $check['code'] }}</code></td>
            <td>{{ $check['label'] }}</td>
            <td class="wp-muted">{{ $check['detail'] }}</td>
        </tr>
    @endforeach
</table>
