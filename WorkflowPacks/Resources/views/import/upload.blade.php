@extends('workflowpacks::layout')

@section('wp_content')
    @include('workflowpacks::partials.steps', ['step' => 1])
    @include('workflowpacks::partials.problems', ['problems' => $problems, 'title' => __('workflowpacks::messages.invalid_pack')])
    <form method="POST" action="{{ route('workflowpacks.import.configure') }}" enctype="multipart/form-data" class="wp-form">
        {{ csrf_field() }}
        <input type="hidden" name="mailbox_id" value="{{ $mailbox_id }}">
        <div class="form-group">
            <label for="wp-file">{{ __('workflowpacks::messages.file') }}</label>
            <input type="file" id="wp-file" name="pack_file" accept=".json,application/json" class="form-control">
        </div>
        <div class="form-group">
            <label for="wp-json">{{ __('workflowpacks::messages.or_paste') }}</label>
            <textarea id="wp-json" name="pack_json" rows="10" class="form-control wp-mono" spellcheck="false">{{ old('pack_json', '') }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary">{{ __('workflowpacks::messages.validate') }}</button>
    </form>

    @php $bundled = \Modules\WorkflowPacks\Http\Controllers\PacksController::bundledPacks(); @endphp
    @if ($bundled)
        <h4 class="wp-section">{{ __('workflowpacks::messages.bundled') }}</h4>
        @foreach (array_keys($bundled) as $file)
            <form method="POST" action="{{ route('workflowpacks.import.configure') }}" class="wp-inline">
                {{ csrf_field() }}
                <input type="hidden" name="mailbox_id" value="{{ $mailbox_id }}">
                <input type="hidden" name="bundled" value="{{ $file }}">
                <button type="submit" class="btn btn-default">{{ $file }}</button>
            </form>
        @endforeach
    @endif
@endsection
