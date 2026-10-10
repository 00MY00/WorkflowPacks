<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Jobs\FileIntoImapFolder;
use Modules\WorkflowPacks\Services\Imap\FolderPath;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;
use Modules\WorkflowPacks\Services\Imap\ImapMover;
use Modules\WorkflowPacks\Services\Workflows\Bridge;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Action "file into an e-mail folder [WP]": option, Workflows hooks, and the real move on an IMAP server
 * (GreenMail container of tests/integration/run.sh, WP_TEST_IMAP_HOST; those tests are skipped without it).
 */
class ImapFolderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(ImapFolders::OPTION_ENABLED, true);
        \Option::set(ImapFolders::OPTION_PREFIX, '[WP]');
        Bridge::forgetCatalogs();
    }

    protected function tearDown(): void
    {
        \Option::set(ImapFolders::OPTION_ENABLED, false);
        Bridge::forgetCatalogs();
        parent::tearDown();
    }

    protected function imapMailbox()
    {
        $host = getenv('WP_TEST_IMAP_HOST');
        if (!$host) {
            $this->markTestSkipped('no IMAP test server (WP_TEST_IMAP_HOST)');
        }
        $mailbox = $this->mailbox('IMAP');
        $mailbox->in_server = $host;
        $mailbox->in_port = 3143;
        $mailbox->in_protocol = \App\Mailbox::IN_PROTOCOL_IMAP;
        $mailbox->in_encryption = \App\Mailbox::IN_ENCRYPTION_NONE;
        $mailbox->in_validate_cert = false;
        $mailbox->in_username = 'box-'.self::uid().'@example.test';
        $mailbox->in_password = 'test-only';
        $mailbox->save();

        return $mailbox;
    }

    /** Puts an e-mail in the INBOX of the test server, returns its Message-ID (without < >). */
    protected function deliver($mailbox, $subject = 'Facture 42')
    {
        $messageId = 'wp-'.self::uid().'@example.test';
        $raw = "From: Supplier <supplier@example.test>\r\nTo: ".$mailbox->email."\r\nSubject: ".$subject."\r\n"
            ."Message-ID: <".$messageId.">\r\nDate: ".date('r')."\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBonjour.\r\n";
        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        $client->getFolderByPath('INBOX')->appendMessage($raw);
        $client->disconnect();

        return $messageId;
    }

    /**
     * Message-IDs found in a folder of the test server (null when the folder does not exist). The path is written
     * with "/" and converted to the delimiter of the server (GreenMail uses ".").
     */
    protected function idsIn($mailbox, $path)
    {
        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        $delimiter = (string) ($client->getFolders(false)->first()->delimiter ?? '/');
        $path = str_replace('/', $delimiter, $path);
        $folder = $client->getFolderByPath(mb_convert_encoding($path, 'UTF7-IMAP', 'UTF-8'));
        $ids = $folder ? $folder->query()->all()->leaveUnread()->get()->map(function ($m) {
            return trim((string) $m->getMessageId(), '<> ');
        })->values()->all() : null;
        $client->disconnect();

        return $ids;
    }

    protected function conversation($mailbox, $messageId)
    {
        $conversation = new \App\Conversation();
        $conversation->type = \App\Conversation::TYPE_EMAIL;
        $conversation->number = random_int(100000, 999999);
        $conversation->folder_id = (int) optional($mailbox->folders()->first())->id;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->subject = 'Facture 42';
        $conversation->preview = 'Bonjour.';
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
        $thread->message_id = $messageId;
        $thread->save();

        return [$conversation, $thread];
    }

    public function testTheActionIsInTheCatalogOnlyWhenTheOptionIsOn()
    {
        $a = $this->mailbox('A');
        $this->assertArrayHasKey(FolderPath::ACTION, Bridge::catalog($a->id)['actions']);
        $this->assertFalse(\Eventy::filter('workflow.validate_action', false, ['type' => FolderPath::ACTION, 'value' => 'Factures/Fournisseurs'], null));
        $this->assertTrue(\Eventy::filter('workflow.validate_action', false, ['type' => FolderPath::ACTION, 'value' => 'Fact*'], null));

        \Option::set(ImapFolders::OPTION_ENABLED, false);
        Bridge::forgetCatalogs();
        $this->assertArrayNotHasKey(FolderPath::ACTION, Bridge::catalog($a->id)['actions']);
        $this->assertTrue(\Eventy::filter('workflow.validate_action', false, ['type' => FolderPath::ACTION, 'value' => 'Factures'], null), 'option off: rule invalid');
        // other actions are left to Workflows and the other modules
        $this->assertFalse(\Eventy::filter('workflow.validate_action', false, ['type' => 'status', 'value' => '3'], null));
    }

    public function testTheSettingsPageShowsTheOptionAndSavesIt()
    {
        $admin = $this->user([], true);
        $this->seeIn($this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks'])), ImapFolders::OPTION_PREFIX);
        $this->actingAs($admin)->post(route('settings.save', ['section' => 'workflowpacks']), ['settings' => [
            ImapFolders::OPTION_ENABLED => '0', ImapFolders::OPTION_PREFIX => '[Tri]*',
        ]])->assertStatus(302);
        $this->assertFalse(ImapFolders::enabled());
        $this->assertSame('[Tri]', ImapFolders::prefix(), 'prefix cleaned when read');
    }

    public function testWorkflowsRunningTheActionQueuesTheMoveOfTheLastEmail()
    {
        \Queue::fake();
        $a = $this->mailbox('A');
        list($conversation, $thread) = $this->conversation($a, 'wp-'.self::uid().'@example.test');
        $performed = \Eventy::filter('workflow.perform_action', false, FolderPath::ACTION, '', 'Factures/Fournisseurs', $conversation, null);
        $this->assertTrue($performed);
        \Queue::assertPushed(FileIntoImapFolder::class, function ($job) use ($a, $thread) {
            return $job->mailboxId === (int) $a->id && $job->threadId === (int) $thread->id && $job->folder === 'Factures/Fournisseurs';
        });
        // another action, or an invalid folder: nothing queued by this module
        $this->assertFalse(\Eventy::filter('workflow.perform_action', false, 'unknown', '', 'x', $conversation, null));
        $this->assertFalse(\Eventy::filter('workflow.perform_action', false, FolderPath::ACTION, '', 'Fact*', $conversation, null));
        \Queue::assertPushed(FileIntoImapFolder::class, 1);
    }

    public function testTheEmailIsMovedIntoTheManagedFolderCreatedWhenMissing()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox);
        $other = $this->deliver($mailbox, 'Autre');
        list(, $thread) = $this->conversation($mailbox, $id);

        (new FileIntoImapFolder($mailbox->id, $thread->id, 'Factures/Fournisseurs'))->handle();

        $this->assertSame([$id], $this->idsIn($mailbox, '[WP] Factures/Fournisseurs'), 'moved into the created folder');
        $this->assertSame([], $this->idsIn($mailbox, '[WP] Factures'), 'parent folder created, empty');
        $this->assertSame([$other], $this->idsIn($mailbox, 'INBOX'), 'only that e-mail left the inbox');

        // run again (rule applied twice): already there, nothing else changes
        $again = (new ImapMover())->move($mailbox, $id, 'Factures/Fournisseurs', '[WP]');
        $this->assertSame('already', $again['result']);
        $this->assertSame([$other], $this->idsIn($mailbox, 'INBOX'));
    }

    public function testAccentsInFolderNamesAndUnknownEmails()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox);
        $r = (new ImapMover())->move($mailbox, $id, 'Reçus/Société', '[WP]');
        $this->assertSame('moved', $r['result'], $r['reason']);
        $this->assertSame([$id], $this->idsIn($mailbox, '[WP] Reçus/Société'), 'UTF-7 IMAP names');

        $this->assertSame('not_found', (new ImapMover())->move($mailbox, 'missing-'.self::uid().'@example.test', 'Factures', '[WP]')['result']);
    }

    public function testNothingHappensWhenTheOptionIsTurnedOffBeforeTheJobRuns()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox);
        list(, $thread) = $this->conversation($mailbox, $id);
        \Option::set(ImapFolders::OPTION_ENABLED, false);

        (new FileIntoImapFolder($mailbox->id, $thread->id, 'Factures'))->handle();

        $this->assertSame([$id], $this->idsIn($mailbox, 'INBOX'));
        $this->assertNull($this->idsIn($mailbox, '[WP] Factures'), 'no folder created');
    }
}
