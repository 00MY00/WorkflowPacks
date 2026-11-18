<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Services\Engine\SampleMail;
use Modules\WorkflowPacks\Tests\TestCase;

/** "An e-mail that goes through": a sample e-mail made to take the positive path of a draft, to its end. */
class SampleMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Option::set(Engine::OPTION, true);
    }

    protected function ticket($mailbox, $subject, $body)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->mailbox_id = $mailbox->id;
        $c->subject = $subject;
        $c->customer_email = 'news@example.test';
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
        $t->body = $body;
        $t->save();

        return $c;
    }

    /** trigger -> If… (each condition, "yes") -> … -> internal note */
    protected function chain(array $conditions)
    {
        $blocks = [['id' => 't', 'type' => 'trigger.new_mail']];
        $links = [];
        $prev = 't';
        foreach ($conditions as $i => $params) {
            $id = 'c'.$i;
            $blocks[] = ['id' => $id] + (isset($params['type']) ? $params : ['type' => 'condition.ticket', 'params' => $params]);
            $links[] = ['from' => $prev, 'port' => $prev === 't' ? 'next' : 'yes', 'to' => $id];
            $prev = $id;
        }
        $blocks[] = ['id' => 'end', 'type' => 'action.note', 'params' => ['text' => 'Reçu']];
        $links[] = ['from' => $prev, 'port' => $prev === 't' ? 'next' : 'yes', 'to' => 'end'];

        return ['blocks' => $blocks, 'links' => $links];
    }

    public function testTheConditionsOfThePositivePathAreMadeTrue()
    {
        $draft = $this->chain([
            ['var' => 'ticket.subject', 'operator' => 'contains', 'value' => 'Facture'],
            ['var' => 'ticket.subject', 'operator' => 'not_contains', 'value' => 'urgent'],
            ['var' => 'ticket.from', 'operator' => 'ends', 'value' => '@fournisseur.test'],
            ['var' => 'ticket.body', 'operator' => 'starts', 'value' => 'Bonjour'],
            ['var' => 'ticket.status', 'operator' => 'is', 'value' => 'pending'],
            ['var' => 'ticket.assigned', 'operator' => 'yes'],
            ['var' => 'ticket.replied', 'operator' => 'no'],
        ]);
        $out = (new SampleMail())->build($draft);
        $this->assertTrue($out['reached'], json_encode($out));
        $this->assertSame('end', end($out['path']));
        $this->assertStringContainsStringIgnoringCase('facture', $out['mail']['subject']);
        $this->assertStringNotContainsStringIgnoringCase('urgent', $out['mail']['subject']);
        $this->assertStringEndsWith('@fournisseur.test', $out['mail']['from']);
        $this->assertStringStartsWith('Bonjour', $out['mail']['body']);
        $this->assertSame('pending', $out['mail']['status']);
        $this->assertTrue($out['mail']['assigned']);
        $this->assertFalse($out['mail']['replied']);
        $this->assertSame([], $out['notes']);

        // equal, and "is not"
        $out = (new SampleMail())->build($this->chain([
            ['var' => 'ticket.subject', 'operator' => 'equal', 'value' => 'Commande 42'],
            ['var' => 'ticket.status', 'operator' => 'is_not', 'value' => 'active'],
        ]));
        $this->assertTrue($out['reached']);
        $this->assertSame('Commande 42', $out['mail']['subject']);
        $this->assertNotSame('active', $out['mail']['status']);
    }

    public function testLooksLikeGetsATextNearTheExamplesAboveTheThreshold()
    {
        $a = $this->mailbox('A');
        $news = [
            $this->ticket($a, 'Newsletter de la semaine', 'Nos promotions et nouveautés de la semaine, découvrez nos offres exclusives réservées aux abonnés.'),
            $this->ticket($a, 'Votre newsletter hebdomadaire', 'Les nouveautés et promotions de cette semaine, offres exclusives pour nos abonnés fidèles.'),
        ];
        $draft = $this->chain([['type' => 'condition.similar', 'params' => ['examples' => [$news[0]->id, $news[1]->id], 'threshold' => 40, 'subject' => 50, 'body' => 50]]]);
        $out = (new SampleMail())->build($draft);
        $this->assertTrue($out['reached'], json_encode($out));
        $this->assertSame([], $out['notes']);
        $this->assertNotSame('', $out['mail']['body']);
        // no example chosen: said, not guessed
        $out = (new SampleMail())->build($this->chain([['type' => 'condition.similar', 'params' => ['examples' => [], 'threshold' => 40]]]));
        $this->assertNotEmpty($out['notes']);
        // a value given by another block: said
        $out = (new SampleMail())->build($this->chain([['var' => 'similar.score', 'operator' => 'gte', 'value' => '50']]));
        $this->assertStringContainsString('similar.score', implode(' ', $out['notes']));
        // a few words dropped or swapped, never empty
        $this->assertNotSame('', SampleMail::vary('un deux trois quatre cinq six sept huit', 0.3));
        $this->assertSame('un deux', SampleMail::vary('un deux', 0.5), 'too short: kept');
    }

    public function testFromTheEditorForAdministratorsOnly()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        $flow = Flow::create(['name' => 'Test', 'mailbox_ids' => [], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 't', 'type' => 'trigger.new_mail']], 'links' => []]]);
        $draft = $this->chain([['var' => 'ticket.subject', 'operator' => 'contains', 'value' => 'promo']]);
        $res = json_decode($this->actingAs($admin)->postJson(route('workflowpacks.flows.sample', ['id' => $flow->id]), ['draft' => $draft])->getContent(), true);
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['reached']);
        $this->assertStringContainsStringIgnoringCase('promo', $res['mail']['subject']);
        // the simulation takes the generated fields, "assigned" included
        $sim = json_decode($this->actingAs($admin)->postJson(route('workflowpacks.flows.simulate', ['id' => $flow->id]), ['draft' => $this->chain([['var' => 'ticket.assigned', 'operator' => 'yes']]),
            'mail' => ['subject' => 'x', 'from' => 'a@example.test', 'body' => 'x', 'assigned' => true]])->getContent(), true);
        $this->assertContains('end', array_column($sim['events'], 'block'), 'assigned: yes, the end is reached');
        $this->actingAs($this->user([$a], false, true))->postJson(route('workflowpacks.flows.sample', ['id' => $flow->id]), ['draft' => $draft])->assertStatus(403);
    }
}
