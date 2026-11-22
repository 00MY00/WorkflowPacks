<?php

namespace Modules\WorkflowPacks\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WorkflowPacks\Services\Compat\Checker;

/**
 * Base of the module's tests. They run inside a FreeScout installation (Modules/WorkflowPacks), each test inside a
 * transaction that is rolled back. The Workflows test stub (tests/stubs/Workflows) is there only for the separation
 * from Workflows (SeparationTest). See Tests/README.md.
 */
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Checker::forget();
        \App\Module::clearModulesCache();
    }

    protected function tearDown(): void
    {
        Checker::forget();
        // the model of the Workflows test stub (separation tests): its listeners never leak into the next test
        if (class_exists(\Modules\WorkflowPacks\Services\Separation::WORKFLOWS_MODEL)) {
            $class = \Modules\WorkflowPacks\Services\Separation::WORKFLOWS_MODEL;
            $class::flushEventListeners();
        }
        parent::tearDown();
        restore_error_handler();
        restore_exception_handler();
    }

    protected static function uid()
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    protected function mailbox($name)
    {
        $mailbox = new \App\Mailbox();
        $mailbox->name = $name.' '.self::uid();
        $mailbox->email = 'mb-'.self::uid().'@example.test';
        $mailbox->save();

        return $mailbox;
    }

    /** @param bool|null $workflows true: "manage workflows" permission (Workflow::PERM_EDIT_WORKFLOWS = 6) */
    /** @var mixed the user of the previous actingAs() */
    protected $wpActingId;

    /**
     * FreeScout ≥ 1.8.250 checks the session against the user's password (AuthenticateSession): a test that changes
     * user starts a new session, as a new browser would. The same user keeps the session.
     */
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $driver = null)
    {
        if ($this->wpActingId !== null && $this->wpActingId !== $user->getAuthIdentifier()) {
            $this->flushSession();
        }
        $this->wpActingId = $user->getAuthIdentifier();

        return parent::actingAs($user, $driver);
    }

    protected function user(array $mailboxes = [], $admin = false, $workflows = false)
    {
        $user = new \App\User();
        $user->first_name = 'Test';
        $user->last_name = self::uid();
        $user->email = 'u-'.self::uid().'@example.test';
        $user->password = bcrypt('pw-'.self::uid());
        $user->role = $admin ? \App\User::ROLE_ADMIN : \App\User::ROLE_USER;
        if ($workflows) {
            $user->permissions = [6 => true];
        }
        $user->save();
        $user->mailboxes()->sync(array_map(function ($m) {
            return $m->id;
        }, $mailboxes));

        return $user->fresh();
    }

    /** Rules of the Workflows test stub in a mailbox (only the separation from Workflows still deals with them). */
    protected function workflows($mailbox)
    {
        return \Modules\Workflows\Entities\Workflow::where('mailbox_id', $mailbox->id)->orderBy('id')->get();
    }

    protected function seeIn($response, $text)
    {
        $this->assertStringContainsString((string) $text, $response->getContent());

        return $response;
    }
}
