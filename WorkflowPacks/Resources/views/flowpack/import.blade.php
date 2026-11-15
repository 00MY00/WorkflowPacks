{{-- Import a pack of flows (internal engine): a pack shipped with the module, a file or pasted JSON. --}}
@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_description'){{ __('workflowpacks::flowpack.intro') }}@endsection

@section('wp_content')
    @if ($problems)
        <div class="alert alert-warning"><strong>{{ __('workflowpacks::flowpack.invalid') }}</strong>
            <ul>@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul></div>
    @endif
    <div class="wp-ai">
        @if ($bundled)
            <section class="wp-pack-g">
                <div class="wp-pack-head">
                    <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('package') !!}</span>
                    <div class="wp-pack-title"><h2 class="wp-pack-name">{{ __('workflowpacks::flowpack.bundled') }}</h2></div>
                </div>
                <div class="wp-pack-body wp-pack-actions">
                    @foreach ($bundled as $file)
                        <form method="POST" action="{{ route('workflowpacks.flowpack.preview') }}">
                            {{ csrf_field() }}
                            <input type="hidden" name="bundled" value="{{ $file }}">
                            <button type="submit" class="btn btn-default">{!! $wp_ui::icon('package') !!} {{ $file }}</button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endif
        <section class="wp-pack-g">
            <div class="wp-pack-head">
                <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('upload') !!}</span>
                <div class="wp-pack-title"><h2 class="wp-pack-name">{{ __('workflowpacks::flowpack.file') }}</h2></div>
            </div>
            <form method="POST" action="{{ route('workflowpacks.flowpack.preview') }}" enctype="multipart/form-data" class="wp-pack-body">
                {{ csrf_field() }}
                <input type="file" name="pack_file" accept=".json,application/json" class="form-control" aria-label="{{ __('workflowpacks::flowpack.file') }}">
                <details class="wp-fold">
                    <summary>{{ __('workflowpacks::flowpack.paste') }}</summary>
                    <textarea name="pack_json" rows="10" class="form-control wp-mono" spellcheck="false" aria-label="{{ __('workflowpacks::flowpack.paste') }}"></textarea>
                </details>
                <div class="wp-pack-actions"><button type="submit" class="btn btn-primary">{!! $wp_ui::icon('check') !!} {{ __('workflowpacks::flowpack.preview') }}</button></div>
            </form>
        </section>
    </div>
@endsection
