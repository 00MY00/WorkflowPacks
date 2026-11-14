{{--
    Workflow Packs › AI assistant (no connection): 1. a brief for the AI (tickets with their id, anonymised by default),
    2. its answer checked and played on those tickets (dry run), 3. the flow saved as a draft. AiController.
--}}
@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_description'){{ __('workflowpacks::ai.intro') }}@endsection

@section('wp_content')
<div class="wp-ai">
    {{-- 1. the brief --}}
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('upload') !!}</span>
            <div class="wp-pack-title"><h2 class="wp-pack-name">1. {{ __('workflowpacks::ai.step1') }}</h2></div>
        </div>
        <form method="POST" action="{{ route('workflowpacks.ai.brief') }}" class="wp-pack-body">
            {{ csrf_field() }}
            @include('workflowpacks::partials.help', ['text' => __('workflowpacks::ai.step1_help')])
            <div class="wp-ai-grid">
                <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.mailboxes') }}</span>
                    <select name="mailbox_ids[]" class="form-control" multiple size="4" required>
                        @foreach ($mailboxes as $m)
                            <option value="{{ $m->id }}" @if (in_array((string) $m->id, (array) ($old['mailbox_ids'] ?? []), true)) selected @endif>{{ $m->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.days') }}</span>
                    <input type="number" name="days" min="1" max="365" class="form-control" value="{{ $old['days'] ?? 30 }}"></label>
                <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.limit') }}</span>
                    <input type="number" name="limit" min="1" max="200" class="form-control" value="{{ $old['limit'] ?? 100 }}"></label>
                <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.search') }}</span>
                    <input type="text" name="search" class="form-control" maxlength="100" placeholder="{{ __('workflowpacks::ai.search_ph') }}" value="{{ $old['search'] ?? '' }}"></label>
            </div>
            <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.task') }}</span>
                <textarea name="task" rows="3" class="form-control" maxlength="4000" placeholder="{{ __('workflowpacks::ai.task_ph') }}">{{ $old['task'] ?? '' }}</textarea></label>
            <label class="wp-ai-check"><input type="checkbox" name="full_text" value="1" @if (!empty($old['full_text'])) checked @endif>
                {{ __('workflowpacks::ai.full_text') }}</label>
            <p class="wp-ai-warn">{!! $wp_ui::icon('shield') !!} {{ __('workflowpacks::ai.full_text_warn') }}</p>
            <div class="wp-pack-actions">
                <button type="submit" class="btn btn-primary">{!! $wp_ui::icon('layers') !!} {{ __('workflowpacks::ai.make') }}</button>
                <button type="submit" name="download" value="1" class="btn btn-default">{!! $wp_ui::icon('download') !!} {{ __('workflowpacks::ai.download') }}</button>
            </div>
            @if ($brief)
                <p class="wp-pill wp-pill-ok"><span class="wp-dot-ok" aria-hidden="true"></span>{{ __('workflowpacks::ai.brief_ready', ['count' => $count]) }}</p>
                <textarea class="form-control wp-mono wp-ai-brief" rows="12" readonly id="wp-ai-brief" aria-label="{{ __('workflowpacks::ai.step1') }}">{{ $brief }}</textarea>
                <button type="button" class="btn btn-default" data-wp-copy="#wp-ai-brief" data-wp-copied="{{ __('workflowpacks::ai.copied') }}">{!! $wp_ui::icon('note') !!} {{ __('workflowpacks::ai.copy') }}</button>
            @endif
        </form>
    </section>

    {{-- connected agents (API) --}}
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('power') !!}</span>
            <div class="wp-pack-title"><h2 class="wp-pack-name">{{ __('workflowpacks::ai.agents') }}</h2>
                <div class="wp-pills"><span class="wp-pill">{{ __('workflowpacks::ai.agents_count', ['count' => $agents->filter->usable()->count()]) }}</span></div></div>
        </div>
        <div class="wp-pack-body">
            @include('workflowpacks::partials.help', ['text' => __('workflowpacks::ai.agents_help')])
            @if ($newToken)
                <div class="alert alert-success">
                    <strong>{{ __('workflowpacks::ai.token_once') }}</strong>
                    <input type="text" class="form-control wp-mono" id="wp-ai-token" value="{{ $newToken }}" readonly aria-label="{{ __('workflowpacks::ai.token_once') }}">
                    <button type="button" class="btn btn-default btn-sm" data-wp-copy="#wp-ai-token" data-wp-copied="{{ __('workflowpacks::ai.copied') }}">{!! $wp_ui::icon('note') !!} {{ __('workflowpacks::ai.copy') }}</button>
                    <p>{{ __('workflowpacks::ai.api_url') }} <code>{{ $apiUrl }}</code></p>
                </div>
            @endif
            @if ($agents->count())
                <div class="wp-tiles">
                    @foreach ($agents as $ag)
                        <div class="wp-tile" title="{{ $ag->token_hint }}…">
                            <span class="wp-initials wp-fam-{{ $wp_ui::family($ag->name) }}" aria-hidden="true">{{ $wp_ui::initials($ag->name) }}<span class="wp-state-dot {{ $ag->usable() ? 'is-synced' : 'is-missing' }}"></span></span>
                            <span class="wp-tile-name">{{ $ag->name }}
                                <span class="wp-tile-note">{{ __('workflowpacks::ai.mode_'.$ag->mode) }}{{ $ag->can_publish ? ' · '.__('workflowpacks::ai.can_publish_short') : '' }}{{ $ag->anonymise ? '' : ' · '.__('workflowpacks::ai.full_text_short') }} · {{ $ag->usable() ? ($ag->last_used_at ? __('workflowpacks::ai.last_used', ['time' => $ag->last_used_at->diffForHumans()]) : __('workflowpacks::ai.never_used')) : __('workflowpacks::ai.disconnected') }}</span>
                            </span>
                            @if ($ag->usable())
                                <form method="POST" action="{{ route('workflowpacks.ai.agents.revoke', ['id' => $ag->id]) }}">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-danger-soft btn-sm" data-wp-confirm="{{ __('workflowpacks::ai.revoke_confirm') }}">{!! $wp_ui::icon('x') !!} {{ __('workflowpacks::ai.revoke') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            <details class="wp-fold" @if (!$agents->count()) open @endif>
                <summary>{{ __('workflowpacks::ai.agent_new') }}</summary>
                <form method="POST" action="{{ route('workflowpacks.ai.agents.create') }}">
                    {{ csrf_field() }}
                    <div class="wp-ai-grid">
                        <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.agent_name') }}</span>
                            <input type="text" name="name" class="form-control" maxlength="100" required placeholder="Claude"></label>
                        <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.mode') }}</span>
                            <select name="mode" class="form-control">
                                <option value="once">{{ __('workflowpacks::ai.mode_once') }}</option>
                                <option value="continuous">{{ __('workflowpacks::ai.mode_continuous') }}</option>
                            </select></label>
                        <label class="wp-ai-field"><span>{{ __('workflowpacks::ai.mailboxes') }}</span>
                            <select name="mailbox_ids[]" class="form-control" multiple size="3" required>
                                @foreach ($mailboxes as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                            </select></label>
                    </div>
                    <label class="wp-ai-check"><input type="checkbox" name="full_text" value="1"> {{ __('workflowpacks::ai.full_text') }}</label>
                    <label class="wp-ai-check"><input type="checkbox" name="can_publish" value="1"> {{ __('workflowpacks::ai.can_publish') }}</label>
                    <p class="wp-ai-warn">{!! $wp_ui::icon('shield') !!} {{ __('workflowpacks::ai.can_publish_warn') }}</p>
                    <div class="wp-pack-actions"><button type="submit" class="btn btn-default">{!! $wp_ui::icon('power') !!} {{ __('workflowpacks::ai.agent_create') }}</button></div>
                </form>
            </details>
        </div>
    </section>

    {{-- 2. the answer of the AI --}}
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('zap') !!}</span>
            <div class="wp-pack-title"><h2 class="wp-pack-name">2. {{ __('workflowpacks::ai.step2') }}</h2>
                <div class="wp-pills"><span class="wp-pill">{{ __('workflowpacks::ai.tickets_kept', ['count' => $ticketCount]) }}</span></div></div>
        </div>
        <form method="POST" action="{{ route('workflowpacks.ai.check') }}" class="wp-pack-body">
            {{ csrf_field() }}
            @include('workflowpacks::partials.help', ['text' => __('workflowpacks::ai.step2_help')])
            <textarea name="answer" rows="10" class="form-control wp-mono" required aria-label="{{ __('workflowpacks::ai.paste') }}" placeholder="{{ __('workflowpacks::ai.paste') }}">{{ $answerText }}</textarea>
            <div class="wp-pack-actions"><button type="submit" class="btn btn-primary">{!! $wp_ui::icon('check') !!} {{ __('workflowpacks::ai.check') }}</button></div>
        </form>
        @if ($answer)
            <div class="wp-pack-body">
                @if ($answer['name'] !== '')<h3 class="wp-rule-name">{{ $answer['name'] }}</h3>@endif
                @if ($answer['description'] !== '')<p class="wp-rule-desc">{{ $answer['description'] }}</p>@endif
                @if ($answer['problems'])
                    <div class="alert alert-warning">
                        <strong>{{ __('workflowpacks::ai.problems', ['count' => count($answer['problems'])]) }}</strong>
                        <ul>@foreach ($answer['problems'] as $p)<li>@if ($p['block'])<code>{{ $p['block'] }}</code>@if ($p['field']) · {{ $p['field'] }}@endif — @endif{{ $p['message'] }}</li>@endforeach</ul>
                        <p>{{ __('workflowpacks::ai.problems_help') }}</p>
                    </div>
                @endif
                @if ($test)
                    <h3 class="wp-boxes-title">{{ __('workflowpacks::ai.results', ['count' => count($test['tickets'])]) }}</h3>
                    @forelse ($test['groups'] as $g)
                        <details class="wp-fold wp-ai-group">
                            <summary><span class="wp-pill wp-pill-indigo">{{ count($g['tickets']) }}</span>
                                {{ $g['changes'] !== '' ? $g['changes'] : __('workflowpacks::ai.no_action') }}</summary>
                            <ul class="wp-ai-tickets">
                                @foreach ($g['tickets'] as $id)
                                    <li><a href="{{ route('conversations.view', ['id' => $id]) }}">#{{ $id }}</a>
                                        <span class="wp-muted">{{ implode(' → ', array_slice($test['tickets'][$id]['summary'], 1, 4)) }}</span></li>
                                @endforeach
                            </ul>
                        </details>
                    @empty
                        <p class="wp-muted">{{ __('workflowpacks::ai.no_tickets') }}</p>
                    @endforelse
                @endif
                @if ($answer['ok'] && !$answer['problems'])
                    <form method="POST" action="{{ route('workflowpacks.ai.create') }}" class="wp-pack-actions">
                        {{ csrf_field() }}
                        <input type="hidden" name="answer" value="{{ $answerText }}">
                        <button type="submit" class="btn btn-primary">{!! $wp_ui::icon('package') !!} {{ __('workflowpacks::ai.create') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </section>
</div>
@endsection
