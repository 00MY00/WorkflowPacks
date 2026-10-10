@extends('workflowpacks::layout')

@section('wp_content')
    <div class="alert alert-danger wp-blocked">
        <h4>{{ __('workflowpacks::messages.blocked_title') }}</h4>
        <p>{{ __('workflowpacks::messages.blocked_help') }}</p>
        <ul>
            @foreach ($reasons as $reason)
                <li>{{ $reason }}</li>
            @endforeach
        </ul>
    </div>
@endsection
