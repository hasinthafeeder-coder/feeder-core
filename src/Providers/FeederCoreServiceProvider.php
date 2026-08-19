<?php

namespace Feeder\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Feeder\Core\Contracts\PermissionServiceInterface;
use Feeder\Core\Services\PermissionService;
use Feeder\Core\Authorization\Services\MenuService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;

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
        Gate::before(function ($user, string $ability) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($ability)) {
                return true;
            }

            return null;
        });

        View::composer('layout_main.app', function ($view) {
            if (auth()->check()) {
                $view->with(
                    'menu',
                    app(MenuService::class)->getForUser(auth()->user())
                );
            }
        });
    }
}
