<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Services\Imap\TicketFolders;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Current e-mail folder of each ticket (workflowpacks_ticket_folders): kept as long as the ticket, read by the other
 * modules, removed with the ticket after them. The filing itself is tested with a real IMAP server in ImapFolderTest.
 */
class TicketFoldersTest extends TestCase
{
    protected function ticket($mailbox)
    {
        $conversation = new \App\Conversation();
        $conversation->type = \App\Conversation::TYPE_EMAIL;
        $conversation->folder_id = (int) optional($mailbox->folders()->first())->id;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->subject = 'Facture';
        $conversation->source_via = \App\Conversation::PERSON_CUSTOMER;
        $conversation->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $conversation->status = \App\Conversation::STATUS_ACTIVE;
        $conversation->state = \App\Conversation::STATE_PUBLISHED;
        $conversation->save();
        $thread = new \App\Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = \App\Thread::TYPE_CUSTOMER;
        $thread->source_via = \App\Thread::PERSON_CUSTOMER;
        $thread->source_type = \App\Thread::SOURCE_TYPE_EMAIL;
        $thread->status = \App\Thread::STATUS_ACTIVE;
        $thread->state = \App\Thread::STATE_PUBLISHED;
        $thread->body = 'Bonjour.';
        $thread->message_id = 'tf-'.self::uid().'@example.test';
        $thread->save();

        return [$conversation, $thread];
    }

    public function testOtherModulesReadTheFolderBeforeItIsRemovedWithTheTicket()
    {
        $a = $this->mailbox('A');
        list($conversation) = $this->ticket($a);
        list($other) = $this->ticket($a);
        TicketFolders::record($conversation->id, $a->id, 'Factures/Fournisseurs', '[WP]', '[WP] Factures/Fournisseurs');
        TicketFolders::record($other->id, $a->id, 'Newsletters', '[WP]', '[WP] Newsletters');

        // a module listening to the deletion with the usual priority still reads it (RefreshGlobal: mail server trash)
        $seen = null;
        \Eventy::addAction('conversations.before_delete_forever', function ($ids) use (&$seen) {
            $seen = \Eventy::filter('workflowpacks.ticket_folders', [], $ids);
        }, 20, 1);
        \App\Conversation::deleteConversationsForever([$conversation->id]);

        $this->assertSame('Factures/Fournisseurs', $seen[$conversation->id]['folder'] ?? null, 'read during the deletion');
        $this->assertNull(TicketFolders::get($conversation->id), 'then removed with the ticket');
        $this->assertNotNull(TicketFolders::get($other->id), 'other tickets kept');
    }

    public function testRowsOfTicketsRemovedOtherwiseGoWithTheDailyCheckAndTheHistoryIsTakenOver()
    {
        $a = $this->mailbox('A');
        list($conversation) = $this->ticket($a);
        TicketFolders::record($conversation->id, $a->id, 'Factures', '[WP]', '[WP] Factures');
        TicketFolders::record(99999999, $a->id, 'Factures', '[WP]', '[WP] Factures');
        $this->assertSame(1, TicketFolders::prune(true));
        $this->assertSame(0, TicketFolders::prune(), 'at most once a day');
        $this->assertNotNull(TicketFolders::get($conversation->id));

        // filings of the history (before the table): the folder of the rule when it exists, else from the path
        list($old) = $this->ticket($a);
        list($older) = $this->ticket($a);
        $wf = \Modules\WorkflowPacks\Services\Workflows\Bridge::newModel();
        $wf->mailbox_id = $a->id;
        $wf->name = 'Rangement '.self::uid();
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]];
        $wf->actions = [[['type' => 'wp_imap_folder', 'value' => 'Voyages/Réservations']]];
        $wf->save();
        \Modules\WorkflowPacks\Services\History::add('imap_file', 'moved', ['mailbox_id' => $a->id, 'conversation_id' => $old->id, 'workflow_id' => $wf->id, 'subject' => '[WP] Voyages.Réservations']);
        \Modules\WorkflowPacks\Services\History::add('imap_file', 'already', ['mailbox_id' => $a->id, 'conversation_id' => $older->id, 'subject' => 'INBOX.[WP] Voyages.Notes']);
        \Modules\WorkflowPacks\Services\History::add('imap_file', 'not_found', ['mailbox_id' => $a->id, 'conversation_id' => $conversation->id, 'subject' => '[WP] Autre']);
        $this->assertSame(2, TicketFolders::backfill());
        $this->assertSame('Voyages/Réservations', TicketFolders::get($old->id)['folder'], 'from the rule');
        $this->assertSame('[WP] Voyages › Réservations', TicketFolders::get($old->id)['label']);
        $this->assertSame('Voyages/Notes', TicketFolders::get($older->id)['folder'], 'from the path, delimiter before the prefix');
        $this->assertSame('Factures', TicketFolders::get($conversation->id)['folder'], 'a ticket already known is kept');
    }
}
