<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Activity;
use Modules\WorkflowPacks\Services\Flow\TicketConditions;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/** Flow view (blocks by instruction), conditions "assigned" / "replied", and the simulation of a fake e-mail. */
class FlowViewTest extends TestCase
{
    protected function workflow($mailbox, $name, array $conditions, array $actions, $active = true)
    {
        $wf = new Workflow();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = $name;
        $wf->active = $active;
        $wf->conditions = $conditions;
        $wf->actions = [$actions];
        $wf->save();

        return $wf;
    }

    protected function delay($number, $metric)
    {
        return [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => $number, 'metric' => $metric]]];
    }

    /** "invoice": note on arrival, closed after 2 days; when nobody replied, pending after 1 day. */
    protected function invoiceFlow($mailbox)
    {
        $subject = [['type' => 'subject', 'operator' => 'contains', 'value' => 'facture']];

        return [
            $this->workflow($mailbox, 'Note', [$subject, [['type' => 'new_or_reply', 'operator' => 'new']]], [['type' => 'note', 'value' => json_encode(['body' => 'Reçue'])]]),
            $this->workflow($mailbox, 'Fermeture', [$subject, $this->delay(2, 'd')], [['type' => 'status', 'value' => '3']]),
            $this->workflow($mailbox, 'Relance', [$subject, [['type' => TicketConditions::REPLIED, 'operator' => 'no']], $this->delay(1, 'd')], [['type' => 'status', 'value' => '2']], false),
        ];
    }

    protected function ticket($mailbox, $userId = null)
    {
        $c = new \App\Conversation();
        $c->type = \App\Conversation::TYPE_EMAIL;
        $c->folder_id = (int) optional($mailbox->folders()->first())->id;
        $c->mailbox_id = $mailbox->id;
        $c->subject = 'Facture';
        $c->user_id = $userId;
        $c->source_via = \App\Conversation::PERSON_CUSTOMER;
        $c->source_type = \App\Conversation::SOURCE_TYPE_EMAIL;
        $c->status = \App\Conversation::STATUS_ACTIVE;
        $c->state = \App\Conversation::STATE_PUBLISHED;
        $c->save();

        return $c;
    }

    protected function thread($conversation, $type, $state = null)
    {
        $t = new \App\Thread();
        $t->conversation_id = $conversation->id;
        $t->type = $type;
        $t->source_via = \App\Thread::PERSON_USER;
        $t->source_type = \App\Thread::SOURCE_TYPE_EMAIL;
        $t->status = \App\Thread::STATUS_ACTIVE;
        $t->state = $state ?: \App\Thread::STATE_PUBLISHED;
        $t->body = 'Réponse';
        $t->save();
    }

    public function testTheConditionsAreOfferedAndEvaluatedForWorkflows()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $catalog = $this->actingAs($admin)->getJson(route('workflowpacks.graph.catalog', ['mailbox_id' => $a->id]))->assertStatus(200)->json();
        $this->assertArrayHasKey(TicketConditions::ASSIGNED, $catalog['catalog']['conditions']);
        $this->assertArrayHasKey(TicketConditions::REPLIED, $catalog['catalog']['conditions']);

        $check = function ($type, $operator, $c) {
            return \Eventy::filter('workflow.check_condition', false, $type, $operator, '', $c, null);
        };
        $free = $this->ticket($a);
        $taken = $this->ticket($a, $admin->id);
        $this->assertTrue($check(TicketConditions::ASSIGNED, 'no', $free));
        $this->assertTrue($check(TicketConditions::ASSIGNED, 'yes', $taken));
        $this->assertFalse($check(TicketConditions::REPLIED, 'yes', $free));
        $this->thread($free, \App\Thread::TYPE_NOTE);
        $this->thread($free, \App\Thread::TYPE_MESSAGE, \App\Thread::STATE_DRAFT);
        $this->assertFalse($check(TicketConditions::REPLIED, 'yes', $free), 'a note or a draft is not a reply');
        $this->thread($free, \App\Thread::TYPE_MESSAGE);
        $this->assertTrue($check(TicketConditions::REPLIED, 'yes', $free));
        $this->assertFalse($check(TicketConditions::REPLIED, 'maybe', $free), 'unknown operator: false');
        $this->assertFalse($check('subject', 'contains', $free), 'other conditions untouched');

        // the stub engine of Workflows asks the filter: a rule with the condition matches
        $rule = $this->workflow($a, 'Personne', [[['type' => TicketConditions::ASSIGNED, 'operator' => 'no']]], [['type' => 'status', 'value' => '2']]);
        $this->assertTrue($rule->checkConditions($this->ticket($a), 'conversation.created_by_customer'));
        $this->assertFalse($rule->checkConditions($taken, 'conversation.created_by_customer'));
    }

    public function testTheDiagramDataHasTheFlowsOfEachMailbox()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        list($note, $close, $later) = $this->invoiceFlow($a);
        $data = $this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->assertStatus(200)->json();
        $flows = $data['flows'][$a->id]['flows'];
        $this->assertCount(1, $flows, 'same conditions: one flow');
        $this->assertCount(2, $flows[0]['lanes'], 'one lane without test, one "not replied"');
        $this->assertSame([$note->id, $close->id], array_column($flows[0]['lanes'][0]['steps'], 'workflow_id'), 'on arrival, then after 2 days');
        $this->assertSame('status-closed', $flows[0]['lanes'][0]['steps'][1]['actions'][0]['kind']);
        $this->assertSame([$later->id], array_column($flows[0]['lanes'][1]['steps'], 'workflow_id'));
        $this->assertFalse($flows[0]['lanes'][1]['steps'][0]['active']);
        $this->assertSame(['mb:'.$a->id, $flows[0]['key'], 'ft:'.$close->id, 'fa:'.$close->id.':0'], $data['flows'][$a->id]['paths'][$close->id]);
    }

    public function testTheSimulationRunsInMemoryAndWritesNothing()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        list($note, $close, $later) = $this->invoiceFlow($a);
        $counts = function () {
            return [\App\Conversation::count(), \App\Thread::count(), \App\Customer::count(), \DB::table('jobs')->count(), Workflow::count()];
        };
        $before = $counts();

        $result = $this->actingAs($admin)->postJson(route('workflowpacks.graph.simulate'), [
            'mailbox_id' => $a->id, 'subject' => 'Votre facture', 'customer_email' => 'client@example.test', 'body' => 'Bonjour',
        ])->assertStatus(200)->json();
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['processed']);
        $this->assertSame([$note->id, $later->id, $close->id], array_column($result['events'], 'workflow_id'), 'arrival, +1 day (not replied, disabled but simulated), +2 days');
        $this->assertSame(e(__('workflowpacks::messages.dest_closed')), e($result['final']['status']));
        $this->assertSame(__('workflowpacks::messages.sim_field_status'), $result['events'][2]['actions'][0]['changes'][0]['field']);
        $this->assertArrayNotHasKey('action', $result['events'][2]['actions'][0], 'the rule itself is not sent back');
        $this->assertSame($before, $counts(), 'no ticket, thread, customer, job or rule written');
        $activity = Activity::where('kind', 'simulate')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertStringNotContainsString('client@example.test', (string) $activity->message.$activity->subject, 'the fake e-mail is not kept');

        // without the disabled rule
        $active = $this->actingAs($admin)->postJson(route('workflowpacks.graph.simulate'), ['mailbox_id' => $a->id, 'subject' => 'facture', 'include_inactive' => 0])->json();
        $this->assertSame([$note->id, $close->id], array_column($active['events'], 'workflow_id'));
        // the team already replied: no reminder
        $replied = $this->actingAs($admin)->postJson(route('workflowpacks.graph.simulate'), ['mailbox_id' => $a->id, 'subject' => 'facture', 'replied' => 1])->json();
        $this->assertNotContains($later->id, array_column($replied['events'], 'workflow_id'));
        // nothing matches
        $none = $this->actingAs($admin)->postJson(route('workflowpacks.graph.simulate'), ['mailbox_id' => $a->id, 'subject' => 'Bonjour'])->json();
        $this->assertFalse($none['processed']);
    }

    public function testTheSimulationNeedsTheRightToManageTheMailbox()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $this->actingAs($agent)->postJson(route('workflowpacks.graph.simulate'), ['mailbox_id' => $b->id, 'subject' => 'x'])->assertStatus(403);
        $this->actingAs($agent)->postJson(route('workflowpacks.graph.simulate'), ['mailbox_id' => $a->id, 'subject' => 'x'])->assertStatus(200);
    }

    public function testARuleIsTestedWithAFakeEmailMadeForIt()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        list(, $close, $later) = $this->invoiceFlow($a);

        $sample = $this->actingAs($admin)->getJson(route('workflowpacks.graph.sample', ['id' => $later->id]))->assertStatus(200)->json();
        $this->assertStringContainsString('facture', mb_strtolower($sample['input']['subject']));
        $this->assertFalse($sample['input']['replied'], 'branch "not replied"');
        $this->assertSame([], $sample['unmet']);

        $result = $this->actingAs($admin)->postJson(route('workflowpacks.graph.simulate'), $sample['input'] + ['mailbox_id' => $a->id, 'target' => $later->id])->json();
        $this->assertTrue($result['target']['applied']);
        $this->assertSame($later->id, $result['target']['workflow_id']);
        $this->assertNotSame('', $result['target']['when']);

        // the same e-mail, the team already replied: the tested rule does not apply, and the panel says why
        $replied = $this->actingAs($admin)->postJson(route('workflowpacks.graph.simulate'), ['replied' => 1] + $sample['input'] + ['mailbox_id' => $a->id, 'target' => $later->id])->json();
        $this->assertFalse($replied['target']['applied']);
        $this->assertSame(__('workflowpacks::messages.sim_no_match'), $replied['target']['reason_text']);

        $agent = $this->user([$b], false, true);
        $this->actingAs($agent)->getJson(route('workflowpacks.graph.sample', ['id' => $close->id]))->assertStatus(403);
    }

    public function testTheFlowViewIsKeptPerUser()
    {
        $admin = $this->user([], true);
        $this->actingAs($admin)->postJson(route('workflowpacks.graph.layout'), ['positions' => [], 'settings' => ['view' => 'flow']])->assertStatus(200);
        $this->assertSame('flow', $this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json()['layout']['settings']['view']);
        $this->actingAs($admin)->postJson(route('workflowpacks.graph.layout'), ['positions' => [], 'settings' => ['view' => 'other']])->assertStatus(422);
        $page = $this->actingAs($admin)->get(route('workflowpacks.graph'));
        $this->seeIn($page, 'data-act="view-flow"');
        $this->seeIn($page, 'data-act="simulate"');
        $this->seeIn($page, '/js/graph/sim.js');
    }
}
