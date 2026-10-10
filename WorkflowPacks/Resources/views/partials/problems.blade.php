@if (!empty($problems))
    <div class="alert alert-danger wp-problems">
        <strong>{{ $title ?? __('workflowpacks::messages.errors') }}</strong>
        <ul>
            @foreach ($problems as $p)
                @if (is_array($p))
                    <li><code>{{ $p['code'] }}</code> @if ($p['path'] !== '')<span class="wp-path">{{ $p['path'] }}</span> : @endif{{ $p['message'] }}</li>
                @else
                    <li>{{ $p }}</li>
                @endif
            @endforeach
        </ul>
    </div>
@endif
