{{-- A flow applied to the tickets already there: the job going on, the choices, the estimate and the dry run. --}}
@extends('workflowpacks::layout')

@php
    $wp_ui = '\Modules\WorkflowPacks\Services\Ui';
    $wp_R = '\Modules\WorkflowPacks\Services\Engine\Reapply';
    $wp_active = $job && $job->active();
@endphp

@section('wp_description'){{ __('workflowpacks::reapply.intro') }}@endsection

@section('wp_content')
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('refresh') !!}</span>
            <div class="wp-pack-title">
                <h2 class="wp-pack-name">{{ $flow->name }}</h2>
                <div class="wp-pills">
                    @if ($flow->version)<span class="wp-pill wp-pill-indigo">{{ __('workflowpacks::flows.published', ['n' => $flow->version->number]) }}</span>@endif
                    <span class="wp-pill">{{ $flow->active ? __('workflowpacks::flows.active') : __('workflowpacks::reapply.inactive') }}</span>
                </div>
            </div>
            <a class="btn btn-link" href="{{ route('workflowpacks.flows', ['flow' => $flow->id]) }}">{{ __('workflowpacks::reapply.back') }}</a>
        </div>

        <div class="wp-pack-body">
            @if (!$flow->version)
                <p class="wp-ai-warn">{!! $wp_ui::icon('info') !!} {{ __('workflowpacks::reapply.not_published') }}</p>
                @if (session('wp_publish_problems'))
                    <div class="alert alert-warning"><strong>{{ __('workflowpacks::reapply.publish_refused_title') }}</strong>
                        <ul>@foreach (session('wp_publish_problems') as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                @endif
                <form method="POST" action="{{ route('workflowpacks.reapply.publish', ['id' => $flow->id]) }}" class="wp-pack-actions">
                    {{ csrf_field() }}
                    <button type="submit" class="btn btn-primary">{!! $wp_ui::icon('check') !!} {{ __('workflowpacks::reapply.publish_now') }}</button>
                    <a class="btn btn-link" href="{{ route('workflowpacks.flows', ['flow' => $flow->id]) }}">{{ __('workflowpacks::reapply.back') }}</a>
                </form>
            @else
                @if ($job)
                    {{-- the job going on (or the last one) --}}
                    <div class="wp-reapply-job">
                        <strong>{{ __('workflowpacks::reapply.scope_'.$job->scope) }} — {{ __('workflowpacks::reapply.state_'.$job->state) }}</strong>
                        <div class="wp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $job->total }}" aria-valuenow="{{ $job->done }}">
                            <span style="width: {{ $job->total ? round(100 * $job->done / $job->total) : 100 }}%"></span></div>
                        <span class="wp-muted">{{ __('workflowpacks::reapply.progress', ['done' => $job->done, 'total' => $job->total, 'started' => $job->started]) }}</span>
                        @if ($wp_active)
                            <form method="POST" action="{{ route('workflowpacks.reapply.control', ['id' => $flow->id]) }}" class="wp-pack-actions">
                                {{ csrf_field() }}
                                @if ($job->state === 'running')
                                    <button type="submit" name="action" value="pause" class="btn btn-default">{{ __('workflowpacks::reapply.pause') }}</button>
                                @else
                                    <button type="submit" name="action" value="resume" class="btn btn-default">{{ __('workflowpacks::reapply.resume') }}</button>
                                @endif
                                <button type="submit" name="action" value="stop" class="btn btn-danger-soft" data-wp-confirm="{{ __('workflowpacks::reapply.stop_confirm') }}">{{ __('workflowpacks::reapply.stop') }}</button>
                                <a class="btn btn-link" href="{{ route('workflowpacks.reapply', ['id' => $flow->id]) }}">{!! $wp_ui::icon('refresh') !!} {{ __('workflowpacks::reapply.refresh') }}</a>
                            </form>
                        @endif
                    </div>
                @endif

                @if (!$wp_active)
                    <form method="POST" action="{{ route('workflowpacks.reapply.estimate', ['id' => $flow->id]) }}">
                        {{ csrf_field() }}
                        <fieldset class="wp-reapply-scopes">
                            <legend>{{ __('workflowpacks::reapply.scope') }}</legend>
                            <label class="wp-reapply-scope"><input type="radio" name="scope" value="existing" @if ($o['scope'] === 'existing') checked @endif>
                                <span><strong>{{ __('workflowpacks::reapply.scope_existing') }}</strong><small class="wp-muted">{{ __('workflowpacks::reapply.scope_existing_help') }}</small></span></label>
                            <label class="wp-reapply-scope"><input type="radio" name="scope" value="running" @if ($o['scope'] === 'running') checked @endif @if (!$running) disabled @endif>
                                <span><strong>{{ __('workflowpacks::reapply.scope_running') }} ({{ $running }})</strong><small class="wp-muted">{{ __('workflowpacks::reapply.scope_running_help') }}</small></span></label>
                        </fieldset>
                        <div class="wp-ai-grid">
                            <label class="wp-ai-field"><span>{{ __('workflowpacks::reapply.period') }}</span>
                                <select name="period" class="form-control">
                                    @foreach ($wp_R::PERIODS as $d)<option value="{{ $d }}" @if ($o['period'] === $d) selected @endif>{{ $d ? __('workflowpacks::reapply.days', ['n' => $d]) : __('workflowpacks::reapply.period_all') }}</option>@endforeach
                                </select></label>
                            <div class="wp-ai-field"><span>{{ __('workflowpacks::reapply.statuses') }}</span>
                                <div class="wp-reapply-checks">
                                    @foreach (['active', 'pending', 'closed'] as $s)
                                        <label><input type="checkbox" name="statuses[]" value="{{ $s }}" @if (in_array($s, $o['statuses'], true)) checked @endif> {{ __('workflowpacks::blocks.values.status.'.$s) }}</label>
                                    @endforeach
                                </div></div>
                            <label class="wp-ai-field"><span>{{ __('workflowpacks::reapply.batch') }}</span>
                                <select name="batch" class="form-control">
                                    @foreach ($wp_R::BATCHES as $b)<option value="{{ $b }}" @if ($o['batch'] === $b) selected @endif>{{ $b }}</option>@endforeach
                                </select></label>
                            <label class="wp-ai-field"><span>{{ __('workflowpacks::reapply.every') }}</span>
                                <select name="every" class="form-control">
                                    @foreach ($wp_R::EVERY as $m)<option value="{{ $m }}" @if ($o['every'] === $m) selected @endif>{{ __('workflowpacks::reapply.minutes', ['n' => $m]) }}</option>@endforeach
                                </select></label>
                        </div>
                        <small class="wp-muted">{{ __('workflowpacks::reapply.period_help') }}</small>
                        <div class="wp-pack-actions"><button type="submit" class="btn btn-default">{!! $wp_ui::icon('check') !!} {{ __('workflowpacks::reapply.estimate') }}</button></div>

                        @if ($estimate)
                            <h3 class="wp-boxes-title">{{ __('workflowpacks::reapply.estimate_title') }}</h3>
                            @if (!$estimate['total'])
                                <p class="wp-muted">{{ __('workflowpacks::reapply.nothing') }}</p>
                            @else
                                <p><strong>{{ __('workflowpacks::reapply.total', ['count' => $estimate['total']]) }}</strong> ·
                                    {{ __('workflowpacks::reapply.duration', ['minutes' => $estimate['minutes'], 'batch' => $o['batch'], 'every' => $o['every']]) }}</p>
                                @if ($estimate['sample'])
                                    <p class="wp-muted">{{ __('workflowpacks::reapply.sample_title', ['count' => count($estimate['sample']['tickets'])]) }}</p>
                                    <div class="wp-tiles">
                                        @foreach ($estimate['sample']['groups'] as $g)
                                            <div class="wp-tile"><span class="wp-tile-name">{{ $g['changes'] !== '' ? $g['changes'] : __('workflowpacks::reapply.sample_none') }}
                                                <span class="wp-tile-note">{{ __('workflowpacks::reapply.sample_tickets', ['count' => count($g['tickets'])]) }}</span></span></div>
                                        @endforeach
                                    </div>
                                @endif
                                @foreach ($estimate['risky'] as $type)
                                    <p class="wp-ai-warn">{!! $wp_ui::icon('shield') !!} {{ __('workflowpacks::reapply.warn_'.str_replace('action.', '', $type)) }}</p>
                                @endforeach
                                @if ($estimate['risky'])
                                    <label class="wpf-check @if (!empty($wp_risky_missing)) text-danger @endif"><input type="checkbox" name="confirm_risky" value="1"> {{ __('workflowpacks::reapply.confirm_risky') }}</label>
                                @endif
                                <div class="wp-actionbar">
                                    <button type="submit" class="btn btn-primary" formaction="{{ route('workflowpacks.reapply.start', ['id' => $flow->id]) }}">{!! $wp_ui::icon('refresh') !!} {{ __('workflowpacks::reapply.start') }}</button>
                                </div>
                            @endif
                        @endif
                    </form>
                @endif
            @endif
        </div>
    </section>
@endsection
