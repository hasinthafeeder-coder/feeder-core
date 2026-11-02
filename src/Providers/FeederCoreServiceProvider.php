<?php

namespace Feeder\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Feeder\Core\Console\Commands\AuditFardarLocationsCommand;
use Feeder\Core\Console\Commands\AuditLegacyOrderLifecycleCommand;
use Feeder\Core\Console\Commands\CleanupFardarLocationsCommand;
use Feeder\Core\Console\Commands\ImportCourierLocationsCommand;
use Feeder\Core\Console\Commands\ProcessDailyResellerSettlementBatchesCommand;
use Feeder\Core\Console\Commands\ProcessDailySupplierSettlementBatchesCommand;
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
use Feeder\Core\Services\Courier\ReceiptImport\CourierReceiptImportMatcher;
use Feeder\Core\Services\Courier\ReceiptImport\CourierReceiptImportReconciler;
use Feeder\Core\Services\Courier\ReceiptImport\CourierReceiptImportService;
use Feeder\Core\Services\Courier\ReceiptImport\CourierRemittanceApplicationService;
use Feeder\Core\Services\Courier\ReceiptImport\CourierRemittanceColumnExtractor;
use Feeder\Core\Services\Finance\FinancialDailySummaryReader;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Services\Courier\ReceiptImport\CourierReceiptInvoiceFileSummaryBuilder;
use Feeder\Core\Services\Courier\ReceiptImport\CourierReceiptInvoiceParserResolver;
use Feeder\Core\Services\Courier\ReceiptImport\CourierReceiptSpreadsheetReader;
use Feeder\Core\Services\Courier\ReceiptImport\FardarInvoiceParser;
use Feeder\Core\Services\Courier\ReceiptImport\FeederCourierPaymentTemplateParser;
use Feeder\Core\Services\Courier\ReceiptImport\RoyalExpressInvoiceParser;
use Feeder\Core\Services\Courier\ReceiptImport\TransExpressInvoiceParser;
use Feeder\Core\Services\Settlement\ResellerDebtObligationService;
use Feeder\Core\Services\Settlement\ResellerEarningEligibilityEvaluator;
use Feeder\Core\Services\Settlement\ResellerEarningObligationService;
use Feeder\Core\Services\Settlement\ResellerNonDeliveryFineSettingsService;
use Feeder\Core\Services\Settlement\ResellerSettlementTransferFeeSettingsService;
use Feeder\Core\Services\Settlement\ResellerOutstandingDebtQueryService;
use Feeder\Core\Services\Settlement\ResellerPlusReportQueryService;
use Feeder\Core\Services\Settlement\ResellerReturnFineCalculator;
use Feeder\Core\Services\Settlement\ResellerSettlementBatchService;
use Feeder\Core\Services\Settlement\ResellerSettlementDailyBatchProcessor;
use Feeder\Core\Services\Settlement\ResellerSettlementDebtAllocator;
use Feeder\Core\Services\Settlement\ResellerSettlementDebtOffsetCalculator;
use Feeder\Core\Services\Settlement\ResellerSettlementEligibilityCollector;
use Feeder\Core\Services\Settlement\ResellerSettlementFinancialCalculator;
use Feeder\Core\Services\Settlement\ResellerSettlementGenerationService;
use Feeder\Core\Services\Settlement\ResellerSettlementLockService;
use Feeder\Core\Services\Settlement\ResellerSettlementNumberGenerator;
use Feeder\Core\Services\Settlement\ResellerSettlementPaymentService;
use Feeder\Core\Services\Settlement\SupplierSettlementBankExportService;
use Feeder\Core\Services\Settlement\SupplierSettlementBankExportWorkbookBuilder;
use Feeder\Core\Services\Settlement\SupplierSettlementBatchService;
use Feeder\Core\Services\Settlement\SupplierSettlementDailyBatchProcessor;
use Feeder\Core\Services\Settlement\SupplierSettlementGenerationService;
use Feeder\Core\Services\Settlement\SupplierSettlementLockService;
use Feeder\Core\Services\Settlement\SupplierSettlementNumberGenerator;
use Feeder\Core\Services\Settlement\SupplierSettlementPayableEligibilityEvaluator;
use Feeder\Core\Support\SimpleXlsxWriter;
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressAuthService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressBookingAdapter;
use Feeder\Core\Services\Courier\TransExpress\TransExpressConfig;
use Feeder\Core\Services\Courier\TransExpress\TransExpressCourierAccountSetupService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressLocationMapper;
use Feeder\Core\Services\Courier\TransExpress\TransExpressMerchantClient;
use Feeder\Core\Services\PermissionService;
use Feeder\Core\Authorization\Services\MenuService;
use Feeder\Core\Services\Expense\CompanyExpenseLedgerService;
use Feeder\Core\Services\Expense\CompanyExpenseReferenceGenerator;
use Feeder\Core\Support\CredentialsEncrypter;
use Feeder\Core\Support\MoneyNormalizer;
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
        $this->app->singleton(MoneyNormalizer::class);
        $this->app->singleton(CompanyExpenseReferenceGenerator::class);
        $this->app->singleton(CompanyExpenseLedgerService::class);
        $this->app->singleton(CourierReceiptSpreadsheetReader::class);
        $this->app->singleton(FeederCourierPaymentTemplateParser::class);
        $this->app->singleton(RoyalExpressInvoiceParser::class);
        $this->app->singleton(TransExpressInvoiceParser::class);
        $this->app->singleton(FardarInvoiceParser::class);
        $this->app->singleton(CourierReceiptInvoiceParserResolver::class);
        $this->app->singleton(CourierReceiptInvoiceFileSummaryBuilder::class);
        $this->app->singleton(CourierReceiptImportMatcher::class);
        $this->app->singleton(CourierReceiptImportReconciler::class);
        $this->app->singleton(CourierRemittanceColumnExtractor::class);
        $this->app->singleton(FinancialDailySummaryUpdater::class);
        $this->app->singleton(FinancialDailySummaryReader::class);
        $this->app->singleton(CourierRemittanceApplicationService::class);
        $this->app->singleton(CourierReceiptImportService::class);
        $this->app->singleton(SupplierSettlementNumberGenerator::class);
        $this->app->singleton(SupplierSettlementPayableEligibilityEvaluator::class);
        $this->app->singleton(SupplierSettlementBatchService::class);
        $this->app->singleton(SupplierSettlementGenerationService::class);
        $this->app->singleton(SupplierSettlementDailyBatchProcessor::class);
        $this->app->singleton(SupplierSettlementLockService::class);
        $this->app->singleton(SimpleXlsxWriter::class);
        $this->app->singleton(SupplierSettlementBankExportWorkbookBuilder::class);
        $this->app->singleton(SupplierSettlementBankExportService::class);
        $this->app->singleton(ResellerNonDeliveryFineSettingsService::class);
        $this->app->singleton(ResellerSettlementTransferFeeSettingsService::class);
        $this->app->singleton(ResellerReturnFineCalculator::class);
        $this->app->singleton(ResellerDebtObligationService::class);
        $this->app->singleton(ResellerOutstandingDebtQueryService::class);
        $this->app->singleton(ResellerSettlementFinancialCalculator::class);
        $this->app->singleton(ResellerEarningEligibilityEvaluator::class);
        $this->app->singleton(ResellerEarningObligationService::class);
        $this->app->singleton(ResellerSettlementDebtOffsetCalculator::class);
        $this->app->singleton(ResellerPlusReportQueryService::class);
        $this->app->singleton(ResellerSettlementNumberGenerator::class);
        $this->app->singleton(ResellerSettlementEligibilityCollector::class);
        $this->app->singleton(ResellerSettlementDebtAllocator::class);
        $this->app->singleton(ResellerSettlementBatchService::class);
        $this->app->singleton(ResellerSettlementGenerationService::class);
        $this->app->singleton(ResellerSettlementLockService::class);
        $this->app->singleton(ResellerSettlementPaymentService::class);
        $this->app->singleton(ResellerSettlementDailyBatchProcessor::class);
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
                ProcessDailySupplierSettlementBatchesCommand::class,
                ProcessDailyResellerSettlementBatchesCommand::class,
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
