<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Tests\TestCase;

/** Enable / disable rules from the list of the packs (one or several) and from a selection of the diagram. */
class BulkToggleTest extends TestCase
{
    protected function installed(array $mailboxes, $admin)
    {
        list(, $result) = $this->import($this->pack([$this->rule('r1'), $this->rule('r2')]), $mailboxes, $admin);
        $this->assertTrue($result['ok'], $result['error']);
    }

    public function testTheListShowsTheStateAndASwitchForEveryRule()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->installed([$a], $admin);
        $wf = $this->workflows($a)->first();
        $page = $this->actingAs($admin)->get(route('workflowpacks.index'))->assertStatus(200);
        $this->seeIn($page, 'id="wp-bulk"');
        $this->seeIn($page, 'name="workflow_ids[]" value="'.$wf->id.'" class="wp-bulk-check"');
        $this->seeIn($page, 'wp-dot is-off');
        $this->seeIn($page, e(__('workflowpacks::messages.enable')));

        // one click on the switch of a rule: that rule only
        $this->actingAs($admin)->post(route('workflowpacks.rules.toggle'), ['workflow_ids' => [$wf->id], 'active' => '1'])
            ->assertRedirect(route('workflowpacks.index'));
        $this->assertTrue((bool) $wf->fresh()->active);
        $this->assertFalse((bool) $this->workflows($a)->last()->active, 'the other one unchanged');
    }

    public function testTickedRulesAreChangedTogetherAndOnlyWhereAllowed()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $this->installed([$a, $b], $admin);
        $agent = $this->user([$a], false, true);
        $ids = $this->workflows($a)->pluck('id')->merge($this->workflows($b)->pluck('id'))->all();

        $response = $this->actingAs($agent)->post(route('workflowpacks.rules.toggle'), ['workflow_ids' => $ids, 'active' => '1']);
        $response->assertSessionHas('flash_success_floating', __('workflowpacks::messages.rules_enabled', ['count' => 2]));
        $response->assertSessionHas('flash_error_floating');
        $this->assertSame([true, true], $this->workflows($a)->pluck('active')->map(function ($v) {
            return (bool) $v;
        })->all());
        $this->assertSame([false, false], $this->workflows($b)->pluck('active')->map(function ($v) {
            return (bool) $v;
        })->all(), 'mailbox of others untouched');

        // nothing ticked: said, nothing changed
        $this->actingAs($admin)->post(route('workflowpacks.rules.toggle'), ['active' => '0'])
            ->assertSessionHas('flash_error_floating', __('workflowpacks::messages.rules_none_selected'));
        $this->assertTrue((bool) $this->workflows($a)->first()->active);

        // and back: disabled together, the filter of the list is kept
        $this->actingAs($admin)->post(route('workflowpacks.rules.toggle'), ['workflow_ids' => $ids, 'active' => '0', 'mailbox_id' => $a->id])
            ->assertRedirect(route('workflowpacks.index', ['mailbox_id' => $a->id]));
        $this->assertSame(0, $this->workflows($a)->where('active', true)->count() + $this->workflows($b)->where('active', true)->count());
    }

    public function testASelectionOfTheDiagramIsEnabledOrDisabledAtOnce()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $agent = $this->user([$a], false, true);
        $this->installed([$a, $b], $admin);
        $ids = $this->workflows($a)->pluck('id')->merge($this->workflows($b)->pluck('id'))->all();

        $json = $this->actingAs($agent)->postJson(route('workflowpacks.graph.toggle_many'), ['ids' => $ids, 'active' => true])->assertStatus(200)->json();
        $this->assertSame(2, $json['changed']);
        $this->assertCount(2, $json['refused'], 'the rules of mailbox B: refused, the others still changed');
        $again = $this->actingAs($agent)->postJson(route('workflowpacks.graph.toggle_many'), ['ids' => $this->workflows($a)->pluck('id')->all(), 'active' => true])->json();
        $this->assertSame(['changed' => 0, 'unchanged' => 2], ['changed' => $again['changed'], 'unchanged' => $again['unchanged']]);
        $this->actingAs($agent)->postJson(route('workflowpacks.graph.toggle_many'), ['ids' => 'x', 'active' => true])->assertStatus(422);
    }
}
