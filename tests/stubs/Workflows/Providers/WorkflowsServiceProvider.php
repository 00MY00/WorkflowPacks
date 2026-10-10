<?php

namespace Modules\Workflows\Providers;

use Illuminate\Support\ServiceProvider;

/** TEST STUB ONLY (see tests/stubs/README.md). */
class WorkflowsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function register()
    {
    }
}
