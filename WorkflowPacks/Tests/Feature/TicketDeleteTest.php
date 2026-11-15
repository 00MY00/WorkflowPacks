<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Blocks\Actions\Delete;
use Modules\WorkflowPacks\Services\Engine\BlockRegistry;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Definition;
use Modules\WorkflowPacks\Tests\TestCase;

/** Block "Delete the ticket": to the Deleted folder (restorable), last block of its branch, nothing done in a dry run. */
class TicketDeleteTest extends TestCase
{
    protected function ticket($mailbox)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->number = random_int(100000, 999999);
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = 'Test FreeScout';
        $c->customer_email = 'client@example.test';
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = \App\Conversation::STATUS_ACTIVE;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();

        return $c;
    }

    public function testTheTicketGoesToTheDeletedFolderOnce()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $c = $this->ticket($a);
        $this->assertTrue((new BlockRegistry())->has('action.delete'));

        // dry run (simulation, test on a ticket): nothing changes
        $dry = Context::forConversation($c, [], true);
        (new Delete())->run($dry, []);
        $this->assertSame(\App\Conversation::STATE_PUBLISHED, (int) $c->fresh()->state);

        $ctx = Context::forConversation($c);
        $ctx->actor = $admin;
        $this->assertSame('next', (new Delete())->run($ctx, [])->output);
        $c = $c->fresh();
        $this->assertSame(\App\Conversation::STATE_DELETED, (int) $c->state);
        $this->assertSame(\App\Folder::TYPE_DELETED, (int) \App\Folder::find($c->folder_id)->type, 'in the Deleted folder, restorable');
        $this->assertNotNull(\App\Conversation::find($c->id), 'not deleted for good');

        $again = Context::forConversation($c);
        $again->actor = $admin;
        $this->assertSame(__('workflowpacks::blocks.already'), (new Delete())->run($again, [])->summary);
    }

    public function testItIsTheLastBlockOfItsBranch()
    {
        $draft = ['blocks' => [
            ['id' => 'b1', 'type' => 'trigger.new_mail'],
            ['id' => 'b2', 'type' => 'action.delete'],
            ['id' => 'b3', 'type' => 'action.status', 'params' => ['status' => 'closed']],
        ], 'links' => [
            ['from' => 'b1', 'port' => 'next', 'to' => 'b2'],
            ['from' => 'b2', 'port' => 'next', 'to' => 'b3'],
        ]];
        $problems = Definition::from($draft)->validate(new BlockRegistry());
        $this->assertNotEmpty($problems, 'no output after the deletion');
        $this->assertSame([], Definition::from(['blocks' => array_slice($draft['blocks'], 0, 2), 'links' => [$draft['links'][0]]])->validate(new BlockRegistry()));
    }
}
