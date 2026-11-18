<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Entities\Flow;
use Modules\WorkflowPacks\Services\Engine\Engine;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * Phones and tablets: the sections in a menu, the way back at the top of the sub-pages and from the settings, and
 * blocks not filled in yet titled without the names of texts.
 */
class MobileNavigationTest extends TestCase
{
    public function testTheSectionsMenuAndTheWaysBack()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(Engine::OPTION, true);

        $page = $this->actingAs($admin)->get(route('workflowpacks.index'))->assertStatus(200)->getContent();
        $this->assertStringContainsString('data-wp-tabs', $page);
        $this->assertStringContainsString('aria-controls="wp-tabs"', $page);
        $this->assertStringContainsString(e(__('workflowpacks::messages.sections')), $page);
        $this->assertStringNotContainsString('class="wp-back"', $page, 'a tab page: no way back, the sections are there');

        $flow = Flow::create(['name' => 'Flux', 'mailbox_ids' => [$a->id], 'active' => false, 'once' => true, 'created_by' => $admin->id,
            'draft' => ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail']], 'links' => []]]);
        $reapply = $this->actingAs($admin)->get(route('workflowpacks.reapply', ['id' => $flow->id]))->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('#<a class="wp-back" href="([^"]+)">‹ ([^<]+)</a>#', $reapply, $m));
        $this->assertSame(route('workflowpacks.flows', ['flow' => $flow->id]), html_entity_decode($m[1]));
        $this->assertSame(e(__('workflowpacks::reapply.back')), trim($m[2]));

        $settings = $this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks']))->assertStatus(200)->getContent();
        $this->assertStringContainsString('href="'.route('workflowpacks.index').'">‹ '.e(__('workflowpacks::messages.back_to_module')), $settings);
    }

    public function testABlockNotFilledInIsTitledWithoutNamesOfTexts()
    {
        $a = $this->mailbox('A');
        $admin = $this->user([$a], true);
        \Option::set(Engine::OPTION, true);
        $id = $this->actingAs($admin)->postJson(route('workflowpacks.flows.store'), ['name' => 'Vide'])->json()['flow']['id'];
        $draft = ['blocks' => [['id' => 'b1', 'type' => 'trigger.new_mail'], ['id' => 'b2', 'type' => 'condition.ticket', 'params' => []],
            ['id' => 'b3', 'type' => 'action.status', 'params' => []], ['id' => 'b4', 'type' => 'action.status', 'params' => ['status' => 'nope']]], 'links' => []];
        $saved = $this->actingAs($admin)->postJson(route('workflowpacks.flows.save', ['id' => $id]), ['name' => 'Vide', 'mailbox_ids' => [$a->id], 'once' => true, 'draft' => $draft])->json();
        foreach ($saved['titles'] as $block => $title) {
            $this->assertStringNotContainsString('workflowpacks::', $title, $block);
        }
        $this->assertStringContainsString('…', $saved['titles']['b2']);
    }
}
