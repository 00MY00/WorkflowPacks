<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Ai\TicketText;
use Modules\WorkflowPacks\Services\Ai\Vectorizer;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Tests\TestCase;

/** AI assistant without connection: brief (ids + anonymised text), answer checked and played, "Looks like", draft. */
class AiAssistantTest extends TestCase
{
    protected function ticket($mailbox, $subject, $body, $from = 'jean.dupont@client.test')
    {
        $conversation = new \App\Conversation();
        $conversation->type = \App\Conversation::TYPE_EMAIL;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->subject = $subject;
        $conversation->customer_email = $from;
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
        $thread->body = $body;
        $thread->save();

        return $conversation;
    }

    public function testVectorsAndAnonymisation()
    {
        $a = Vectorizer::vector('Votre newsletter de la semaine : promotions et nouveautés');
        $b = Vectorizer::vector('Newsletter hebdomadaire : nouveautés et promotions de la semaine');
        $c = Vectorizer::vector('Facture n° 2024-118 à régler avant le 30');
        $this->assertGreaterThan(Vectorizer::similarity($a, $c) + 0.3, Vectorizer::similarity($a, $b));
        $this->assertEqualsWithDelta(1.0, Vectorizer::similarity($a, $a), 0.0001);

        $text = TicketText::anonymise('Écrivez à jean.dupont@client.test ou au +41 79 123 45 67, voir https://shop.example.test/x?id=9 ; IBAN CH93 0076 2011 6238 5295 7');
        $this->assertStringNotContainsString('jean.dupont', $text);
        $this->assertStringNotContainsString('123 45 67', $text);
        $this->assertStringContainsString('[link:shop.example.test]', $text);
        $this->assertStringContainsString('[iban]', $text);
        $clean = TicketText::clean("Bonjour,<br>merci.<br>-- <br>Jean Dupont, directeur<br>> ancien message");
        $this->assertStringNotContainsString('directeur', $clean, 'no signature');
    }

    public function testTheBriefOnlyForAdministratorsAnonymisedByDefault()
    {
        $box = $this->mailbox('A');
        $admin = $this->user([$box], true);
        \Option::set(Engine::OPTION, true);
        $t = $this->ticket($box, 'Commande de jean.dupont@client.test', '<p>Appelez le 079 123 45 67.</p>');
        $this->actingAs($this->user([$box], false, true))->get(route('workflowpacks.ai'))->assertStatus(403);

        $brief = $this->actingAs($admin)->post(route('workflowpacks.ai.brief'), ['mailbox_ids' => [$box->id], 'days' => 7, 'limit' => 50,
            'task' => 'Fermer les newsletters', 'download' => 1])->assertStatus(200);
        $data = json_decode($brief->getContent(), true);
        $this->assertSame('workflowpacks-ai-brief', $data['format']);
        $this->assertTrue($data['anonymised']);
        $ticket = collect($data['tickets'])->firstWhere('id', $t->id);
        $this->assertSame('Commande de [email]', $ticket['subject']);
        $this->assertStringNotContainsString('123 45 67', $ticket['excerpt']);
        $this->assertArrayNotHasKey('from', $ticket, 'no address');
        $this->assertSame('client.test', $ticket['from_domain']);
        $this->assertContains('condition.similar', array_column($data['blocks'], 'type'));
        $this->assertNotEmpty($data['rules']);

        // anonymisation turned off explicitly: the full text
        $full = json_decode($this->actingAs($admin)->post(route('workflowpacks.ai.brief'), ['mailbox_ids' => [$box->id], 'full_text' => 1, 'download' => 1])->getContent(), true);
        $this->assertSame('jean.dupont@client.test', collect($full['tickets'])->firstWhere('id', $t->id)['from']);
    }

    public function testTheAnswerIsCheckedPlayedOnTheTicketsAndSavedAsADraft()
    {
        $box = $this->mailbox('A');
        $admin = $this->user([$box], true);
        \Option::set(Engine::OPTION, true);
        $news1 = $this->ticket($box, 'Newsletter de la semaine', 'Nos promotions et nouveautés de la semaine.');
        $news2 = $this->ticket($box, 'Votre newsletter hebdomadaire', 'Les nouveautés et promotions de cette semaine.');
        $other = $this->ticket($box, 'Problème de connexion', 'Je n’arrive pas à me connecter depuis ce matin.');
        $this->actingAs($admin)->post(route('workflowpacks.ai.brief'), ['mailbox_ids' => [$box->id]])->assertStatus(200);

        $answer = json_encode(['format' => 'workflowpacks-flow', 'version' => 1, 'name' => 'Newsletters', 'description' => 'Fermer les newsletters.',
            'blocks' => [
                ['id' => 'b1', 'type' => 'trigger.new_mail'],
                ['id' => 'b2', 'type' => 'condition.similar', 'params' => ['examples' => [$news1->id], 'threshold' => 40]],
                ['id' => 'b3', 'type' => 'action.status', 'params' => ['status' => 'closed']],
            ],
            'links' => [['from' => 'b1', 'port' => 'next', 'to' => 'b2'], ['from' => 'b2', 'port' => 'yes', 'to' => 'b3']]]);
        $page = $this->actingAs($admin)->post(route('workflowpacks.ai.check'), ['answer' => "Voici le flux :\n```json\n".$answer."\n```"])->assertStatus(200);
        $this->seeIn($page, '#'.$news2->id);
        $test = $page->original->getData()['test'];
        $closed = collect($test['groups'])->first(function ($g) {
            return strpos($g['changes'], __('workflowpacks::blocks.values.status.closed')) !== false;
        });
        $this->assertContains($news2->id, $closed['tickets'], 'looks like the example newsletter');
        $this->assertNotContains($other->id, $closed['tickets']);
        $this->assertSame((int) \App\Conversation::STATUS_ACTIVE, (int) $news2->fresh()->status, 'dry run: nothing written');

        // a wrong answer: its problems, no test
        $bad = $this->actingAs($admin)->post(route('workflowpacks.ai.check'), ['answer' => '{"blocks": [{"id": "b1", "type": "action.nope"}]}'])->assertStatus(200);
        $this->assertNotEmpty($bad->original->getData()['answer']['problems']);

        $this->actingAs($admin)->post(route('workflowpacks.ai.create'), ['answer' => $answer])->assertRedirect();
        $flow = Flow::where('name', 'Newsletters')->first();
        $this->assertNotNull($flow);
        $this->assertFalse($flow->active, 'a draft, switched off');
        $this->assertNull($flow->version_id, 'not published');
        $this->assertSame(340, $flow->draft['blocks'][1]['x'], 'placed left to right');
    }
}
