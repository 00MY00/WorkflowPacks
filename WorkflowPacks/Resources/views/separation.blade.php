{{--
    Separation from Workflows (4.0): what a confirmation deletes, the backup to download first, then confirm, stay on the
    previous version (nothing is deleted until then) or decide later. $plan: Services\Separation::plan().
--}}
@extends('workflowpacks::layout')

@php
    $wp_ui = '\Modules\WorkflowPacks\Services\Ui';
    $wp_s = function ($key, array $params = []) { return __('workflowpacks::separation.'.$key, $params); };
@endphp

@section('wp_description'){{ $wp_s('intro') }}@endsection

@section('wp_content')
    <section class="wp-pack-g wp-separation">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('shield') !!}</span>
            <div class="wp-pack-title">
                <h2 class="wp-pack-name">{{ $wp_s('title') }}</h2>
                <div class="wp-pills">
                    <span class="wp-pill wp-pill-indigo">{{ $wp_s('rules_count', ['count' => count($plan['rules'])]) }}</span>
                    <span class="wp-pill">{{ $wp_s('packs_count', ['count' => count($plan['packs'])]) }}</span>
                </div>
            </div>
        </div>

        <div class="wp-pack-body">
            <p>{{ $wp_s('why') }}</p>

            <h3 class="wp-boxes-title">{{ $wp_s('what_title') }}</h3>
            <ul class="wp-separation-list">
                <li>{{ $wp_s('what_rules', ['count' => count($plan['rules'])]) }}</li>
                @if ($plan['gone'])<li>{{ $wp_s('what_gone', ['count' => $plan['gone']]) }}</li>@endif
                <li>{{ $wp_s('what_tables', ['count' => count($plan['tables'])]) }}</li>
                @if ($plan['backups'])<li>{{ $wp_s('what_backups', ['count' => $plan['backups']]) }}</li>@endif
                <li><strong>{{ $wp_s('what_kept') }}</strong></li>
            </ul>
            @if (!$plan['workflows'] && $plan['rules'])
                <p class="wp-muted">{!! $wp_ui::icon('info') !!} {{ $wp_s('no_workflows') }}</p>
            @endif

            @if ($plan['rules'])
                <details class="wp-separation-rules">
                    <summary>{{ $wp_s('rules_show', ['count' => count($plan['rules'])]) }}</summary>
                    <div class="table-responsive">
                        <table class="table table-condensed">
                            <thead><tr><th>{{ $wp_s('col_rule') }}</th><th>{{ $wp_s('col_mailbox') }}</th><th>{{ $wp_s('col_pack') }}</th><th>{{ $wp_s('col_state') }}</th></tr></thead>
                            <tbody>
                                @foreach ($plan['rules'] as $r)
                                    <tr><td>{{ $r['name'] }}</td><td>{{ $r['mailbox'] }}</td><td>{{ $r['pack'] }}</td>
                                        <td>{{ $r['active'] ? $wp_s('state_on') : $wp_s('state_off') }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif

            {{-- 1. the backup first --}}
            <h3 class="wp-boxes-title">{{ $wp_s('step_backup') }}</h3>
            <p>{{ $wp_s('backup_help') }}</p>
            <p><a class="btn btn-default" href="{{ route('workflowpacks.separation.backup') }}">{!! $wp_ui::icon('download') !!} {{ $wp_s('backup_download') }}</a></p>

            {{-- 2. the decision --}}
            <h3 class="wp-boxes-title">{{ $wp_s('step_choose') }}</h3>
            <div class="wp-separation-choices">
                <form method="POST" action="{{ route('workflowpacks.separation.confirm') }}" class="wp-separation-choice">
                    {{ csrf_field() }}
                    <input type="hidden" name="choice" value="confirm">
                    <h4>{{ $wp_s('confirm_title') }}</h4>
                    <p>{{ $wp_s('confirm_help') }}</p>
                    <label class="wp-ai-check"><input type="checkbox" name="understood" value="1" required> {{ $wp_s('confirm_tick') }}</label>
                    <button type="submit" class="btn btn-danger" data-wp-confirm="{{ $wp_s('confirm_ask', ['count' => count($plan['rules'])]) }}">{!! $wp_ui::icon('trash') !!} {{ $wp_s('confirm_button') }}</button>
                </form>

                <div class="wp-separation-choice">
                    <h4>{{ $wp_s('stay_title', ['version' => $previous]) }}</h4>
                    <p>{{ $wp_s('stay_help', ['version' => $previous]) }}</p>
                    <ol>
                        @if ($updateGuard)
                            <li>{!! $wp_s('stay_updateguard', ['url' => e($updateGuard)]) !!}</li>
                        @endif
                        <li>{!! $wp_s('stay_download', ['url' => e($zipUrl), 'version' => e($previous)]) !!}</li>
                        <li>{{ $wp_s('stay_install') }} <code>sudo bash install.sh --version {{ $previous }}</code></li>
                    </ol>
                    <p><a class="btn btn-default" href="{{ $releaseUrl }}" target="_blank" rel="noopener noreferrer">{{ $wp_s('stay_button', ['version' => $previous]) }}</a></p>
                </div>

                <form method="POST" action="{{ route('workflowpacks.separation.confirm') }}" class="wp-separation-choice">
                    {{ csrf_field() }}
                    <input type="hidden" name="choice" value="later">
                    <h4>{{ $wp_s('later_title') }}</h4>
                    <p>{{ $wp_s('later_help') }}</p>
                    <button type="submit" class="btn btn-link">{{ $wp_s('later_button') }}</button>
                </form>
            </div>
        </div>
    </section>
@endsection
