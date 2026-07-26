<?php

namespace Feeder\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Feeder\Core\Contracts\PermissionServiceInterface;
use Feeder\Core\Services\PermissionService;

class FeederCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
