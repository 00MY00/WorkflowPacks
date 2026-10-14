<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Services\MailTags;
use Modules\WorkflowPacks\Services\Workflows\Bridge;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Condition "E-mail tag" (origin tags of RefreshGlobal). RefreshGlobal is not installed in the tests: it is marked
 * active and its read filter "refreshglobal.mail_tags" is played by the test (RefreshGlobal docs/api-tags.md).
 */
class MailTagsTest extends TestCase
{
    /** [conversation id => tags] answered by the fake RefreshGlobal */
    protected static $tags = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$tags = [];
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
        Bridge::forgetCatalogs();
    }

    protected function tearDown(): void
    {
        \App\Module::setActive('refreshglobal', false);
        \App\Module::clearModulesCache();
        Bridge::forgetCatalogs();
        parent::tearDown();
    }

    protected function ticket($mailbox, $subject = 'Promo')
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = $subject;
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = \App\Conversation::STATUS_ACTIVE;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();
        $t = new \App\Thread();
        $t->conversation_id = $c->id;
        $t->type = \App\Thread::TYPE_CUSTOMER;
        $t->source_via = \App\Thread::PERSON_CUSTOMER;
        $t->source_type = \App\Thread::SOURCE_TYPE_EMAIL;
        $t->status = \App\Thread::STATUS_ACTIVE;
        $t->state = \App\Thread::STATE_PUBLISHED;
        $t->body = 'Bonjour.';
        $t->message_id = 'tag-'.self::uid().'@example.test';
        $t->save();

        return [$c, $t];
    }

    protected function spamRule($mailbox, $operator = 'has', $previous = false)
    {
        $wf = Bridge::newModel();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = 'Spam '.self::uid();
        $wf->type = 1;
        $wf->apply_to_prev = $previous;
        $wf->max_executions = 1;
        $wf->conditions = [[['type' => MailTags::TYPE, 'operator' => $operator, 'value' => 'classified_spam']]];
        $wf->actions = [[['type' => 'status', 'value' => '4']]];
        $wf->active = true;
        $wf->save();

        return $wf;
    }

    public function testTheConditionIsOfferedWithTheKnownTagsOnlyWithRefreshGlobal()
    {
        $a = $this->mailbox('A');
        $item = Bridge::catalog($a->id)['conditions'][MailTags::TYPE] ?? null;
        $this->assertNotNull($item, 'in the catalog of Workflows');
        $this->assertSame(['has', 'has_not'], $item['operators']);
        $this->assertNull($item['values'], 'free text: any tag can be typed');
        foreach (MailTags::STANDARD as $tag) {
            $this->assertArrayHasKey($tag, $item['suggest']);
        }
        $this->assertSame(__('workflowpacks::messages.mail_tag_classified_spam'), $item['suggest']['classified_spam']);

        \App\Module::setActive('refreshglobal', false);
        \App\Module::clearModulesCache();
        Bridge::forgetCatalogs();
        $this->assertArrayNotHasKey(MailTags::TYPE, Bridge::catalog($a->id)['conditions'], 'not offered without RefreshGlobal');
    }

    public function testWorkflowsEvaluatesTheTagsOfTheTicket()
    {
        $a = $this->mailbox('A');
        list($spam) = $this->ticket($a);
        list($clean) = $this->ticket($a, 'Facture');
        self::$tags = [$spam->id => ['classified_spam', 'inbox'], $clean->id => ['inbox']];

        $check = function ($operator, $value, $c) {
            return \Eventy::filter('workflow.check_condition', false, MailTags::TYPE, $operator, $value, $c, null);
        };
        $this->assertTrue($check('has', 'classified_spam', $spam));
        $this->assertTrue($check('has', ' Classified_Spam ', $spam), 'case and spaces ignored');
        $this->assertFalse($check('has', 'classified_spam', $clean));
        $this->assertTrue($check('has_not', 'classified_spam', $clean));
        $this->assertFalse($check('has', '', $spam), 'no tag written: never true');
        $this->assertFalse(\Eventy::filter('workflow.check_condition', false, 'subject', 'contains', 'x', $spam, null), 'other conditions untouched');
    }

    public function testTheRuleRunsWhenRefreshGlobalTagsTheEmailOnceNeverOnOlderTickets()
    {
        $a = $this->mailbox('A');
        // ticket created before the rule (a recalculation of the tags must not touch it)
        list($old) = $this->ticket($a);
        $old->created_at = date('Y-m-d H:i:s', time() - 7200);
        $old->save();
        $rule = $this->spamRule($a);
        // the rule exists since an hour (Workflows: a ticket is "new" for a rule when created strictly after it)
        $rule->created_at = date('Y-m-d H:i:s', time() - 3600);
        $rule->save();
        list($new, $thread) = $this->ticket($a);
        self::$tags = [$old->id => ['classified_spam'], $new->id => ['classified_spam']];

        // FreeScout fetched the e-mail, RefreshGlobal tagged it: the rule runs (here: status spam)
        \Eventy::action('refreshglobal.mail_tagged', $new->id, $thread->id, ['classified_spam']);
        $this->assertSame(\App\Conversation::STATUS_SPAM, (int) $new->fresh()->status);
        $this->assertSame(1, \DB::table('conversation_workflow')->where('conversation_id', $new->id)->where('workflow_id', $rule->id)->count());

        // tagged again (another e-mail of the ticket, recalculation): not run twice
        // (the rule saved another instance: this one still says "active", so it is reloaded before the change)
        $new = $new->fresh();
        $new->status = \App\Conversation::STATUS_ACTIVE;
        $new->save();
        \Eventy::action('refreshglobal.mail_tagged', $new->id, $thread->id, ['inbox']);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $new->fresh()->status);

        // older ticket: only with "existing tickets" on the rule
        \Eventy::action('refreshglobal.mail_tagged', $old->id, null, ['classified_spam']);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $old->fresh()->status);
        $rule->apply_to_prev = true;
        $rule->save();
        \Eventy::action('refreshglobal.mail_tagged', $old->id, null, ['classified_spam']);
        $this->assertSame(\App\Conversation::STATUS_SPAM, (int) $old->fresh()->status);

        // inactive rule, or a rule without this condition: never run from here
        list($third, $t3) = $this->ticket($a);
        self::$tags[$third->id] = ['classified_spam'];
        $rule->active = false;
        $rule->save();
        \Eventy::action('refreshglobal.mail_tagged', $third->id, $t3->id, ['classified_spam']);
        $this->assertSame(\App\Conversation::STATUS_ACTIVE, (int) $third->fresh()->status);
    }

    public function testAPackRuleUsingTheConditionIsImported()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $rule = $this->rule('spam', ['conditions' => ['all' => [['type' => MailTags::TYPE, 'operator' => 'has', 'value' => 'classified_spam']]],
            'actions' => [['type' => 'status', 'value' => '4']]]);
        list(, $result) = $this->import($this->pack([$rule]), [$a], $admin);
        $this->assertTrue($result['ok'], json_encode($result));
        $wf = $this->workflows($a)->first();
        $this->assertSame(MailTags::TYPE, $wf->conditions[0][0]['type']);
        $this->assertSame('classified_spam', $wf->conditions[0][0]['value']);
    }
}
