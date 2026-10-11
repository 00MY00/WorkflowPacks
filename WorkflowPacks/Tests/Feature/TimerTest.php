<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Tests\TestCase;

/** Timer rules: date conditions of Workflows ({ number, metric }) in packs, previews, the editor and the diagram. */
class TimerTest extends TestCase
{
    protected function purge($key = 'purge', array $value = ['number' => 30, 'metric' => 'd'])
    {
        return $this->rule($key, [
            'name'       => 'Purge des fermées',
            'settings'   => ['apply_to_prev' => false, 'max_executions' => 1],
            'conditions' => ['all' => [['type' => 'created', 'operator' => 'not_in_last', 'value' => $value], ['type' => 'status', 'operator' => 'equal', 'value' => '3']]],
            'actions'    => [['type' => 'delete']],
        ]);
    }

    public function testATimerRuleIsImportedAsWorkflowsExpectsIt()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $pack = $this->pack([$this->purge('purge', ['number' => '30', 'metric' => 'd'])]);

        $preview = $this->actingAs($admin)->post(route('workflowpacks.import.preview'), ['pack_json' => json_encode($pack), 'mailboxes' => [$a->id]]);
        $this->seeIn($preview, e(__('workflowpacks::texts.timer_d', ['count' => 30])));

        list(, $result) = $this->import($pack, [$a], $admin);
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => 30, 'metric' => 'd']],
            $this->workflows($a)->first()->conditions[0][0]);

        // a delay the Workflows module would not understand: blocked at the preview, never written
        $bad = $this->pack([$this->purge('purge', ['number' => 30, 'metric' => 'weeks'])], ['key' => 'bad-timer']);
        list($plan) = $this->import($bad, [$a], $admin);
        $this->assertSame('blocked', $plan['rules']['purge']['items'][$a->id]['status']);
    }

    public function testTheEditorOffersDelaysAndTheDiagramShowsThem()
    {
        $a = $this->mailbox('A');
        $agent = $this->user([$a], false, true);
        $catalog = $this->actingAs($agent)->getJson(route('workflowpacks.graph.catalog', ['mailbox_id' => $a->id]))->json();
        $this->assertEqualsCanonicalizing(['created', 'waiting', 'user_reply', 'customer_reply'], $catalog['timers']);

        $rule = ['name' => 'Relance après 2 heures', 'conditions' => ['all' => [['type' => 'waiting', 'operator' => 'longer', 'value' => ['number' => '2', 'metric' => 'h']]]],
            'actions' => [['type' => 'status', 'value' => '2']]];
        $saved = $this->actingAs($agent)->postJson(route('workflowpacks.graph.save'), ['mailbox_id' => $a->id, 'rule' => $rule])->assertStatus(200)->json();
        $wf = \Modules\Workflows\Entities\Workflow::find($saved['workflow_id']);
        $this->assertSame(['number' => 2, 'metric' => 'h'], $wf->conditions[0][0]['value']);

        $rule['name'] = 'Mauvaise unité';
        $rule['conditions']['all'][0]['value'] = ['number' => 2, 'metric' => 'x'];
        $this->actingAs($agent)->postJson(route('workflowpacks.graph.save'), ['mailbox_id' => $a->id, 'rule' => $rule])->assertStatus(422);

        $data = $this->actingAs($agent)->getJson(route('workflowpacks.graph.data'))->json();
        $node = collect($data['nodes'])->firstWhere('key', 'wf:'.$wf->id);
        $this->assertStringStartsWith(__('workflowpacks::messages.timer_prefix', ['delay' => __('workflowpacks::texts.timer_h', ['count' => 2])]), $node['sub']);
    }
}
