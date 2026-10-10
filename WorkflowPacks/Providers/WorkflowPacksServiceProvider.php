<?php

namespace Modules\WorkflowPacks\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Workflow Packs: import / export / update of sets of rules of the official Workflows module.
 *
 * Nothing of FreeScout or of the Workflows module is modified. The module only adds its own routes, views,
 * translations, tables and commands, and listens to FreeScout hooks:
 *   menu.manage.after_mailboxes (resources/views/layouts/app.blade.php)       -> "Workflow Packs" in Manage
 *   mailboxes.settings.menu     (resources/views/mailboxes/settings_menu.blade.php) -> entry in the mailbox menu
 *   refresh.rail_items          (Refresh module, when installed)               -> entry in Refresh's left bar
 *   schedule                    (app/Console/Kernel.php)                       -> daily update check
 */
class WorkflowPacksServiceProvider extends ServiceProvider
{
    const ALIAS = 'workflowpacks';

    protected $defer = false;

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', self::ALIAS);
        $this->commands([
            \Modules\WorkflowPacks\Console\CheckCommand::class,
            \Modules\WorkflowPacks\Console\UpdateCommand::class,
        ]);
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', self::ALIAS);
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', self::ALIAS);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');

        $this->registerMenus();
        $this->registerSchedule();
    }

    /** The user may manage the workflows of at least one mailbox (Workflows' own permission rule). */
    public static function userCanUse($user = null)
    {
        $user = $user ?: auth()->user();
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        try {
            return Bridge::classAvailable() && Bridge::editableMailboxes($user)->isNotEmpty();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isModulePage()
    {
        try {
            return \Route::is('workflowpacks.*');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function registerMenus()
    {
        \Eventy::addAction('menu.manage.after_mailboxes', function () {
            try {
                if (self::userCanUse()) {
                    echo '<li class="'.(self::isModulePage() ? 'active' : '').'"><a href="'.e(route('workflowpacks.index')).'">'
                        .e(__('workflowpacks::messages.menu')).'</a></li>';
                }
            } catch (\Throwable $e) {
                // optional entry: never break the layout
            }
        });

        \Eventy::addAction('mailboxes.settings.menu', function ($mailbox) {
            try {
                $user = auth()->user();
                if ($user && $mailbox && Bridge::classAvailable() && Bridge::canEdit($user, $mailbox->id)) {
                    echo '<li class="'.(self::isModulePage() ? 'active' : '').'"><a href="'.e(route('workflowpacks.index', ['mailbox_id' => $mailbox->id])).'">'
                        .'<i class="glyphicon glyphicon-transfer"></i> '.e(__('workflowpacks::messages.menu')).'</a></li>';
                }
            } catch (\Throwable $e) {
                // optional entry
            }
        }, 30);

        // Refresh's left bar (documented extension point of the Refresh module)
        \Eventy::addFilter('refresh.rail_items', function ($items) {
            try {
                if (self::userCanUse()) {
                    $items[] = [
                        'url'    => route('workflowpacks.index'),
                        'label'  => __('workflowpacks::messages.menu'),
                        'icon'   => asset(\Module::getPublicPath(self::ALIAS).'/img/module.svg'),
                        'active' => self::isModulePage(),
                        'order'  => 60,
                    ];
                }
            } catch (\Throwable $e) {
                // optional entry
            }

            return $items;
        });
    }

    /** Daily update check (installs only when the automatic update is on) + "Update now" requests. */
    protected function registerSchedule()
    {
        \Eventy::addFilter('schedule', function ($schedule) {
            try {
                $time = (string) config('workflowpacks.auto_update_time', '03:40');
                $schedule->command('workflowpacks:update --scheduled')
                    ->dailyAt(preg_match('/^\d{1,2}:\d{2}$/', $time) ? $time : '03:40')
                    ->withoutOverlapping();
                $schedule->command('workflowpacks:update --requested')
                    ->everyMinute()
                    ->withoutOverlapping();
            } catch (\Throwable $e) {
                // no automatic update rather than a broken scheduler
            }

            return $schedule;
        });
    }
}
