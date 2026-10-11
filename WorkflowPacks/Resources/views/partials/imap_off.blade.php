{{--
    The pack files e-mails into folders while the option is off (included inside the forms of the import steps, so
    that "Turn it on" posts everything already chosen and shows the preview again with the option on).
--}}
@if (!empty($imapOff))
    <div class="alert alert-warning wp-imap-off margin-top">
        <strong>{{ __('workflowpacks::messages.imap_off_title') }}</strong>
        {{ __('workflowpacks::messages.imap_off_text') }}
        @if (auth()->user()->isAdmin())
            <button type="submit" name="enable_imap" value="1" formaction="{{ route('workflowpacks.import.preview') }}" class="btn btn-warning btn-xs">
                {{ __('workflowpacks::messages.imap_off_enable') }}
            </button>
        @else
            <em>{{ __('workflowpacks::messages.imap_off_ask') }}</em>
        @endif
    </div>
@endif
