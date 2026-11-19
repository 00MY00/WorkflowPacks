{{-- The tickets no flow has acted on: filters, how many, the most recent ones, and a temporary flow made for them. --}}
@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_description'){{ __('workflowpacks::temp.intro') }}@endsection

@section('wp_back'){{ route('workflowpacks.flows') }}@endsection
@section('wp_back_label'){{ __('workflowpacks::temp.back') }}@endsection

@section('wp_content')
    <section class="wp-pack-g">
        <div class="wp-pack-head">
            <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('mail') !!}</span>
            <div class="wp-pack-title">
                <h2 class="wp-pack-name">{{ __('workflowpacks::temp.title') }}</h2>
                <div class="wp-pills"><span class="wp-pill wp-pill-indigo">{{ __('workflowpacks::temp.count', ['count' => $total]) }}</span></div>
            </div>
        </div>

        <div class="wp-pack-body">
            <p class="wp-muted">{!! $wp_ui::icon('info') !!} {{ __('workflowpacks::temp.what') }}</p>

            {{-- the filters (GET: the address can be kept) --}}
            <form method="GET" action="{{ route('workflowpacks.untreated') }}">
                <input type="hidden" name="sent" value="1">
                <div class="wp-ai-grid">
                    <div class="wp-ai-field"><span>{{ __('workflowpacks::temp.mailboxes') }}</span>
                        <div class="wp-box-checks">
                            @foreach ($mailboxes as $m)<label><input type="checkbox" name="mailboxes[]" value="{{ $m->id }}" @if (in_array((int) $m->id, $f['mailboxes'], true)) checked @endif> {{ $m->name }}</label>@endforeach
                        </div>
                        <small class="wp-muted">{{ __('workflowpacks::temp.mailboxes_help') }}</small></div>
                    <div class="wp-ai-field"><span>{{ __('workflowpacks::reapply.statuses') }}</span>
                        <div class="wp-reapply-checks">
                            @foreach (\Modules\WorkflowPacks\Services\Engine\TicketScope::STATUSES as $s)
                                <label><input type="checkbox" name="statuses[]" value="{{ $s }}" @if (in_array($s, $f['statuses'], true)) checked @endif> {{ __('workflowpacks::blocks.values.status.'.$s) }}</label>
                            @endforeach
                        </div></div>
                    <label class="wp-ai-field"><span>{{ __('workflowpacks::reapply.period') }}</span>
                        @include('workflowpacks::partials.period_days', ['wp_name' => 'period', 'wp_value' => $f['period']])</label>
                </div>
                <div class="wp-pack-actions"><button type="submit" class="btn btn-default">{!! $wp_ui::icon('check') !!} {{ __('workflowpacks::temp.search') }}</button></div>
            </form>

            @if (!$total)
                <p class="wp-muted">{{ __('workflowpacks::temp.none') }}</p>
            @else
                <h3 class="wp-boxes-title">{{ __('workflowpacks::temp.latest', ['count' => count($tickets)]) }}</h3>
                <div class="table-responsive">
                    <table class="table table-condensed wp-untreated">
                        <thead><tr><th>#</th><th>{{ __('workflowpacks::temp.subject') }}</th><th>{{ __('workflowpacks::temp.mailbox') }}</th><th>{{ __('workflowpacks::temp.status') }}</th><th>{{ __('workflowpacks::temp.created_at') }}</th></tr></thead>
                        <tbody>
                            @foreach ($tickets as $t)
                                <tr>
                                    <td><a href="{{ route('conversations.view', ['id' => $t->id]) }}">{{ $t->number ?: $t->id }}</a></td>
                                    <td>{{ mb_substr((string) $t->subject, 0, 120) }}</td>
                                    <td>{{ optional($t->mailbox)->name }}</td>
                                    <td>{{ __('workflowpacks::blocks.values.status.'.(\Modules\WorkflowPacks\Services\Engine\Context::STATUS[$t->status] ?? 'active')) }}</td>
                                    <td>{{ $t->created_at ? $t->created_at->format('Y-m-d') : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- a temporary flow for these tickets, opened in the editor --}}
                <form method="POST" action="{{ route('workflowpacks.untreated.create') }}" class="wp-untreated-create">
                    {{ csrf_field() }}
                    <input type="hidden" name="sent" value="1">
                    @foreach ($f['mailboxes'] as $id)<input type="hidden" name="mailboxes[]" value="{{ $id }}">@endforeach
                    @foreach ($f['statuses'] as $s)<input type="hidden" name="statuses[]" value="{{ $s }}">@endforeach
                    <input type="hidden" name="period" value="{{ $f['period'] }}">
                    <h3 class="wp-boxes-title">{{ __('workflowpacks::temp.create_title') }}</h3>
                    <div class="wp-ai-grid">
                        <label class="wp-ai-field"><span>{{ __('workflowpacks::temp.name') }}</span>
                            <input type="text" name="name" class="form-control" maxlength="191" placeholder="{{ __('workflowpacks::temp.name_placeholder') }}"></label>
                        <label class="wp-ai-field"><span>{{ __('workflowpacks::temp.from_flow') }}</span>
                            <select name="from_flow" class="form-control">
                                <option value="">{{ __('workflowpacks::temp.from_empty') }}</option>
                                @foreach ($flows as $fl)<option value="{{ $fl->id }}">{{ $fl->name }}</option>@endforeach
                            </select></label>
                    </div>
                    <small class="wp-muted">{{ __('workflowpacks::temp.create_help') }}</small>
                    @if ($total > $max)
                        <p class="wp-ai-warn">{!! $wp_ui::icon('shield') !!} {{ __('workflowpacks::temp.too_many', ['max' => $max]) }}</p>
                    @endif
                    <div class="wp-actionbar">
                        <button type="submit" class="btn btn-primary" @if ($total > $max) disabled @endif>{!! $wp_ui::icon('zap') !!} {{ __('workflowpacks::temp.create', ['count' => min($total, $max)]) }}</button>
                    </div>
                </form>
            @endif
        </div>
    </section>
@endsection
