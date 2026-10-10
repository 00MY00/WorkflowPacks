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

    /** Status written by the update check (Updater::check(), daily from the scheduler), in the options table. */
    protected function latest($version)
    {
        \App\Option::set(Updater::OPTION_STATUS, json_encode(['latest' => $version, 'last_check_at' => date('c'), 'source' => 'release']));
        Updater::clearRequest();
    }

    public function testTheCheckResultIsKeptEvenWhenTheStorageFolderIsNotWritable()
    {
        $admin = $this->user([], true);
        \App\Option::set(Updater::OPTION_STATUS, '');
        // a newer release, as GitHub would answer it (local file instead of the network)
        $dir = sys_get_temp_dir().'/wp-release-'.self::uid();
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/module.json', json_encode(['alias' => 'workflowpacks', 'version' => '99.0.0']));
        config(['workflowpacks.update_url' => $dir]);
        // storage/app/workflowpacks owned by another user (created by cron): simulated by a file in its place
        $storage = Updater::dir();
        $moved = is_dir($storage) ? $storage.'.bak-'.self::uid() : null;
        if ($moved) {
            rename($storage, $moved);
        }
        file_put_contents($storage, 'not a folder');
        try {
            $this->actingAs($admin)->post(route('workflowpacks.check_update'), ['back' => 'settings'])->assertRedirect();
            $this->assertSame('available', Updater::state(), 'result saved in the database');
            $page = $this->actingAs($admin)->get(route('settings', ['section' => 'workflowpacks']));
            $this->seeIn($page, '99.0.0');
            $this->seeIn($page, route('workflowpacks.update_now'));
        } finally {
            @unlink($storage);
            if ($moved) {
                rename($moved, $storage);
            }
            @unlink($dir.'/module.json');
            @rmdir($dir);
        }
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
