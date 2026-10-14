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
 *   schedule                    (app/Console/Kernel.php)                       -> clean-up of the e-mail folders
 *
 * Updates: module.json declares latestVersionUrl / latestVersionZipUrl (FreeScout's Manage › Modules page, and the
 * UpdateGuard module for safe updates; workflowpacks:health is its health check).
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
            \Modules\WorkflowPacks\Console\HealthCommand::class,
            \Modules\WorkflowPacks\Console\CleanFoldersCommand::class,
        ]);
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', self::ALIAS);
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', self::ALIAS);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');

        // messages of the services in the user's language (fallback: English, then the French reference catalogue)
        \Modules\WorkflowPacks\Services\Pack\Problems::$translator = function ($key, array $params) {
            $id = 'workflowpacks::texts.'.$key;
            $text = __($id, $params);

            return is_string($text) && $text !== $id ? $text : null;
        };

        $this->registerMenus();
        $this->registerPhoneMenu();
        $this->registerSchedule();
        $this->registerSettings();
        // optional action "file into an e-mail folder [WP]" (off by default, Manage › Settings › Workflow Packs)
        \Modules\WorkflowPacks\Services\Imap\ImapFolders::register();
        // current e-mail folder of each ticket: read API for the other modules, shown in the ticket, removed with it
        \Modules\WorkflowPacks\Services\Imap\TicketFolders::register();
        // condition "E-mail tag" of the rules: origin tags of RefreshGlobal (spam, inbox, folder:…), when it is installed
        \Modules\WorkflowPacks\Services\MailTags::register();
    }

    /**
     * Manage › Settings › Workflow Packs (administrators): e-mail folders, their clean-up, who updates the module.
     * Same filters as RefreshGlobal and
     * Refresh: "settings.sections", "settings.section_settings" (the keys FreeScout saves itself in its options
     * table, SettingsController::processSave) and "settings.view".
     */
    protected function registerSettings()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[self::ALIAS] = ['title' => 'Workflow Packs', 'icon' => 'transfer', 'order' => 170];

            return $sections;
        }, 40);
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== self::ALIAS) {
                return $settings;
            }

            return array_merge($settings, [
                \Modules\WorkflowPacks\Services\Imap\ImapFolders::OPTION_ENABLED => \Modules\WorkflowPacks\Services\Imap\ImapFolders::enabled(),
                \Modules\WorkflowPacks\Services\Imap\ImapFolders::OPTION_PREFIX => \Modules\WorkflowPacks\Services\Imap\ImapFolders::prefix(),
                \Modules\WorkflowPacks\Services\Imap\ImapFolders::OPTION_PARENT => \Modules\WorkflowPacks\Services\Imap\ImapFolders::parentMode(),
            ]);
        }, 20, 2);
        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section === self::ALIAS ? 'workflowpacks::settings' : $view;
        }, 20, 2);
    }

    /**
     * Phones with the Refresh module: Refresh replaces the left bar by a fixed tab bar (Tickets, Contacts, Dashboard,
     * Notifications, Profile — Modules/Refresh/Public/js/mobile.js), with no room for a module. The entry is added to
     * the "Profile" panel instead (Refresh's burger menu), by Public/js/workflowpacks-phone.js, from the settings
     * written here in <head>. Without Refresh, the entry is in FreeScout's own burger menu (Manage).
     */
    protected function registerPhoneMenu()
    {
        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath(self::ALIAS).'/js/workflowpacks-phone.js';

            return $scripts;
        });
        \Eventy::addAction('layout.head', function () {
            try {
                if (!self::userCanUse() || !\App\Module::isActive('refresh')) {
                    return;
                }
                $config = [
                    'url'    => route('workflowpacks.index'),
                    'label'  => __('workflowpacks::messages.menu'),
                    'icon'   => asset(\Module::getPublicPath(self::ALIAS).'/img/module.svg'),
                    'active' => self::isModulePage(),
                ];
                echo '<meta name="workflowpacks" content="'.e(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'">'."\n";
            } catch (\Throwable $e) {
                // optional entry
            }
        });
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

    /** Automatic clean-up of the managed e-mail folders (updates: FreeScout's Modules page, or UpdateGuard). */
    protected function registerSchedule()
    {
        \Eventy::addFilter('schedule', function ($schedule) {
            try {
                // automatic clean-up of the managed e-mail folders (does nothing without a setting)
                $schedule->command('workflowpacks:clean-folders')
                    ->everyFifteenMinutes()
                    ->withoutOverlapping();
            } catch (\Throwable $e) {
                // no clean-up rather than a broken scheduler
            }

            return $schedule;
        });
    }
}
