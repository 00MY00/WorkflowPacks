<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Tests\TestCase;
use Modules\Workflows\Entities\Workflow;

/** "Delete" of the diagram: what a block stands for, confirmed, applied after a backup, undone. */
class DeleteBlocksTest extends TestCase
{
    protected function workflow($mailbox, $name, array $conditions, array $actions)
    {
        $wf = new Workflow();
        $wf->mailbox_id = $mailbox->id;
        $wf->name = $name;
        $wf->conditions = $conditions;
        $wf->actions = [$actions];
        $wf->save();

        return $wf;
    }

    protected function deletionPlan($user, array $keys)
    {
        return $this->actingAs($user)->postJson(route('workflowpacks.graph.delete_plan'), ['keys' => $keys])->assertStatus(200)->json();
    }

    protected function remove($user, array $keys)
    {
        $plan = $this->deletionPlan($user, $keys);

        return [$plan, $this->actingAs($user)->postJson(route('workflowpacks.graph.delete'), ['keys' => $keys, 'token' => $plan['token']])->json()];
    }

    public function testARuleIsDeletedAndTheDeletionUndone()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $wf = $this->workflow($a, 'À supprimer', [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]], [['type' => 'status', 'value' => '3']]);

        list($plan, $done) = $this->remove($admin, ['wf:'.$wf->id]);
        $this->assertSame(1, $plan['count']);
        $this->assertStringContainsString('À supprimer', $plan['lines'][0]);
        $this->assertTrue($done['ok']);
        $this->assertNull(Workflow::find($wf->id));

        $this->actingAs($admin)->postJson(route('workflowpacks.graph.delete_undo'), ['backup_id' => $done['backup_id']])->assertStatus(200);
        $back = Workflow::where('mailbox_id', $a->id)->where('name', 'À supprimer')->first();
        $this->assertNotNull($back);
        $this->assertSame('x', $back->conditions[0][0]['value']);
        // the backup is used once
        $this->actingAs($admin)->postJson(route('workflowpacks.graph.delete_undo'), ['backup_id' => $done['backup_id']])->assertStatus(422);
    }

    public function testAnActionADelayAndAConditionOfTheFlowView()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $subject = [['type' => 'subject', 'operator' => 'contains', 'value' => 'commande']];
        $status = [['type' => 'status', 'operator' => 'equal', 'value' => '2']];
        $delay = [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => 2, 'metric' => 'd']]];
        $two = $this->workflow($a, 'Deux actions', [$subject, $status], [['type' => 'note', 'value' => json_encode(['body' => 'n'])], ['type' => 'status', 'value' => '3']]);
        $later = $this->workflow($a, 'Plus tard', [$subject, $status, $delay], [['type' => 'assign', 'value' => '-1']]);

        // one action of a rule with two
        list(, $done) = $this->remove($admin, ['fa:'.$two->id.':1']);
        $this->assertTrue($done['ok']);
        $this->assertSame([[['type' => 'note', 'value' => json_encode(['body' => 'n'])]]], $two->fresh()->actions);

        // the delay: the rule then runs on arrival (said in the confirmation)
        list($plan) = $this->remove($admin, ['ft:'.$later->id]);
        $this->assertNotEmpty($plan['warnings']);
        $this->assertCount(2, $later->fresh()->conditions);

        // the condition block "status" of the flow: removed from both rules
        $flows = $this->actingAs($admin)->getJson(route('workflowpacks.graph.data', ['flows' => 1]))->json()['flows'][$a->id]['flows'];
        $blocks = array_column($flows[0]['blocks'], 'family', 'key');
        $statusKey = array_search('status-pending', $blocks, true);
        $this->assertNotFalse($statusKey);
        list($plan, $done) = $this->remove($admin, [$statusKey]);
        $this->assertSame(2, $plan['count']);
        $this->assertSame([$subject], $two->fresh()->conditions);
        $this->assertSame([$subject], $later->fresh()->conditions);

        // the last action: the rule itself goes
        list($plan) = $this->remove($admin, ['fa:'.$later->id.':0']);
        $this->assertStringContainsString('Plus tard', $plan['lines'][0]);
        $this->assertNull(Workflow::find($later->id));
    }

    public function testADestinationAndARuleOfAPack()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a, $b], $admin);
        $this->assertSame(2, Link::where('pack_key', 'test-pack')->count());

        // "Closed" (status destination): the status action leaves the rules sending there
        list($plan, $done) = $this->remove($admin, ['dest:status:pending']);
        $this->assertSame(2, $plan['count']);
        foreach ($this->workflows($a)->merge($this->workflows($b)) as $wf) {
            $this->assertSame(['note'], array_column($wf->actions[0], 'type'));
        }

        // the rule of the pack: in both mailboxes, with its links; undone with them
        list(, $done) = $this->remove($admin, ['tpl:test-pack/r1']);
        $this->assertSame(0, Workflow::whereIn('mailbox_id', [$a->id, $b->id])->count());
        $this->assertSame(0, Link::where('pack_key', 'test-pack')->count());
        $this->actingAs($admin)->postJson(route('workflowpacks.graph.delete_undo'), ['backup_id' => $done['backup_id']])->assertStatus(200);
        $this->assertSame(2, Link::where('pack_key', 'test-pack')->whereNotNull('workflow_id')->count());
        $this->assertSame(2, Workflow::whereIn('mailbox_id', [$a->id, $b->id])->count());
    }

    public function testTheCleanUpOfAFolderIsRemovedByAnAdministratorAndUndone()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $agent = $this->user([$a], false, true);
        $this->assertNull(\Modules\WorkflowPacks\Services\Imap\Retention::save('Discussions', ['number' => 30, 'metric' => 'd'], 'trash', $admin));
        $key = 'dest:clean:'.substr(\Modules\WorkflowPacks\Services\Graph\GraphBuilder::folderKey('Discussions'), strlen('dest:'));

        $refused = $this->deletionPlan($agent, [$key]);
        $this->assertSame(0, $refused['count']);
        $this->assertNotEmpty($refused['refused'], 'a setting of the administrators');

        list($plan, $done) = $this->remove($admin, [$key]);
        $this->assertSame(1, $plan['count']);
        $this->assertStringContainsString('Discussions', $plan['lines'][0]);
        $this->assertTrue($done['ok']);
        $this->assertNull(\Modules\WorkflowPacks\Services\Imap\Retention::forFolder('Discussions'));

        $this->actingAs($admin)->postJson(route('workflowpacks.graph.delete_undo'), ['backup_id' => $done['backup_id']])->assertStatus(200);
        $back = \Modules\WorkflowPacks\Services\Imap\Retention::forFolder('Discussions');
        $this->assertSame([30, 'd', 'trash'], [(int) $back->number, $back->metric, $back->mode]);
    }

    public function testABlockLeftByARuleDeletedFromWorkflowsGoesAway()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $this->import($this->pack([$this->rule('r1')]), [$a], $admin);
        // deleted in Workflows itself: the diagram still shows the rule of the pack ("missing")
        $this->workflows($a)->first()->delete();
        $nodes = array_column($this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json()['nodes'], 'key');
        $this->assertContains('tpl:test-pack/r1', $nodes);

        list($plan, $done) = $this->remove($admin, ['tpl:test-pack/r1']);
        $this->assertSame(1, $plan['count']);
        $this->assertStringContainsString('Règle r1', $plan['lines'][0]);
        $this->assertTrue($done['ok']);
        $nodes = array_column($this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json()['nodes'], 'key');
        $this->assertNotContains('tpl:test-pack/r1', $nodes, 'the block went away');

        $this->actingAs($admin)->postJson(route('workflowpacks.graph.delete_undo'), ['backup_id' => $done['backup_id']])->assertStatus(200);
        $this->assertSame(1, Link::where('pack_key', 'test-pack')->where('workflow_key', 'r1')->count());

        // the only block of a rule without delay nor action (flow view)
        $bare = $this->workflow($a, 'Vide', [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]], []);
        list(, $done) = $this->remove($admin, ['fa:'.$bare->id.':none']);
        $this->assertTrue($done['ok']);
        $this->assertNull(Workflow::find($bare->id));
    }

    public function testTheTimerBlockOfARuleInTheOverview()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $subject = [['type' => 'subject', 'operator' => 'contains', 'value' => 'x']];
        $delay = [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => 30, 'metric' => 'd']]];
        $wf = $this->workflow($a, 'Plus tard', [$subject, $delay], [['type' => 'status', 'value' => '3']]);

        $data = $this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json();
        $timer = collect($data['nodes'])->firstWhere('key', 'dest:timer:wf:'.$wf->id);
        $this->assertNotNull($timer, 'a block of its own');
        $this->assertSame('timer', $timer['dest']);
        $this->assertStringContainsString('30', $timer['label']);
        $this->assertTrue(collect($data['edges'])->contains(function ($e) use ($wf) {
            return $e['kind'] === 'timer' && $e['from'] === 'wf:'.$wf->id && $e['to'] === 'dest:timer:wf:'.$wf->id;
        }));

        // deleting it removes the delay (said in the confirmation)
        list($plan, $done) = $this->remove($admin, ['dest:timer:wf:'.$wf->id]);
        $this->assertNotEmpty($plan['warnings']);
        $this->assertTrue($done['ok']);
        $this->assertSame([$subject], $wf->fresh()->conditions);
        $keys = array_column($this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json()['nodes'], 'key');
        $this->assertNotContains('dest:timer:wf:'.$wf->id, $keys);
    }

    public function testATimerGoesAfterTheBlockOfTheStepBefore()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([], true);
        $subject = [['type' => 'subject', 'operator' => 'contains', 'value' => 'x']];
        $delay = function ($days) {
            return [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => $days, 'metric' => 'd']]];
        };
        $this->workflow($a, 'Pending', [$subject], [['type' => 'status', 'value' => '2']]);
        $one = $this->workflow($a, 'After 1 day', [$subject, $delay(1)], [['type' => 'status', 'value' => '3']]);
        $two = $this->workflow($a, 'After 2 days', [$subject, $delay(2)], [['type' => 'assign', 'value' => '-1'], ['type' => 'status', 'value' => '1']]);

        $data = $this->actingAs($admin)->getJson(route('workflowpacks.graph.data'))->json();
        $nodes = collect($data['nodes'])->keyBy('key');
        $edges = collect($data['edges']);
        $has = function ($from, $to, $kind) use ($edges) {
            return $edges->contains(function ($e) use ($from, $to, $kind) {
                return $e['from'] === $from && $e['to'] === $to && $e['kind'] === $kind;
            });
        };
        // a flow: mailbox -> Pending -> rule 1 -> After 1 day -> Closed (its own block) -> rule 2 -> After 2 days -> its blocks
        $r1 = 'wf:'.$one->id;
        $r2 = 'wf:'.$two->id;
        $t1 = 'dest:timer:'.$r1;
        $t2 = 'dest:timer:'.$r2;
        $s1 = 'dest:step:'.$one->id.':0:0';
        $this->assertTrue($has('dest:status:pending', $r1, 'then'), 'the next rule after the block of the rule before');
        $this->assertTrue($has($r1, $t1, 'timer'));
        $this->assertTrue($has($t1, $s1, 'sends'));
        $this->assertSame('closed', $nodes[$s1]['dest']);
        $this->assertFalse($has($t1, 'dest:status:closed', 'sends'), 'never the shared block');
        $this->assertTrue($has($s1, $r2, 'then'));
        $this->assertTrue($has($r2, $t2, 'timer'));
        $this->assertTrue($has($t2, 'dest:step:'.$two->id.':1:1', 'sends'));
        $this->assertSame([1, 2, 3, 4, 5], [$nodes[$r1]['depth'], $nodes[$t1]['depth'], $nodes[$s1]['depth'], $nodes[$r2]['depth'], $nodes[$t2]['depth']]);
        // still applied in its mailbox (panel), not drawn from it
        $applies = $edges->first(function ($e) use ($one) {
            return $e['kind'] === 'applies' && $e['workflow_id'] === $one->id;
        });
        $this->assertTrue($applies['chained']);
        $this->assertArrayNotHasKey('depth', $nodes['wf:'.$this->firstRuleId($a)], 'the first rule comes from its mailbox');

        // deleting a block after a timer removes that action only
        list(, $done) = $this->remove($admin, ['dest:step:'.$two->id.':1:1']);
        $this->assertTrue($done['ok']);
        $this->assertSame([['type' => 'assign', 'value' => '-1']], array_values(array_map(function ($action) {
            return array_intersect_key($action, ['type' => 1, 'value' => 1]);
        }, $two->fresh()->actions[0])));
    }

    protected function firstRuleId($mailbox)
    {
        return (int) Workflow::where('mailbox_id', $mailbox->id)->orderBy('id')->value('id');
    }

    public function testRightsAndAChangeInTheMeantime()
    {
        $a = $this->mailbox('A');
        $b = $this->mailbox('B');
        $agent = $this->user([$a], false, true);
        $mine = $this->workflow($a, 'Mienne', [], [['type' => 'status', 'value' => '3']]);
        $other = $this->workflow($b, 'Autre', [], [['type' => 'status', 'value' => '3']]);

        $this->assertSame(0, $this->deletionPlan($agent, ['wf:'.$other->id])['count'], 'a mailbox the user does not manage');
        $this->assertNotEmpty($this->deletionPlan($agent, ['mb:'.$a->id])['refused']);
        $this->assertNotEmpty($this->deletionPlan($agent, ['pack:test-pack'])['refused']);

        $plan = $this->deletionPlan($agent, ['wf:'.$mine->id]);
        $mine->name = 'Changée entre-temps';
        $mine->save();
        $this->actingAs($agent)->postJson(route('workflowpacks.graph.delete'), ['keys' => ['wf:'.$mine->id], 'token' => $plan['token']])->assertStatus(422);
        $this->assertNotNull(Workflow::find($mine->id));
        $this->assertNotNull(Workflow::find($other->id));
    }
}
