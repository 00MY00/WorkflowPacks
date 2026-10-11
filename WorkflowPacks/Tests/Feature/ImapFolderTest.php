<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Jobs\FileIntoImapFolder;
use Modules\WorkflowPacks\Jobs\RefileMailbox;
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
    /** jobs already run by runQueued() */
    protected $ranJobs = [];

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
        // mailbox without IMAP settings: nothing to file on a server, nothing queued
        $this->assertFalse(\Eventy::filter('workflow.perform_action', false, FolderPath::ACTION, '', 'Factures', $conversation, null));
        \Queue::assertNotPushed(FileIntoImapFolder::class);

        $a->in_server = 'imap.example.test';
        $a->in_username = 'box@example.test';
        $a->save();
        $conversation->load('mailbox');
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

    public function testAnImportNeedingTheOptionOffersToTurnItOn()
    {
        \Option::set(ImapFolders::OPTION_ENABLED, false);
        Bridge::forgetCatalogs();
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $agent = $this->user([$a], false, true);
        $pack = $this->pack([$this->rule('r1', ['actions' => [['type' => FolderPath::ACTION, 'value' => 'Factures/Fournisseurs']]])]);
        $form = ['pack_json' => json_encode($pack), 'mailboxes' => [$a->id]];

        // step 2: notice + button for an administrator, "ask an administrator" for the others
        $page = $this->actingAs($admin)->post(route('workflowpacks.import.configure'), ['pack_json' => json_encode($pack)]);
        $this->seeIn($page, 'name="enable_imap"');
        $other = $this->actingAs($agent)->post(route('workflowpacks.import.configure'), ['pack_json' => json_encode($pack)]);
        $this->assertStringNotContainsString('name="enable_imap"', $other->getContent());
        $this->seeIn($other, e(__('workflowpacks::messages.imap_off_ask')));

        // the button of someone who may not change the settings does nothing
        $this->actingAs($agent)->post(route('workflowpacks.import.preview'), $form + ['enable_imap' => '1']);
        $this->assertFalse(ImapFolders::enabled());

        // an administrator: option on, and the preview at once shows the rule as created (no longer blocked)
        $preview = $this->actingAs($admin)->post(route('workflowpacks.import.preview'), $form + ['enable_imap' => '1'])->assertStatus(200);
        $this->assertTrue(ImapFolders::enabled());
        $this->assertStringNotContainsString('name="enable_imap"', $preview->getContent(), 'notice gone');
        $this->assertStringNotContainsString(e(__('workflowpacks::texts.imap_disabled')), $preview->getContent());
        $this->seeIn($preview, 'name="token"');
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

    public function testCleanUpSettingsAreForAdministratorsAndShownInTheDiagramAndTheSettings()
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
        ], 'folder written as in the rules');

        // diagram: shown on the folder block; settings: listed with a "Remove" button
        $class = Bridge::modelClass();
        $wf = new $class();
        $wf->mailbox_id = $a->id;
        $wf->name = 'Promos';
        $wf->type = 1;
        $wf->apply_to_prev = false;
        $wf->max_executions = 1;
        $wf->conditions = [[['type' => 'subject', 'operator' => 'contains', 'value' => 'promo']]];
        $wf->actions = [[['type' => FolderPath::ACTION, 'value' => 'Newsletters/Promos']]];
        $wf->save();
        $node = collect($this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json()['nodes'])->where('dest', 'imap')->firstWhere('label', 'Promos');
        $this->assertSame(['number' => 7, 'metric' => 'd', 'mode' => 'trash'], $node['retention']);
        $this->assertStringContainsString(__('workflowpacks::messages.retention_short', ['delay' => __('workflowpacks::texts.timer_d', ['count' => 7])]), $node['sub']);
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

    /** A rule of the mailbox: by default "subject contains facture" -> folder "Factures" and close the ticket. */
    protected function filingRule($mailbox, $actions = null, $conditions = null, $sortOrder = 10)
    {
        $wf = Bridge::newModel();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = 'Règle '.self::uid();
        $wf->type = 1;
        $wf->apply_to_prev = false;
        $wf->max_executions = 1;
        $wf->conditions = $conditions ?: [[['type' => 'subject', 'operator' => 'contains', 'value' => 'facture']]];
        $wf->actions = $actions ?: [[['type' => FolderPath::ACTION, 'value' => 'Factures'], ['type' => 'status', 'value' => '3']]];
        $wf->active = true;
        $wf->sort_order = $sortOrder;
        $wf->save();

        return $wf;
    }

    /**
     * Runs the jobs queued since the last call (fake queue): searches of "File again", then the filings they queue.
     * Returns the filing jobs run.
     */
    protected function runQueued()
    {
        $filings = [];
        do {
            $jobs = [];
            foreach ([RefileMailbox::class, FileIntoImapFolder::class] as $class) {
                foreach (\Queue::pushed($class) as $job) {
                    $jobs[spl_object_id($job)] = $job;
                }
            }
            $new = array_diff_key($jobs, $this->ranJobs);
            $this->ranJobs += $new;
            foreach ($new as $job) {
                $job->handle();
                if ($job instanceof FileIntoImapFolder) {
                    $filings[] = $job;
                }
            }
        } while ($new);

        return $filings;
    }

    /** Moves an e-mail on the test server from a folder whose path contains $from into $to (created when missing). */
    protected function moveByHand($mailbox, $messageId, $from, $to)
    {
        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();
        if (!$client->getFolderByPath($to)) {
            $client->createFolder($to, false);
        }
        foreach ($client->getFolders(false) as $folder) {
            if (strpos($folder->path, $from) === false) {
                continue;
            }
            foreach ($folder->query()->all()->leaveUnread()->get() as $message) {
                if (trim((string) $message->getMessageId(), '<> ') === $messageId) {
                    $message->move($to);
                }
            }
        }
        $client->disconnect();
    }

    protected function lastFiling($conversation)
    {
        return \Modules\WorkflowPacks\Entities\Activity::where('kind', 'imap_file')->where('conversation_id', $conversation->id)->orderBy('id', 'desc')->first();
    }

    public function testFileAgainFilesTheExistingTicketsMatchingTheRulesAndThoseMovedElsewhere()
    {
        \Queue::fake();
        $mailbox = $this->imapMailbox();
        $admin = $this->user([], true);
        // "New" -> "A traiter" first, then "facture" -> "Factures" (the last one wins), and a rule for replies only
        $catchAll = $this->filingRule($mailbox, [[['type' => FolderPath::ACTION, 'value' => 'A traiter']]], [[['type' => 'new_or_reply', 'operator' => 'new']]], 1);
        $invoices = $this->filingRule($mailbox, null, null, 2);
        $replies = $this->filingRule($mailbox, [[['type' => FolderPath::ACTION, 'value' => 'Réponses']]], [[['type' => 'new_or_reply', 'operator' => 'reply']]], 3);

        // never handled by Workflows (arrived before the rules): one invoice, one other e-mail
        $invoice = $this->deliver($mailbox, 'Facture 41');
        $hello = $this->deliver($mailbox, 'Bonjour');
        list($c1) = $this->conversation($mailbox, $invoice);
        list($c2) = $this->conversation($mailbox, $hello);
        $c2->subject = 'Bonjour';
        $c2->save();
        // handled and filed by Workflows, then: one moved by hand (its subject no longer matches), one deleted
        $moved = $this->deliver($mailbox);
        $trashed = $this->deliver($mailbox, 'Facture 43');
        list($c3) = $this->conversation($mailbox, $moved);
        list($c4) = $this->conversation($mailbox, $trashed);
        foreach ([$c3, $c4] as $c) {
            ImapFolders::queue($c, 'Factures', $invoices);
            $invoices->markProcessed($c->id);
        }
        $this->runQueued();
        $this->moveByHand($mailbox, $moved, 'Factures', 'Archive');
        $this->moveByHand($mailbox, $trashed, 'Factures', 'Trash');
        $c3->subject = 'Re: commande';
        $c3->save();

        $response = $this->actingAs($admin)->post(route('workflowpacks.rules.toggle'), ['workflow_ids' => [$catchAll->id, $invoices->id, $replies->id], 'refile' => '1']);
        $response->assertSessionHas('flash_success_floating', __('workflowpacks::messages.refile_started', ['rules' => 3, 'mailboxes' => 1]));
        $filings = $this->runQueued();
        $this->assertCount(4, $filings, 'one filing per ticket, the last rule only');
        $this->assertTrue($filings[0]->anywhere, 'looked for outside the inbox');

        $this->assertEqualsCanonicalizing([$invoice, $moved], $this->idsIn($mailbox, '[WP] Factures'), 'invoice never handled + e-mail moved by hand');
        $this->assertSame([$hello], $this->idsIn($mailbox, '[WP] A traiter'), '"New" is true for an existing ticket');
        $this->assertNull($this->idsIn($mailbox, '[WP] Réponses'), '"Reply" stays an event');
        $this->assertSame([], $this->idsIn($mailbox, 'Archive'));
        $this->assertSame([$trashed], $this->idsIn($mailbox, 'Trash'), 'the trash is never taken from');
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $c1->fresh()->status, 'the other actions of the rule are not done');
        $this->assertStringContainsString(__('workflowpacks::messages.history_reason_found_in', ['folder' => 'Archive']), (string) $this->lastFiling($c3)->message);
        $this->assertSame('not_found', $this->lastFiling($c4)->status);
        $search = \Modules\WorkflowPacks\Entities\Activity::where('kind', 'rules')->where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
        $this->assertSame('ok', $search->status);
        $this->assertSame(__('workflowpacks::messages.refile_result', ['seen' => 4, 'queued' => 4, 'skipped' => 0]), $search->message);

        // again: already in place, nothing moves
        $this->actingAs($admin)->post(route('workflowpacks.rules.toggle'), ['workflow_ids' => [$catchAll->id, $invoices->id], 'refile' => '1']);
        $this->runQueued();
        $this->assertSame('already', $this->lastFiling($c1)->status);
        $this->assertSame('already', $this->lastFiling($c2)->status);
    }

    public function testFileAgainSaysWhatIsSkippedAndIsRefusedWhereNotAllowed()
    {
        \Queue::fake();
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $noFolder = $this->filingRule($a, [[['type' => 'status', 'value' => '3']]]);
        $noImap = $this->filingRule($a);
        $other = $this->filingRule($b);

        $result = (new \Modules\WorkflowPacks\Services\Imap\Refiler())->rules($agent, [$noFolder->id, $noImap->id, $other->id]);
        $this->assertSame([0, 0, 1, [$a->name]], [$result['rules'], $result['mailboxes'], $result['no_folder'], $result['no_imap']]);
        $this->assertCount(1, $result['refused'], 'mailbox of others');
        list($ok, $warning) = \Modules\WorkflowPacks\Services\Imap\Refiler::summary($result);
        $this->assertNull($ok);
        $this->assertStringContainsString(__('workflowpacks::messages.refile_no_imap', ['list' => $a->name]), $warning);
        \Queue::assertNotPushed(RefileMailbox::class);

        // only rules without folder: said once, not as a list of errors
        $only = \Modules\WorkflowPacks\Services\Imap\Refiler::summary((new \Modules\WorkflowPacks\Services\Imap\Refiler())->rules($agent, [$noFolder->id]));
        $this->assertSame([null, __('workflowpacks::messages.refile_nothing')], $only);

        // the diagram offers it only for the rules filing into a folder
        $edges = collect((new \Modules\WorkflowPacks\Services\Graph\GraphBuilder())->build($this->user([], true))['edges'])->where('kind', 'applies')->keyBy('workflow_id');
        $this->assertFalse($edges[$noFolder->id]['files']);
        $this->assertTrue($edges[$other->id]['files']);

        // option off: nothing started, said
        \Option::set(ImapFolders::OPTION_ENABLED, false);
        $this->actingAs($this->user([], true))->post(route('workflowpacks.rules.toggle'), ['workflow_ids' => [$other->id], 'refile' => '1'])
            ->assertSessionHas('flash_error_floating', __('workflowpacks::messages.history_reason_option_off'));
        \Queue::assertNotPushed(RefileMailbox::class);
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
