<?php

namespace Feeder\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Feeder\Core\Console\Commands\AuditFardarLocationsCommand;
use Feeder\Core\Console\Commands\AuditLegacyOrderLifecycleCommand;
use Feeder\Core\Console\Commands\CleanupFardarLocationsCommand;
use Feeder\Core\Console\Commands\ImportCourierLocationsCommand;
use Feeder\Core\Console\Commands\SyncCourierLocationsCommand;
use Feeder\Core\Contracts\PermissionServiceInterface;
use Feeder\Core\Services\CountryRegistrationRuleService;
use Feeder\Core\Services\Courier\CourierBookingAdapterResolver;
use Feeder\Core\Services\Courier\CourierConnectionService;
use Feeder\Core\Services\Courier\CourierConnectionTesterResolver;
use Feeder\Core\Services\Courier\CourierCredentialSchemaRegistry;
use Feeder\Core\Services\Courier\CourierLocationSyncService;
use Feeder\Core\Services\Courier\Curfox\CurfoxAuthService;
use Feeder\Core\Services\Courier\Curfox\CurfoxConfig;
use Feeder\Core\Services\Courier\Curfox\CurfoxLocationMapper;
use Feeder\Core\Services\Courier\Curfox\CurfoxMerchantClient;
use Feeder\Core\Services\Courier\Curfox\RoyalAccountMeta;
use Feeder\Core\Services\Courier\Curfox\RoyalBookingAdapter;
use Feeder\Core\Services\Courier\Curfox\RoyalCourierAccountSetupService;
use Feeder\Core\Services\Courier\Fardar\FardarApiClient;
use Feeder\Core\Services\Courier\Fardar\FardarBookingAdapter;
use Feeder\Core\Services\Courier\Fardar\FardarConfig;
use Feeder\Core\Services\Courier\Fardar\FardarCourierAccountSetupService;
use Feeder\Core\Services\Courier\Fardar\FardarLocationCleanupService;
use Feeder\Core\Services\Courier\Fardar\FardarLocationCsvMapper;
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressAuthService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressBookingAdapter;
use Feeder\Core\Services\Courier\TransExpress\TransExpressConfig;
use Feeder\Core\Services\Courier\TransExpress\TransExpressCourierAccountSetupService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressLocationMapper;
use Feeder\Core\Services\Courier\TransExpress\TransExpressMerchantClient;
use Feeder\Core\Services\PermissionService;
use Feeder\Core\Authorization\Services\MenuService;
use Feeder\Core\Support\CredentialsEncrypter;
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

        $this->app->singleton(CountryRegistrationRuleService::class);
        $this->app->singleton(CourierBookingAdapterResolver::class);
        $this->app->singleton(CourierCredentialSchemaRegistry::class);
        $this->app->singleton(CourierConnectionTesterResolver::class);
        $this->app->singleton(CourierConnectionService::class);
        $this->app->singleton(SupplierCourierAccountService::class);
        $this->app->singleton(CurfoxConfig::class);
        $this->app->singleton(CurfoxAuthService::class);
        $this->app->singleton(CurfoxMerchantClient::class);
        $this->app->singleton(RoyalAccountMeta::class);
        $this->app->singleton(RoyalCourierAccountSetupService::class);
        $this->app->singleton(RoyalBookingAdapter::class);
        $this->app->singleton(CurfoxLocationMapper::class);
        $this->app->singleton(TransExpressConfig::class);
        $this->app->singleton(TransExpressAuthService::class);
        $this->app->singleton(TransExpressMerchantClient::class);
        $this->app->singleton(TransExpressCourierAccountSetupService::class);
        $this->app->singleton(TransExpressBookingAdapter::class);
        $this->app->singleton(TransExpressLocationMapper::class);
        $this->app->singleton(FardarConfig::class);
        $this->app->singleton(FardarApiClient::class);
        $this->app->singleton(FardarCourierAccountSetupService::class);
        $this->app->singleton(FardarBookingAdapter::class);
        $this->app->singleton(FardarLocationCsvMapper::class);
        $this->app->singleton(FardarLocationCleanupService::class);
        $this->app->singleton(CourierLocationSyncService::class);
        $this->app->singleton(CredentialsEncrypter::class);
    }

    public function boot(): void
    {
        // OrderConfirmed must not drive courier booking. Booking happens before
        // CONFIRMED in Manual Create, Call Center, and bank-transfer approval.
        // BookCourierWhenOrderConfirmed is retained only as a no-op safety net.

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCourierLocationsCommand::class,
                ImportCourierLocationsCommand::class,
                AuditFardarLocationsCommand::class,
                AuditLegacyOrderLifecycleCommand::class,
                CleanupFardarLocationsCommand::class,
            ]);
        }

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
