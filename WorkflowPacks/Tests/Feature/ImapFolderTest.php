<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Jobs\FileIntoImapFolder;
use Modules\WorkflowPacks\Services\Imap\ImapFolders;
use Modules\WorkflowPacks\Services\Imap\ImapMover;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * "File into an e-mail folder [WP]" (block of the flows): option, queue, history, and the real move on an IMAP server
 * (GreenMail container of tests/integration/run.sh, WP_TEST_IMAP_HOST; those tests are skipped without it).
 */
class ImapFolderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(ImapFolders::OPTION_ENABLED, true);
        \Option::set(ImapFolders::OPTION_PREFIX, '[WP]');
    }

    protected function tearDown(): void
    {
        \Option::set(ImapFolders::OPTION_ENABLED, false);
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

    public function testTheMoveOfTheLastEmailIsQueued()
    {
        \Queue::fake();
        $a = $this->mailbox('A');
        list($conversation, $thread) = $this->conversation($a, 'wp-'.self::uid().'@example.test');
        // mailbox without IMAP settings: nothing to file on a server, nothing queued
        $this->assertFalse(ImapFolders::queue($conversation, 'Factures'));
        \Queue::assertNotPushed(FileIntoImapFolder::class);

        $a->in_server = 'imap.example.test';
        $a->in_username = 'box@example.test';
        $a->save();
        $conversation->load('mailbox');
        $performed = ImapFolders::queue($conversation, 'Factures/Fournisseurs');
        $this->assertTrue($performed);
        \Queue::assertPushed(FileIntoImapFolder::class, function ($job) use ($a, $thread) {
            return $job->mailboxId === (int) $a->id && $job->threadId === (int) $thread->id && $job->folder === 'Factures/Fournisseurs';
        });
        // an invalid folder: nothing queued
        $this->assertFalse(ImapFolders::queue($conversation, 'Fact*'));
        \Queue::assertPushed(FileIntoImapFolder::class, 1);
    }

    public function testTheFlowBlockFilesTheEmailOnTheAddressChosen()
    {
        \Queue::fake();
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $b->in_server = 'imap.example.test';
        $b->in_username = 'alias@example.test';
        $b->save();
        list($conversation, $thread) = $this->conversation($a, 'wp-'.self::uid().'@example.test');
        // the mailbox where it arrived has no IMAP; the address chosen has one: filed there
        $this->assertFalse(ImapFolders::queue($conversation, 'Factures'));
        $this->assertTrue(ImapFolders::queue($conversation, 'Factures', $b->id));
        \Queue::assertPushed(FileIntoImapFolder::class, function ($job) use ($b, $thread) {
            return $job->mailboxId === (int) $b->id && $job->threadId === (int) $thread->id && $job->folder === 'Factures';
        });
        // the block: an address that does not exist is a problem to fix before publishing
        $this->assertArrayHasKey('mailbox', \Modules\WorkflowPacks\Blocks\Actions\ImapFolder::validate(['path' => 'Factures', 'mailbox' => 999999]));
        $this->assertSame([], \Modules\WorkflowPacks\Blocks\Actions\ImapFolder::validate(['path' => 'Factures', 'mailbox' => $b->id]));
        $this->assertSame([], \Modules\WorkflowPacks\Blocks\Actions\ImapFolder::validate(['path' => 'Factures']), 'no address: where it arrived');
    }

    public function testTheFoldersOfTheModuleOnAnAddressAreListedForTheEditor()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox);
        $prefix = ImapFolders::prefix();
        $this->assertSame('moved', (new ImapMover())->move($mailbox, $id, 'Factures/Fournisseurs', $prefix)['result']);
        \Cache::forget('workflowpacks.imap_folders.'.$mailbox->id.'.'.md5($prefix));
        $listed = ImapFolders::managedFolders($mailbox);
        $this->assertTrue($listed['ok'], $listed['error']);
        $this->assertSame(['Factures', 'Factures/Fournisseurs'], $listed['folders'], 'as written in a block, the folders of the user left out');

        \Option::set(\Modules\WorkflowPacks\Services\Engine\Engine::OPTION, true);
        $admin = $this->user([$mailbox], true);
        $json = json_decode($this->actingAs($admin)->get(route('workflowpacks.flows.imap_folders', ['mailbox_id' => $mailbox->id]))->getContent(), true);
        $this->assertSame(['Factures', 'Factures/Fournisseurs'], $json['folders']);
        $this->actingAs($this->user([$mailbox], false, true))->get(route('workflowpacks.flows.imap_folders', ['mailbox_id' => $mailbox->id]))->assertStatus(403);
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

    public function testAFolderAlreadyCreatedIsReusedAndNewFoldersAreSubscribed()
    {
        $mailbox = $this->imapMailbox();
        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        $delimiter = (string) ($client->getFolders(false)->first()->delimiter ?? '/');
        // created meanwhile by another job (or by hand)
        $client->createFolder('[WP] Factures', false);
        $client->disconnect();

        $id = $this->deliver($mailbox);
        $r = (new ImapMover())->move($mailbox, $id, 'Factures/Fournisseurs', '[WP]');
        $this->assertSame('moved', $r['result'], $r['reason']);
        $this->assertSame([$id], $this->idsIn($mailbox, '[WP] Factures/Fournisseurs'));

        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        // subscribed folders (standard LSUB command): what mail clients display
        $lsub = $client->getConnection()->requestAndResponse('LSUB', ['""', '"*"']);
        $client->disconnect();
        $subscribed = [];
        foreach (is_array($lsub) ? $lsub : [] as $item) {
            if (is_array($item) && ($item[0] ?? '') === 'LSUB' && isset($item[3])) {
                $subscribed[] = $item[3];
            }
        }
        $this->assertContains('[WP] Factures'.$delimiter.'Fournisseurs', $subscribed, 'new folder subscribed');
    }

    public function testInvalidValuesNeverReachTheServer()
    {
        $mailbox = $this->mailbox('No IMAP');
        $this->assertSame(['result' => 'failed', 'folder' => '', 'reason' => 'imap_folder_chars', 'retry' => false],
            (new ImapMover())->move($mailbox, 'x@example.test', 'Fact*', '[WP]'));
        $this->assertFalse((new ImapMover())->move($mailbox, 'x@example.test', 'Factures', '[WP]')['retry'], 'no IMAP settings: no retry');
    }

    public function testTheSettingsSwitchesAreSavedWhenChanged()
    {
        $admin = $this->user([], true);
        $page = $this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks']));
        $this->seeIn($page, 'id="wp-settings-form"');
        $this->seeIn($page, "addEventListener('change'");
        $this->seeIn($page, e(__('workflowpacks::messages.settings_autosave')));
    }

    /** Puts an e-mail dated $daysAgo days ago in a folder of the test server ("/" between the levels), created if needed. */
    protected function appendTo($mailbox, $path, $daysAgo)
    {
        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        $delimiter = (string) ($client->getFolders(false)->first()->delimiter ?? '/');
        $server = mb_convert_encoding(str_replace('/', $delimiter, $path), 'UTF7-IMAP', 'UTF-8');
        if (!$client->getFolderByPath($server)) {
            $client->createFolder($server, false);
        }
        $date = \Carbon\Carbon::now()->subDays($daysAgo);
        $messageId = 'old-'.self::uid().'@example.test';
        $raw = "From: News <news@example.test>\r\nTo: ".$mailbox->email."\r\nSubject: Newsletter\r\nMessage-ID: <".$messageId.">\r\n"
            ."Date: ".$date->format('r')."\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nPromo.\r\n";
        $client->getFolderByPath($server)->appendMessage($raw, null, $date);
        $client->disconnect();

        return $messageId;
    }

    public function testCleanUpSettingsAreForAdministratorsAndShownInTheSettings()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $agent = $this->user([$a], false, true);
        $save = route('workflowpacks.retention.save');

        $this->actingAs($agent)->postJson($save, ['folder' => 'Newsletters', 'number' => 7, 'metric' => 'd', 'mode' => 'trash'])->assertStatus(403);
        $this->actingAs($admin)->postJson($save, ['folder' => 'Fact*', 'number' => 7, 'metric' => 'd', 'mode' => 'trash'])->assertStatus(422);
        $this->actingAs($admin)->postJson($save, ['folder' => 'Newsletters', 'number' => 0, 'metric' => 'd', 'mode' => 'trash'])->assertStatus(422);
        $this->actingAs($admin)->postJson($save, ['folder' => 'Newsletters', 'number' => 7, 'metric' => 'd', 'mode' => 'shred'])->assertStatus(422);
        $this->actingAs($admin)->postJson($save, ['folder' => ' Newsletters / Promos ', 'number' => '7', 'metric' => 'd', 'mode' => 'trash'])->assertStatus(200);
        $this->assertSame(['Newsletters/Promos', 7, 'd', 'trash'], [
            \Modules\WorkflowPacks\Entities\Retention::first()->folder, (int) \Modules\WorkflowPacks\Entities\Retention::first()->number,
            \Modules\WorkflowPacks\Entities\Retention::first()->metric, \Modules\WorkflowPacks\Entities\Retention::first()->mode,
        ], 'folder written as in the flows');

        // settings: listed with a "Remove" button
        $this->seeIn($this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks'])), e(__('workflowpacks::messages.retention_remove_btn')));

        $this->actingAs($admin)->post(route('workflowpacks.retention.remove'), ['folder' => 'Newsletters/Promos'])->assertRedirect();
        $this->assertSame(0, \Modules\WorkflowPacks\Entities\Retention::count());
    }

    public function testOldEmailsOfACleanedFolderGoToTheTrashAndOnlyThem()
    {
        $mailbox = $this->imapMailbox();
        $old = $this->appendTo($mailbox, '[WP] Newsletters', 10);
        $new = $this->appendTo($mailbox, '[WP] Newsletters', 1);
        $mine = $this->appendTo($mailbox, 'Newsletters', 10);          // a folder of the user, same name, no prefix
        $this->appendTo($mailbox, 'Trash', 400);                           // the trash of the account (with an old e-mail)
        \Modules\WorkflowPacks\Services\Imap\Retention::save('Newsletters', ['number' => 7, 'metric' => 'd'], 'trash', null);

        $total = (new \Modules\WorkflowPacks\Services\Imap\Retention())->run();

        $this->assertSame(1, $total['moved']);
        $this->assertSame([$new], $this->idsIn($mailbox, '[WP] Newsletters'), 'only the e-mail older than 7 days left');
        $this->assertContains($old, $this->idsIn($mailbox, 'Trash'), 'moved to the trash, not lost');
        $this->assertSame([$mine], $this->idsIn($mailbox, 'Newsletters'), 'folder of the user never touched');
        $this->assertCount(2, $this->idsIn($mailbox, 'Trash'), 'the trash itself is never cleaned');
    }

    public function testDeleteModeAndGuards()
    {
        $mailbox = $this->imapMailbox();
        $old = $this->appendTo($mailbox, '[WP] Spam', 3);
        $recent = $this->appendTo($mailbox, '[WP] Spam', 0);
        \Modules\WorkflowPacks\Services\Imap\Retention::save('Spam', ['number' => 48, 'metric' => 'h'], 'delete', null);
        \Modules\WorkflowPacks\Services\Imap\Retention::save('Absent', ['number' => 1, 'metric' => 'd'], 'delete', null);

        // option off: nothing at all
        \Option::set(ImapFolders::OPTION_ENABLED, false);
        $this->assertSame(['moved' => 0, 'deleted' => 0, 'errors' => 0], (new \Modules\WorkflowPacks\Services\Imap\Retention())->run());
        $this->assertCount(2, $this->idsIn($mailbox, '[WP] Spam'));

        // option on: deleted for good (hours), the recent one kept; a folder that does not exist is not created
        \Option::set(ImapFolders::OPTION_ENABLED, true);
        $total = (new \Modules\WorkflowPacks\Services\Imap\Retention())->run();
        $this->assertSame(1, $total['deleted']);
        $this->assertSame([$recent], $this->idsIn($mailbox, '[WP] Spam'));
        $this->assertNotContains($old, (array) $this->idsIn($mailbox, 'Trash'));
        $this->assertNull($this->idsIn($mailbox, '[WP] Absent'), 'nothing created');
        $this->assertSame(0, $total['errors']);
    }

    public function testEveryFilingIsInTheHistoryWithItsResult()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox);
        list($conversation, $thread) = $this->conversation($mailbox, $id);

        // Workflows calls the action: "requested", then the job completes the same entry
        \Queue::fake();
        $this->assertTrue(ImapFolders::queue($conversation, 'Factures/Fournisseurs'));
        $entry = \Modules\WorkflowPacks\Entities\Activity::where('kind', 'imap_file')->orderBy('id', 'desc')->first();
        $this->assertSame(['queued', (int) $conversation->id], [$entry->status, (int) $entry->conversation_id]);
        $job = null;
        \Queue::assertPushed(FileIntoImapFolder::class, function ($j) use (&$job) {
            $job = $j;

            return true;
        });
        $job->handle();
        $entry = $entry->fresh();
        $this->assertSame('moved', $entry->status);
        $this->assertStringContainsString('Fournisseurs', $entry->subject, 'folder on the server');

        // nothing queued: the reason is written
        $plain = $this->mailbox('No IMAP');
        list($other) = $this->conversation($plain, 'x-'.self::uid().'@example.test');
        $this->assertFalse(ImapFolders::queue($other, 'Factures'));
        $skipped = \Modules\WorkflowPacks\Entities\Activity::orderBy('id', 'desc')->first();
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame(__('workflowpacks::messages.history_reason_no_imap'), $skipped->message);
    }

    public function testTheMailServerFoldersCanBeLookedAtByAdministrators()
    {
        $mailbox = $this->imapMailbox();
        $this->appendTo($mailbox, '[WP] Factures', 1);
        $this->appendTo($mailbox, 'Trash', 1);
        $admin = $this->user([], true);

        $page = $this->actingAs($admin)->get(route('workflowpacks.history.inspect', ['imap_mailbox' => $mailbox->id]))->assertStatus(200);
        $this->seeIn($page, '[WP] Factures');
        $this->seeIn($page, e(__('workflowpacks::messages.history_inspect_managed')));
        $this->seeIn($page, 'glyphicon-trash');
        $this->seeIn($page, e(__('workflowpacks::messages.history_inspect_delimiter')));
    }

    public function testFoldersAreNeverCreatedInsideTheTrash()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox);
        $this->appendTo($mailbox, 'Trash', 1);
        // "under INBOX" while the trash of this test account is INBOX itself? Simulated: the parent is the trash folder
        \Option::set(ImapFolders::OPTION_PARENT, 'inbox');
        $mover = new class() extends ImapMover {
            public function layout($client)
            {
                $layout = parent::layout($client);
                $layout['parent'] = $layout['trash'][0];

                return $layout;
            }
        };
        $r = $mover->move($mailbox, $id, 'Factures', '[WP]');
        $this->assertSame('failed', $r['result']);
        $this->assertStringContainsString('trash', $r['reason']);
        $this->assertSame([$id], $this->idsIn($mailbox, 'INBOX'), 'nothing moved');
        \Option::set(ImapFolders::OPTION_PARENT, 'auto');
    }


    protected function lastFiling($conversation)
    {
        return \Modules\WorkflowPacks\Entities\Activity::where('kind', 'imap_file')->where('conversation_id', $conversation->id)->orderBy('id', 'desc')->first();
    }

    /** Mail server whose search by Message-ID answers nothing (some servers do): only reading the headers finds it. */
    protected function serverWithoutSearch()
    {
        app()->bind(\Modules\WorkflowPacks\Services\Imap\MessageFinder::class, function () {
            return new class extends \Modules\WorkflowPacks\Services\Imap\MessageFinder {
                protected function bySearch($folder, $id, $charset)
                {
                    return null;
                }
            };
        });
    }

    public function testAnEmailIsFoundByItsHeadersWhenTheServerSearchAnswersNothing()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox);
        $other = $this->deliver($mailbox, 'Autre');
        list($conversation, $thread) = $this->conversation($mailbox, $id);
        $this->serverWithoutSearch();

        // the job gives the date of the e-mail: the headers of those days are read
        (new FileIntoImapFolder($mailbox->id, $thread->id, 'Factures'))->handle();
        $this->assertSame([$id], $this->idsIn($mailbox, '[WP] Factures'));
        $this->assertSame([$other], $this->idsIn($mailbox, 'INBOX'));
        // already in its folder: seen the same way, nothing moves
        $again = (new ImapMover())->move($mailbox, $id, 'Factures', '[WP]', false, $thread->created_at);
        $this->assertSame('already', $again['result']);

        // e-mail that is nowhere: "not found", with the folders looked in
        $missing = (new ImapMover())->move($mailbox, 'nowhere-'.self::uid().'@example.test', 'Factures', '[WP]', false, $thread->created_at);
        $this->assertSame('not_found', $missing['result']);
        $this->assertStringContainsString('INBOX', $missing['reason']);
        app()->forgetInstance(\Modules\WorkflowPacks\Services\Imap\MessageFinder::class);
        $this->assertNotNull($conversation);
    }

    public function testATicketCanBeTestedStepByStepByAdministrators()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->deliver($mailbox, 'Facture 77');
        list($conversation) = $this->conversation($mailbox, $id);
        $admin = $this->user([], true);

        $page = $this->actingAs($admin)->get(route('workflowpacks.history.trace', ['conversation' => $conversation->id]));
        $page->assertStatus(200);
        $this->seeIn($page, e(__('workflowpacks::messages.history_trace_found', ['folder' => 'INBOX'])));
        $this->seeIn($page, $id);
        $this->seeIn($page, e(__('workflowpacks::messages.history_trace_step_search')));
        $this->seeIn($page, 'Facture 77');

        // by the number shown in FreeScout, and a server without search: found by the headers
        $this->serverWithoutSearch();
        $shown = method_exists(\App\Conversation::class, 'numberFieldName') ? $conversation->fresh()->{\App\Conversation::numberFieldName()} : $conversation->fresh()->number;
        $page = $this->actingAs($admin)->get(route('workflowpacks.history.trace', ['number' => $shown]));
        $this->seeIn($page, e(__('workflowpacks::messages.history_trace_step_headers')));
        $this->seeIn($page, e(__('workflowpacks::messages.history_trace_found', ['folder' => 'INBOX'])));
        app()->forgetInstance(\Modules\WorkflowPacks\Services\Imap\MessageFinder::class);

        // unknown ticket, and not for other users
        $this->actingAs($admin)->get(route('workflowpacks.history.trace', ['number' => 99999999]))
            ->assertSessionHas('flash_error_floating', __('workflowpacks::messages.history_trace_unknown'));
        $this->actingAs($this->user([$mailbox], false, true))->get(route('workflowpacks.history.trace', ['conversation' => $conversation->id]))->assertStatus(403);
    }

    /**
     * What the module adds to the page of a ticket, next to its subject (FreeScout's "conversation.after_subject"; the
     * whole page cannot be rendered with the minimal tickets of these tests).
     */
    protected function ticketPage($conversation)
    {
        ob_start();
        \Eventy::action('conversation.after_subject', $conversation->fresh(), $conversation->mailbox);

        return ob_get_clean();
    }

    public function testTheCurrentFolderOfTheTicketIsKeptShownAndUpdatedByTheCleanUp()
    {
        $mailbox = $this->imapMailbox();
        $id = $this->appendTo($mailbox, 'INBOX', 10);
        list($conversation, $thread) = $this->conversation($mailbox, $id);
        $changes = [];
        \Eventy::addAction('workflowpacks.ticket_folder_changed', function ($cid, $current, $previous) use (&$changes) {
            $changes[] = [$cid, $current ? $current['state'] : null, $previous ? $previous['state'] : null];
        }, 20, 3);

        (new FileIntoImapFolder($mailbox->id, $thread->id, 'Newsletters'))->handle();

        $folder = \Eventy::filter('workflowpacks.ticket_folder', null, $conversation->id);
        $this->assertSame(['Newsletters', '[WP] Newsletters', 'filed'], [$folder['folder'], $folder['label'], $folder['state']]);
        $this->assertStringContainsString('Newsletters', $folder['path'], 'folder on the server');
        $this->assertSame([(int) $conversation->id], array_keys(\Eventy::filter('workflowpacks.ticket_folders', [], [$conversation->id, 999999])));
        $this->assertSame([[(int) $conversation->id, 'filed', null]], $changes);

        // shown in the ticket, next to its subject
        $page = $this->ticketPage($conversation);
        $this->assertStringContainsString('wp-ticket-folder', $page);
        $this->assertStringContainsString('[WP] Newsletters', $page);

        // filed again: same row, no change announced
        (new FileIntoImapFolder($mailbox->id, $thread->id, 'Newsletters'))->handle();
        $this->assertSame(1, \DB::table('workflowpacks_ticket_folders')->where('conversation_id', $conversation->id)->count());
        $this->assertCount(1, $changes);

        // the automatic clean-up of the folder removes the e-mail: the ticket is no longer there
        \Modules\WorkflowPacks\Services\Imap\Retention::save('Newsletters', ['number' => 7, 'metric' => 'd'], 'trash', null);
        (new \Modules\WorkflowPacks\Services\Imap\Retention())->run();
        $folder = \Modules\WorkflowPacks\Services\Imap\TicketFolders::get($conversation->id);
        $this->assertSame('cleaned', $folder['state']);
        $this->assertNotNull($folder['cleaned_at']);
        $this->assertSame([(int) $conversation->id, 'cleaned', 'filed'], end($changes));
        $this->assertStringContainsString(e(__('workflowpacks::messages.ticket_folder_cleaned')), $this->ticketPage($conversation));
        \Modules\WorkflowPacks\Services\Imap\Retention::remove('Newsletters');
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
