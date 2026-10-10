<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Services\Update\Updater;
use Modules\WorkflowPacks\Tests\TestCase;

/** Manage › Settings › Workflow Packs: update options (same layout as RefreshGlobal), saved by FreeScout. */
class SettingsTest extends TestCase
{
    public function testTheSectionIsListedAndShowsTheUpdateOptions()
    {
        $admin = $this->user([], true);
        $page = $this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks']));
        $page->assertStatus(200);
        $this->seeIn($page, 'name="settings['.Updater::OPTION.']"');
        $this->seeIn($page, 'class="subheader"');
        $this->seeIn($page, route('workflowpacks.check_update'));
        $this->seeIn($page, route('workflowpacks.update_now'));
        $this->seeIn($page, 'Workflow Packs');
    }

    public function testFreeScoutSavesTheAutomaticUpdateSwitch()
    {
        $admin = $this->user([], true);
        Updater::setEnabled(false);
        $this->actingAs($admin)->post(route('settings.save', ['section' => 'workflowpacks']), [
            'settings' => [Updater::OPTION => '1'],
        ])->assertStatus(302);
        $this->assertTrue(Updater::enabled());
        $this->actingAs($admin)->post(route('settings.save', ['section' => 'workflowpacks']), [
            'settings' => [Updater::OPTION => '0'],
        ]);
        $this->assertFalse(Updater::enabled());
    }

    public function testUpdateNowFromTheSettingsGoesBackToTheSettings()
    {
        $admin = $this->user([], true);
        Updater::clearRequest();
        $response = $this->actingAs($admin)->post(route('workflowpacks.update_now'), ['back' => 'settings']);
        $response->assertRedirect(route('settings', ['section' => 'workflowpacks']));
        $this->assertNotSame('', Updater::pendingRequest(), 'request recorded for the scheduler');
        Updater::clearRequest();
    }

    public function testNonAdministratorsCannotSeeOrChangeTheSettings()
    {
        $a = $this->mailbox('A');
        $agent = $this->user([$a], false, true);
        $this->actingAs($agent)->get(route('settings', ['section' => 'workflowpacks']))->assertStatus(403);
        $this->actingAs($agent)->post(route('workflowpacks.update_now'), ['back' => 'settings'])->assertStatus(403);
    }
}
