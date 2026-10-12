{{-- Current e-mail folder of the ticket, next to its subject (TicketFolders::register(), "conversation.after_subject") --}}
<span class="conv-tags wp-ticket-folder">
    <span class="fs-tag fs-tag-md @if ($folder['state'] !== 'filed') wp-ticket-folder-cleaned @endif"
        title="{{ __('workflowpacks::messages.ticket_folder_title') }}{{ $folder['path'] !== '' ? ' : '.$folder['path'] : '' }}@if ($folder['filed_at']) · {{ __('workflowpacks::messages.ticket_folder_filed_at', ['date' => $folder['filed_at']]) }}@endif">
        <span class="fs-tag-name"><small class="glyphicon glyphicon-folder-close" aria-hidden="true"></small>
            {{ $folder['label'] }}@if ($folder['state'] !== 'filed') <small>({{ __('workflowpacks::messages.ticket_folder_cleaned') }})</small>@endif</span>
    </span>
</span>
