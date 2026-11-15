{{-- Preview of a pack of flows: its parameters, the mailboxes, each flow (created / updated, problems), then import. --}}
@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_description'){{ __('workflowpacks::flowpack.intro') }}@endsection

@section('wp_content')
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('package') !!}</span>
            <div class="wp-pack-title">
                <h2 class="wp-pack-name">{{ $read['pack']['name'] }}</h2>
                <div class="wp-pills">
                    <span class="wp-pill wp-pill-slug">{{ $read['pack']['key'] }}</span>
                    @if ($read['pack']['version'] !== '')<span class="wp-pill">{{ __('workflowpacks::messages.version') }} {{ $read['pack']['version'] }}</span>@endif
                    <span class="wp-pill wp-pill-indigo">{{ __('workflowpacks::flowpack.flows_count', ['count' => count($read['flows'])]) }}</span>
                </div>
            </div>
        </div>
        <form method="POST" action="{{ route('workflowpacks.flowpack.confirm') }}" class="wp-pack-body">
            {{ csrf_field() }}
            <input type="hidden" name="pack_carry" value="{{ $json }}">
            @if ($read['pack']['description'] !== '')
                <div class="wp-infos">
                    @foreach ($wp_ui::infoBlocks($read['pack']['description']) as $info)
                        <div class="wp-info"><span class="wp-square">{!! $wp_ui::icon($info['icon']) !!}</span>
                            <div>@if ($info['title'] !== '')<strong class="wp-info-title">{{ $info['title'] }}</strong>@endif<span class="wp-info-text">{{ $info['text'] }}</span></div></div>
                    @endforeach
                </div>
            @endif
            <div class="wp-ai-grid">
                <div class="wp-ai-field"><span>{{ __('workflowpacks::flowpack.mailboxes') }}</span>
                    {{-- check boxes, not a multiple select: nothing ticked (all mailboxes) is easy, also with a finger --}}
                    <div class="wp-box-checks">
                        @foreach ($mailboxes as $m)<label><input type="checkbox" name="mailbox_ids[]" value="{{ $m->id }}" @if (in_array((int) $m->id, $chosen, true)) checked @endif> {{ $m->name }}</label>@endforeach
                    </div>
                    <small class="wp-muted">{{ __('workflowpacks::flowpack.mailboxes_help') }}</small></div>
                @foreach ($read['params'] as $p)
                    <label class="wp-ai-field"><span>{{ $p['label'] }} @if ($p['required'])<span class="label label-danger">{{ __('workflowpacks::flowpack.required') }}</span>@endif</span>
                        <input type="text" name="params[{{ $p['key'] }}]" class="form-control" value="{{ $values[$p['key']] ?? '' }}" @if ($p['required']) required @endif>
                        @if ($p['description'] !== '')<small class="wp-muted">{{ $p['description'] }}</small>@endif</label>
                @endforeach
            </div>
            @if ($read['params'])
                <div class="wp-pack-actions"><button type="submit" class="btn btn-default" formaction="{{ route('workflowpacks.flowpack.preview') }}">{!! $wp_ui::icon('refresh') !!} {{ __('workflowpacks::flowpack.recheck') }}</button></div>
            @endif
            <h3 class="wp-boxes-title">{{ __('workflowpacks::flowpack.flows_title') }}</h3>
            <div class="wp-tiles">
                @foreach ($read['flows'] as $f)
                    <div class="wp-tile" title="{{ $f['description'] }}">
                        <span class="wp-initials wp-fam-{{ $wp_ui::family($f['key']) }}" aria-hidden="true">{{ $wp_ui::initials($f['name']) }}<span class="wp-state-dot {{ $f['problems'] ? 'is-modified' : 'is-synced' }}"></span></span>
                        <span class="wp-tile-name">{{ $f['name'] }}
                            <span class="wp-tile-note">{{ $f['existing'] ? __('workflowpacks::flowpack.update') : __('workflowpacks::flowpack.create') }}@if ($f['problems']) · {{ __('workflowpacks::flowpack.problems', ['count' => count($f['problems'])]) }}: {{ $f['problems'][0]['message'] }}@endif</span>
                        </span>
                    </div>
                @endforeach
            </div>
            <p class="wp-ai-warn">{!! $wp_ui::icon('shield') !!} {{ __('workflowpacks::flowpack.drafts_note') }}</p>
            <div class="wp-actionbar">
                <button type="submit" class="btn btn-primary">{!! $wp_ui::icon('upload') !!} {{ __('workflowpacks::flowpack.import') }}</button>
                <a href="{{ route('workflowpacks.flowpack') }}" class="btn btn-link">{{ __('workflowpacks::messages.back') }}</a>
            </div>
        </form>
    </section>
@endsection
