@extends('workflowpacks::layout')

@php $wp_ui = '\Modules\WorkflowPacks\Services\Ui'; @endphp

@section('wp_description'){{ __('workflowpacks::messages.intro') }}@endsection

@section('wp_actions')
    @if ($mailboxes->count() > 1)
        <form method="GET" action="{{ route('workflowpacks.index') }}" class="wp-inline">
            <select name="mailbox_id" class="form-control wp-autosubmit" aria-label="{{ __('workflowpacks::messages.all_mailboxes') }}">
                <option value="">{{ __('workflowpacks::messages.all_mailboxes') }}</option>
                @foreach ($mailboxes as $mailbox)
                    <option value="{{ $mailbox->id }}" @if ($filter === (int) $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                @endforeach
            </select>
        </form>
    @endif
    <a class="btn btn-default" href="{{ route('workflowpacks.export', $filter ? ['mailbox_id' => $filter] : []) }}">{!! $wp_ui::icon('download') !!} {{ __('workflowpacks::messages.export') }}</a>
    <a class="btn btn-primary" href="{{ route('workflowpacks.import', $filter ? ['mailbox_id' => $filter] : []) }}">{!! $wp_ui::icon('upload') !!} {{ __('workflowpacks::messages.import') }}</a>
@endsection

@section('wp_content')
    @if (\Modules\WorkflowPacks\Services\Compat\Checker::packsBlocked())
        @include('workflowpacks::partials.blocked_inline')
    @endif

    @if ($packs && !\Modules\WorkflowPacks\Services\Compat\Checker::packsBlocked())
        {{-- the ticked rules (form="wp-bulk"): a floating bar, only while something is ticked (workflowpacks.js) --}}
        <form id="wp-bulk" method="POST" action="{{ route('workflowpacks.rules.toggle') }}" class="wp-selbar" aria-live="polite">
            {{ csrf_field() }}
            @if ($filter)<input type="hidden" name="mailbox_id" value="{{ $filter }}">@endif
            <span class="wp-selbar-count" data-wp-bulk-count>0</span>
            <span>{{ __('workflowpacks::messages.selected_rules') }}</span>
            <button type="button" class="wp-selbar-btn wp-selbar-clear" data-wp-clear title="{{ __('workflowpacks::messages.clear_selection') }}" aria-label="{{ __('workflowpacks::messages.clear_selection') }}">{!! $wp_ui::icon('x') !!}</button>
            <span class="wp-selbar-sep" aria-hidden="true"></span>
            <button type="submit" name="active" value="1" class="wp-selbar-btn is-primary" data-wp-bulk-action>{!! $wp_ui::icon('power') !!} {{ __('workflowpacks::messages.enable_selected') }}</button>
            <button type="submit" name="active" value="0" class="wp-selbar-btn" data-wp-bulk-action>{!! $wp_ui::icon('power') !!} {{ __('workflowpacks::messages.disable_selected') }}</button>
            <button type="submit" name="previous" value="1" class="wp-selbar-btn" data-wp-bulk-action title="{{ __('workflowpacks::messages.previous_help') }}"
                data-wp-confirm="{{ __('workflowpacks::messages.previous_confirm') }}">{!! $wp_ui::icon('clock') !!} {{ __('workflowpacks::messages.previous_selected_on') }}</button>
            <button type="submit" name="previous" value="0" class="wp-selbar-btn" data-wp-bulk-action>{!! $wp_ui::icon('clock') !!} {{ __('workflowpacks::messages.previous_selected_off') }}</button>
            @if (\Modules\WorkflowPacks\Services\Imap\ImapFolders::enabled())
                <button type="submit" name="refile" value="1" class="wp-selbar-btn" data-wp-bulk-action title="{{ __('workflowpacks::messages.refile_help') }}"
                    data-wp-confirm="{{ __('workflowpacks::messages.refile_confirm') }}">{!! $wp_ui::icon('folder') !!} {{ __('workflowpacks::messages.refile_selected') }}</button>
            @endif
        </form>
    @endif

    <div class="wp-packs">
    @forelse ($packs as $entry)
        <section class="wp-pack-g wp-pack">
            <div class="wp-pack-head">
                @if (!\Modules\WorkflowPacks\Services\Compat\Checker::packsBlocked())
                    <input type="checkbox" class="wp-bulk-pack" data-wp-check-all=".wp-bulk-check" data-wp-scope=".wp-pack" title="{{ __('workflowpacks::messages.select_pack') }}" aria-label="{{ __('workflowpacks::messages.select_pack') }}">
                @endif
                <span class="wp-square wp-square-40 wp-square-pack">{!! $wp_ui::icon('package') !!}</span>
                <div class="wp-pack-title">
                    <h2 class="wp-pack-name">{{ $entry['pack']->name }}</h2>
                    <div class="wp-pills">
                        <span class="wp-pill wp-pill-slug">{{ $entry['pack']->pack_key }}</span>
                        @if ($entry['pack']->pack_version !== '')<span class="wp-pill">{{ __('workflowpacks::messages.version') }} {{ $entry['pack']->pack_version }}</span>@endif
                        <span class="wp-pill wp-pill-indigo">{{ count($entry['rules']) }} {{ __('workflowpacks::messages.rules') }}</span>
                    </div>
                </div>
                @if (Auth::user()->isAdmin() && !\Modules\WorkflowPacks\Services\Compat\Checker::workflowsReady() && !\Modules\WorkflowPacks\Services\Compat\Checker::coreBlocking())
                    {{-- without Workflows: forget the pack (what Workflow Packs keeps of it), after a backup --}}
                    <form method="POST" action="{{ route('workflowpacks.forget', ['key' => $entry['pack']->pack_key]) }}" class="wp-pack-actions">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-danger-soft" title="{{ __('workflowpacks::messages.forget_help') }}"
                            data-wp-confirm="{{ __('workflowpacks::messages.forget_confirm', ['name' => $entry['pack']->name]) }}">{!! $wp_ui::icon('trash') !!} {{ __('workflowpacks::messages.remove') }}</button>
                    </form>
                @endif
                @if (!\Modules\WorkflowPacks\Services\Compat\Checker::packsBlocked())
                    <div class="wp-pack-actions">
                        <a class="btn btn-default" href="{{ route('workflowpacks.reimport', ['key' => $entry['pack']->pack_key]) }}">{!! $wp_ui::icon('refresh') !!} {{ __('workflowpacks::messages.reimport') }}</a>
                        <a class="btn btn-danger-soft" href="{{ route('workflowpacks.remove', ['key' => $entry['pack']->pack_key]) }}">{!! $wp_ui::icon('trash') !!} {{ __('workflowpacks::messages.remove') }}</a>
                    </div>
                @endif
            </div>
            <div class="wp-pack-body">
                {{-- the description of the pack, cut into info blocks (one idea each, nothing removed) --}}
                @if ($entry['pack']->description)
                    <div class="wp-infos">
                        @foreach ($wp_ui::infoBlocks($entry['pack']->description) as $info)
                            <div class="wp-info">
                                <span class="wp-square">{!! $wp_ui::icon($info['icon']) !!}</span>
                                <div>
                                    @if ($info['title'] !== '')<strong class="wp-info-title">{{ $info['title'] }}</strong>@endif
                                    <span class="wp-info-text">{{ $info['text'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @foreach ($entry['rules'] as $r)
                    @include('workflowpacks::partials.rule_schema', ['rule' => $r['rule'], 'states' => $r['states'], 'mailboxId' => null, 'toggles' => !\Modules\WorkflowPacks\Services\Compat\Checker::packsBlocked(), 'filter' => $filter])
                @endforeach
            </div>
        </section>
    @empty
        <div class="wp-empty">{{ __('workflowpacks::messages.no_packs') }}</div>
    @endforelse
    </div>
@endsection
