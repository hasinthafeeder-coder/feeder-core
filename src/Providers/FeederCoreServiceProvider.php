<?php

namespace Feeder\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Feeder\Core\Contracts\PermissionServiceInterface;
use Feeder\Core\Services\PermissionService;

class FeederCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/feeder.php', 'feeder');

        $this->app->singleton(
            PermissionServiceInterface::class,
            PermissionService::class
        );
    }

    public function boot(): void
    {
        //
    }
}
