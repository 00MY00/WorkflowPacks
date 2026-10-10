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
        $this->seeIn($page, 'Workflow Packs');
    }

    /** Status file written by the update check (Updater::check(), daily from the scheduler). */
    protected function latest($version)
    {
        @mkdir(Updater::dir(), 0775, true);
        $file = Updater::dir('update-status.json');
        $this->statusBackup = is_file($file) ? file_get_contents($file) : null;
        file_put_contents($file, json_encode(['latest' => $version, 'last_check_at' => date('c'), 'source' => 'release']));
        Updater::clearRequest();
    }

    protected $statusBackup = null;

    protected function tearDown(): void
    {
        $file = Updater::dir('update-status.json');
        if ($this->statusBackup !== null) {
            file_put_contents($file, $this->statusBackup);
        } elseif (is_file($file)) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testUpdateNowButtonIsAlwaysThereAndTheBannerOnlyWhenANewVersionIsKnown()
    {
        $admin = $this->user([], true);

        // up to date (or last check older than the release): "Update now" still offered, no banner
        $this->latest(Updater::currentVersion());
        $page = $this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks']));
        $this->seeIn($page, route('workflowpacks.update_now'));
        $this->assertStringNotContainsString('wp-update-banner', $this->actingAs($admin)->get(route('workflowpacks.index'))->getContent());

        // newer version: banner + button on the settings page and on the module pages
        $this->latest('99.0.0');
        $page = $this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks']));
        $this->seeIn($page, 'wp-update-banner');
        $this->seeIn($page, route('workflowpacks.update_now'));
        $this->seeIn($page, '99.0.0');
        $index = $this->actingAs($admin)->get(route('workflowpacks.index'));
        $this->seeIn($index, 'wp-update-banner');
        $this->seeIn($index, route('workflowpacks.update_now'));

        // clicked: the request is recorded and the banner says it is running soon
        $this->actingAs($admin)->post(route('workflowpacks.update_now'))->assertRedirect(route('workflowpacks.diagnostic'));
        $this->seeIn($this->actingAs($admin)->get(route('workflowpacks.index')), e(__('workflowpacks::messages.update_state_requested')));
        Updater::clearRequest();
    }

    public function testTheBannerIsNotShownToNonAdministrators()
    {
        $a = $this->mailbox('A');
        $agent = $this->user([$a], false, true);
        $this->latest('99.0.0');
        $page = $this->actingAs($agent)->get(route('workflowpacks.index'));
        $page->assertStatus(200);
        $this->assertStringNotContainsString('wp-update-banner', $page->getContent());
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
