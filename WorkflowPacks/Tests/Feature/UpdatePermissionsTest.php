<?php

namespace Modules\WorkflowPacks\Tests\Feature;

use Modules\WorkflowPacks\Services\Update\Updater;
use Modules\WorkflowPacks\Tests\TestCase;

/**
 * In-app update when folders belong to another system user (e.g. created by "sudo bash install.sh" while cron runs as
 * the web server user). Simulated with chmod: the tests run as the web server user (www-data) in the container.
 */
class UpdatePermissionsTest extends TestCase
{
    protected $release;

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root: permissions cannot be simulated');
        }
        // a newer release, as GitHub would answer it (local folder instead of the network)
        $this->release = sys_get_temp_dir().'/wp-release-'.self::uid();
        @mkdir($this->release, 0775, true);
        file_put_contents($this->release.'/module.json', json_encode(['alias' => 'workflowpacks', 'version' => '99.0.0']));
        config(['workflowpacks.update_url' => $this->release]);
        \App\Option::set(Updater::OPTION_STATUS, '');
    }

    protected function tearDown(): void
    {
        @unlink($this->release.'/module.json');
        @rmdir($this->release);
        parent::tearDown();
    }

    /** Runs $fn with $path made read-only, then puts its permissions back. */
    protected function readOnly($path, callable $fn)
    {
        @mkdir($path, 0775, true);
        $perms = fileperms($path) & 0777;
        chmod($path, 0555);
        clearstatcache();
        try {
            return $fn();
        } finally {
            chmod($path, $perms);
            clearstatcache();
        }
    }

    public function testAWorkFolderOfAnotherUserIsReplacedByAFallbackFolder()
    {
        $main = storage_path('app/workflowpacks');
        $this->readOnly($main, function () use ($main) {
            $base = Updater::base();
            $this->assertNotSame($main, $base, 'not the read-only folder');
            $this->assertSame(storage_path('app/workflowpacks-'.preg_replace('/[^A-Za-z0-9_-]/', '', Updater::processUser())), $base);
            $this->assertTrue(is_writable($base));
            $this->assertFalse(Updater::isRunning(), 'no lock held: not "running"');
        });
    }

    public function testAModuleFolderOfAnotherUserStopsTheUpdateWithTheFixingCommand()
    {
        $module = base_path('Modules/WorkflowPacks');
        $result = $this->readOnly($module, function () {
            return (new Updater(function () {
            }))->update();
        });
        $this->assertSame('failed', $result['result']);
        $this->assertStringNotContainsString('another update is running', $result['reason']);
        $this->assertStringContainsString(Updater::processUser(), $result['reason']);
        $this->assertStringContainsString('chown -R', $result['reason']);
        $this->assertStringContainsString($module, $result['reason']);
        $this->assertSame(Updater::currentVersion(), json_decode(file_get_contents($module.'/module.json'), true)['version'], 'nothing changed');
        $this->assertFalse(Updater::isRunning(), 'not left "running"');
        $this->assertSame('failed', Updater::status()['last_result']);
    }

    public function testARunningUpdateRecordedInTheDatabaseIsSeenWhateverTheFolder()
    {
        \App\Option::set(Updater::OPTION_STATUS, json_encode(['running_since' => date('c'), 'latest' => '99.0.0']));
        $this->assertSame('running', Updater::state());
        // a run killed long ago is not "running" forever
        \App\Option::set(Updater::OPTION_STATUS, json_encode(['running_since' => date('c', time() - 3600), 'latest' => '99.0.0']));
        $this->assertSame('available', Updater::state());
    }
}
