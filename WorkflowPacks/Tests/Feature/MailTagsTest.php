<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Blocks\Conditions\MailTag;
use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Entities\FlowRun;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\Listener;
use Modules\WorkflowPacks\Services\Engine\Publisher;
use Modules\WorkflowPacks\Services\Engine\Scheduler;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Block "E-mail tag" of the flows (origin tags of RefreshGlobal). RefreshGlobal is not installed in the tests: it is
 * marked active and its read filter "refreshglobal.mail_tags" is played by the test (RefreshGlobal docs/api-tags.md).
 */
class MailTagsTest extends TestCase
{
    /** [conversation id => tags] answered by the fake RefreshGlobal */
    protected static $tags = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$tags = [];
        \Option::set(Engine::OPTION, true);
        Flow::query()->update(['active' => false]);
        Listener::forget();
        // a new application (and Eventy) for every test
        \Eventy::addFilter('refreshglobal.mail_tags', function ($default, $ids = []) {
            $out = (array) $default;
            foreach ((array) $ids as $id) {
                if (isset(self::$tags[(int) $id])) {
                    $out[(int) $id] = self::$tags[(int) $id];
                }
            }

            return $out;
        }, 20, 2);
        \App\Module::setActive('refreshglobal', true);
        \App\Module::clearModulesCache();
    }

    protected function tearDown(): void
    {
        \App\Module::setActive('refreshglobal', false);
        \App\Module::clearModulesCache();
        parent::tearDown();
    }

    protected function ticket($mailbox, $minutesAgo = 0)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->number = random_int(100000, 999999);
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = 'Promo';
        $c->customer_email = 'client@example.test';
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = \App\Conversation::STATUS_ACTIVE;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();
        if ($minutesAgo) {
            \DB::table('conversations')->where('id', $c->id)->update(['created_at' => \Carbon\Carbon::now()->subMinutes($minutesAgo)]);
        }

        return $c->fresh();
    }

    /** New e-mail -> "E-mail tag" -> yes: closed / no: pending. */
    protected function flow($admin, array $params = ['has' => 'has', 'tag' => 'classified_spam'])
    {
        $flow = Flow::create(['name' => 'Spam', 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id, 'draft' => [
            'blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail', 'params' => []], ['id' => 'b2', 'type' => 'condition.mail_tag', 'params' => $params],
                ['id' => 'b3', 'type' => 'action.status', 'params' => ['status' => 'closed']], ['id' => 'b4', 'type' => 'action.status', 'params' => ['status' => 'pending']]],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2'], ['from' => 'b2', 'port' => 'yes', 'to' => 'b3'], ['from' => 'b2', 'port' => 'no', 'to' => 'b4']]]]);
        $this->assertTrue((new Publisher())->publish($flow, $admin)['ok']);

        return $flow->fresh(['version']);
    }

    public function testTheBlockDecidesWithTheTagsOfRefreshGlobal()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $flow = $this->flow($admin);
        $spam = $this->ticket($a);
        $inbox = $this->ticket($a);
        self::$tags = [$spam->id => ['Classified_Spam', 'folder:Promos'], $inbox->id => ['inbox']];

        (new Engine())->start($flow, $spam);
        (new Engine())->start($flow, $inbox);
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $spam->fresh()->status, 'has the tag (case ignored): yes');
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $inbox->fresh()->status, 'other tag: no');

        // "has not"
        $flow2 = $this->flow($admin, ['has' => 'has_not', 'tag' => 'classified_spam']);
        $other = $this->ticket($a);
        self::$tags[$other->id] = ['inbox'];
        (new Engine())->start($flow2, $other);
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $other->fresh()->status);

        // an older ticket without tags: no wait, decided at once
        $old = $this->ticket($a, 120);
        $run = (new Engine())->start($flow, $old);
        $this->assertSame(FlowRun::DONE, $run->fresh()->state);
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $old->fresh()->status);
    }

    public function testANewEmailWithoutTagsWaitsForThemThenGoesOn()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $flow = $this->flow($admin);
        $ticket = $this->ticket($a);

        // no tags yet: the block waits a minute (the flow does not decide "no" too early)
        $run = (new Engine())->start($flow, $ticket)->fresh();
        $this->assertSame([FlowRun::WAITING, 'b2', null], [$run->state, $run->block, $run->resume]);
        $this->assertTrue($run->wake_at->lte(\Carbon\Carbon::now()->addMinutes(2)));
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $ticket->fresh()->status);

        // RefreshGlobal tags it: next minute, the block runs again and decides
        self::$tags[$ticket->id] = ['classified_spam'];
        FlowRun::where('id', $run->id)->update(['wake_at' => \Carbon\Carbon::now()->subSecond()]);
        (new Scheduler())->tick();
        $this->assertSame(FlowRun::DONE, $run->fresh()->state);
        $this->assertSame(\App\Conversation::STATUS_CLOSED, (int) $ticket->fresh()->status);

        // never tagged: decided with what is there after WAIT_TIMES minutes
        $never = $this->ticket($a);
        $run = (new Engine())->start($flow, $never)->fresh();
        for ($i = 0; $i < MailTag::WAIT_TIMES + 2 && $run->fresh()->state === FlowRun::WAITING; $i++) {
            FlowRun::where('id', $run->id)->update(['wake_at' => \Carbon\Carbon::now()->subSecond()]);
            (new Scheduler())->tick();
        }
        $this->assertSame(FlowRun::DONE, $run->fresh()->state);
        $this->assertSame(MailTag::WAIT_TIMES, $i, 'one minute at a time, WAIT_TIMES times');
        $this->assertSame(\App\Conversation::STATUS_PENDING, (int) $never->fresh()->status);
        $this->assertArrayNotHasKey(MailTag::VAR_WAITS, (array) $run->fresh()->vars, 'its counter is not left in the variables');
    }

    public function testWithoutRefreshGlobalTheErrorOutputAndTheEditor()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);

        // checks of the block
        $this->assertSame([], MailTag::validate(['has' => 'has_not', 'tag' => 'spam']));
        $this->assertArrayHasKey('tag', MailTag::validate(['has' => 'has', 'tag' => '']));
        $this->assertArrayHasKey('has', MailTag::validate(['has' => 'maybe', 'tag' => 'spam']));
        $this->assertArrayHasKey('tag', MailTag::validate(['has' => 'has', 'tag' => str_repeat('x', 61)]));
        $this->assertStringContainsString('spam', MailTag::title(['has' => 'has', 'tag' => 'spam']));

        // the editor offers the block and the tags of RefreshGlobal
        $page = $this->actingAs($admin)->get(route('workflowpacks.flows'))->assertStatus(200)->getContent();
        $this->assertStringContainsString('condition.mail_tag', $page);
        $this->assertStringContainsString('classified_spam', $page);

        // RefreshGlobal off: the "error" output, nothing else done
        $flow = $this->flow($admin);
        \App\Module::setActive('refreshglobal', false);
        \App\Module::clearModulesCache();
        $ticket = $this->ticket($a);
        $run = (new Engine())->start($flow, $ticket);
        $this->assertSame(FlowRun::FAILED, $run->fresh()->state, 'no "error" link: the run fails, written in History');
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $ticket->fresh()->status);

        // nothing is added to Workflows any more
        $this->assertSame([], \Eventy::filter('workflows.conditions_config', [], $a->id));
        $this->assertFalse(\Eventy::filter('workflow.check_condition', false, 'wp_mail_tag', 'has', 'spam', $ticket, null));
    }
}
