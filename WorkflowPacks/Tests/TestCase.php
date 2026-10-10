<?php

namespace Modules\WorkflowPacks\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WorkflowPacks\Services\Compat\Checker;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Plan\Importer;
use Modules\WorkflowPacks\Services\Plan\Planner;

/**
 * Base of the module's tests. They run inside a FreeScout installation (Modules/WorkflowPacks) with a Workflows
 * module (the official one, or tests/stubs/Workflows on a test instance), each test inside a transaction that is
 * rolled back. See Tests/README.md.
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
        $class = \Modules\WorkflowPacks\Services\Workflows\Bridge::modelClass();
        if (class_exists($class)) {
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

    protected function pack(array $workflows, array $extra = [])
    {
        return $extra + [
            'format'       => 'freescout-workflow-pack',
            'version'      => 1,
            'key'          => 'test-pack',
            'name'         => 'Pack de test',
            'pack_version' => '1.0.0',
            'workflows'    => $workflows,
        ];
    }

    protected function rule($key, array $over = [])
    {
        return $over + [
            'key'        => $key,
            'name'       => 'Règle '.$key,
            'type'       => 'automatic',
            'settings'   => ['apply_to_prev' => true, 'max_executions' => 1],
            'conditions' => ['all' => [
                ['type' => 'new_or_reply', 'operator' => 'new'],
                ['any' => [
                    ['type' => 'subject', 'operator' => 'contains', 'value' => 'facture'],
                    ['type' => 'to', 'operator' => 'regex', 'value' => '/^{{mailbox.email}}$/i'],
                ]],
            ]],
            'actions'    => [
                ['type' => 'note', 'value' => ['body' => 'Boîte {{mailbox.name}}']],
                ['type' => 'status', 'value' => '2'],
            ],
        ];
    }

    protected function validated(array $pack)
    {
        $result = (new PackValidator((array) config('workflowpacks.limits')))->validate($pack);
        $this->assertSame([], $result['problems']->lines());

        return $result['pack'];
    }

    protected function plan(array $pack, array $mailboxes, $user, array $params = [])
    {
        return (new Planner())->plan($this->validated($pack), array_map(function ($m) {
            return $m->id;
        }, $mailboxes), $params, $user);
    }

    protected function import(array $pack, array $mailboxes, $user, array $decisions = [], array $options = [], array $params = [])
    {
        $plan = $this->plan($pack, $mailboxes, $user, $params);
        $result = (new Importer())->apply($plan, $decisions, $options, $user);

        return [$plan, $result];
    }

    protected function workflows($mailbox)
    {
        return \Modules\Workflows\Entities\Workflow::where('mailbox_id', $mailbox->id)->orderBy('id')->get();
    }

    protected function statuses(array $plan)
    {
        $out = [];
        foreach ($plan['rules'] as $key => $rule) {
            foreach ($rule['items'] as $mailboxId => $item) {
                $out[$key][$mailboxId] = $item['status'];
            }
        }

        return $out;
    }

    protected function seeIn($response, $text)
    {
        $this->assertStringContainsString((string) $text, $response->getContent());

        return $response;
    }
}
