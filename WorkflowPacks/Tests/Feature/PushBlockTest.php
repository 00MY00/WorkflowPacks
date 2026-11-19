<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Blocks\Actions\Push;
use Modules\WorkflowPacks\Services\Engine\BlockRegistry;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Tests\TestCase;

/** Block "Push notification" through the WebPush module (test stub tests/stubs/WebPush when the module is absent). */
class PushBlockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!property_exists('\Modules\WebPush\Services\WebPush', 'sent')) {
            $this->markTestSkipped('the test stub of WebPush is not installed (tests/stubs/WebPush)');
        }
        \Modules\WebPush\Services\WebPush::$sent = [];
        \Modules\WebPush\Services\QuietHours::$silent = [];
        // WebPush 1.3.0: its settings and buttons (test stub)
        if (class_exists('\Modules\WebPush\Services\Settings')) {
            \Modules\WebPush\Services\Settings::$hide = false;
            \Modules\WebPush\Services\Settings::$icon = '';
            \Modules\WebPush\Services\Settings::$badge = '';
        }
        \App\Module::setActive('webpush', true);
        \App\Module::clearModulesCache();
    }

    protected function tearDown(): void
    {
        \App\Module::setActive('webpush', false);
        \App\Module::clearModulesCache();
        parent::tearDown();
    }

    protected function ticket($mailbox, $userId = null)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->number = random_int(100000, 999999);
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = 'Serveur <arrêté>';
        $c->user_id = $userId;
        $c->customer_email = 'client@example.test';
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = \App\Conversation::STATUS_ACTIVE;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();

        return $c;
    }

    public function testTheAssigneeIsNotifiedWithTheVariablesReplacedInPlainText()
    {
        $a = $this->mailbox('A');
        $agent = $this->user([$a]);
        $c = $this->ticket($a, $agent->id);
        $this->assertTrue((new BlockRegistry())->has('action.push'));

        $result = (new Push())->run(Context::forConversation($c), ['to' => 'assignee', 'title' => '', 'text' => 'Urgent : {{ticket.subject}}']);
        $this->assertSame('next', $result->output);
        $this->assertCount(1, \Modules\WebPush\Services\WebPush::$sent);
        list($userId, $payload) = \Modules\WebPush\Services\WebPush::$sent[0];
        $this->assertSame((int) $agent->id, $userId);
        $this->assertSame('Serveur <arrêté>', $payload['title'], 'empty title: the subject, not escaped');
        $this->assertSame('Urgent : Serveur <arrêté>', $payload['body']);
        $this->assertSame($c->url(), $payload['url']);
    }

    public function testMailboxUsersExceptQuietHoursAndNothingSentInADryRun()
    {
        $a = $this->mailbox('A');
        $one = $this->user([$a]);
        $two = $this->user([$a]);
        $other = $this->user([$this->mailbox('B')]);
        \Modules\WebPush\Services\QuietHours::$silent = [(int) $two->id];
        $c = $this->ticket($a);

        (new Push())->run(Context::forConversation($c), ['to' => 'mailbox', 'title' => 'T', 'text' => 'x']);
        $ids = array_column(\Modules\WebPush\Services\WebPush::$sent, 0);
        $this->assertContains((int) $one->id, $ids);
        $this->assertNotContains((int) $two->id, $ids, 'quiet hours of the WebPush module respected');
        $this->assertNotContains((int) $other->id, $ids, 'no access to the mailbox');

        \Modules\WebPush\Services\WebPush::$sent = [];
        $ctx = Context::forConversation($c, [], true);
        (new Push())->run($ctx, ['to' => 'user', 'user' => $one->id, 'title' => 'T', 'text' => 'x']);
        $this->assertSame([], \Modules\WebPush\Services\WebPush::$sent, 'simulation: nothing sent');
    }

    public function testErrorsAndChecks()
    {
        $a = $this->mailbox('A');
        $c = $this->ticket($a);
        $this->assertSame('error', (new Push())->run(Context::forConversation($c), ['to' => 'assignee', 'text' => 'x'])->output, 'not assigned');
        $outsider = $this->user([$this->mailbox('B')]);
        $this->assertSame('error', (new Push())->run(Context::forConversation($c), ['to' => 'user', 'user' => $outsider->id, 'text' => 'x'])->output);

        $this->assertArrayHasKey('user', Push::validate(['to' => 'user', 'user' => '-1', 'text' => 'x']));
        $this->assertArrayHasKey('to', Push::validate(['to' => 'everyone', 'text' => 'x']));
        $this->assertSame([], Push::validate(['to' => 'mailbox', 'text' => 'x']));

        \App\Module::setActive('webpush', false);
        \App\Module::clearModulesCache();
        $result = (new Push())->run(Context::forConversation($this->ticket($a, $this->user([$a])->id)), ['to' => 'assignee', 'text' => 'x']);
        $this->assertSame('error', $result->output, 'module off: the "error" output');
        $this->assertSame([], \Modules\WebPush\Services\WebPush::$sent);
    }

    public function testTheSettingsAndButtonsOfWebPushAreUsed()
    {
        if (!class_exists('\Modules\WebPush\Services\Settings') || !class_exists('\Modules\WebPush\Providers\WebPushServiceProvider')) {
            $this->markTestSkipped('the test stub of WebPush 1.3.0 is not installed');
        }
        $a = $this->mailbox('A');
        $assignee = $this->user([$a]);
        $colleague = $this->user([$a]);
        $c = $this->ticket($a, $assignee->id);
        \Modules\WebPush\Services\Settings::$icon = 'https://help.example.test/icon.png';
        \Modules\WebPush\Services\Settings::$badge = 'https://help.example.test/badge.png';

        // its icon and badge; its buttons for each agent ("Assign to me" only for a ticket that is not theirs)
        (new Push())->run(Context::forConversation($c), ['to' => 'mailbox', 'title' => 'Urgent', 'text' => 'Client : {{ticket.subject}}']);
        $sent = [];
        foreach (\Modules\WebPush\Services\WebPush::$sent as list($id, $payload)) {
            $sent[$id] = $payload;
        }
        $mine = $sent[(int) $assignee->id];
        $this->assertSame(['Urgent', 'Client : Serveur <arrêté>', 'https://help.example.test/icon.png', 'https://help.example.test/badge.png'],
            [$mine['title'], $mine['body'], $mine['icon'], $mine['badge']]);
        $this->assertSame(['reply'], array_column($mine['actions'], 'action'));
        $this->assertSame(['assign', 'reply'], array_column($sent[(int) $colleague->id]['actions'], 'action'));
        $this->assertSame('failed', $mine['failed']);
        $this->assertArrayNotHasKey('other', $mine, 'only its buttons are taken');

        // "Hide the content": only the ticket number, nothing readable on a locked screen
        \Modules\WebPush\Services\WebPush::$sent = [];
        \Modules\WebPush\Services\Settings::$hide = true;
        (new Push())->run(Context::forConversation($c), ['to' => 'assignee', 'title' => 'Urgent', 'text' => 'Client : {{ticket.subject}}']);
        $payload = \Modules\WebPush\Services\WebPush::$sent[0][1];
        $this->assertSame('#'.$c->number, $payload['title']);
        $this->assertStringNotContainsString('Serveur', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Urgent', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertNotSame('', $payload['body']);
        $this->assertStringNotContainsString('::', $payload['body'], 'a real text, not a key');
    }
}
