<?php

namespace Feeder\Core\Services;

use Feeder\Core\DTOs\BulkMarketAccessResult;
use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerMarketAccess;
use Feeder\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResellerMarketAccessService
{
    public function __construct(
        private readonly MarketService $marketService,
    ) {}

    public function grantMarketAccess(Company $company, Market|int $market, ?User $grantedBy = null): ResellerMarketAccess
    {
        $this->assertResellerCompany($company);

        $marketModel = $this->resolveGrantableMarket($market);

        $existing = ResellerMarketAccess::query()
            ->where('company_id', $company->id)
            ->where('market_id', $marketModel->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return ResellerMarketAccess::query()->create([
            'company_id' => $company->id,
            'market_id' => $marketModel->id,
            'granted_by' => $grantedBy?->id,
        ]);
    }

    public function revokeMarketAccess(Company $company, Market|int $market): void
    {
        $this->assertResellerCompany($company);

        $marketId = $market instanceof Market ? $market->id : $market;

        $currentCount = ResellerMarketAccess::query()
            ->where('company_id', $company->id)
            ->count();

        if ($currentCount <= 1) {
            throw ValidationException::withMessages([
                'allowed_market_ids' => 'A reseller must have at least one allowed market.',
            ]);
        }

        $deleted = ResellerMarketAccess::query()
            ->where('company_id', $company->id)
            ->where('market_id', $marketId)
            ->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'allowed_market_ids' => 'The selected market is not assigned to this reseller.',
            ]);
        }
    }

    /**
     * @param  list<int>  $marketIds
     */
    public function syncMarketAccess(Company $company, array $marketIds, ?User $grantedBy = null): void
    {
        $this->assertResellerCompany($company);

        $marketIds = array_values(array_unique(array_map('intval', $marketIds)));

        if ($marketIds === []) {
            throw ValidationException::withMessages([
                'allowed_market_ids' => 'At least one allowed market is required.',
            ]);
        }

        $activeCount = Market::query()
            ->whereIn('id', $marketIds)
            ->where('is_active', true)
            ->count();

        if ($activeCount !== count($marketIds)) {
            throw ValidationException::withMessages([
                'allowed_market_ids' => 'One or more selected markets are invalid or inactive.',
            ]);
        }

        DB::transaction(function () use ($company, $marketIds, $grantedBy): void {
            $existingIds = ResellerMarketAccess::query()
                ->where('company_id', $company->id)
                ->pluck('market_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $toRemove = array_diff($existingIds, $marketIds);

            if ($toRemove !== [] && count($marketIds) < 1) {
                throw ValidationException::withMessages([
                    'allowed_market_ids' => 'At least one allowed market is required.',
                ]);
            }

            if (count($marketIds) === 0) {
                throw ValidationException::withMessages([
                    'allowed_market_ids' => 'At least one allowed market is required.',
                ]);
            }

            if ($toRemove !== []) {
                ResellerMarketAccess::query()
                    ->where('company_id', $company->id)
                    ->whereIn('market_id', $toRemove)
                    ->delete();
            }

            $toAdd = array_diff($marketIds, $existingIds);

            if ($toAdd === []) {
                return;
            }

            $now = now();
            $rows = collect($toAdd)->map(fn (int $marketId) => [
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'market_id' => $marketId,
                'granted_by' => $grantedBy?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            ResellerMarketAccess::query()->insert($rows);
        });
    }

    public function hasMarketAccess(Company $company, Market|int $market): bool
    {
        $marketId = $market instanceof Market ? $market->id : $market;

        return ResellerMarketAccess::query()
            ->where('company_id', $company->id)
            ->where('market_id', $marketId)
            ->exists();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function bulkGrantMarketAccess(array $companyIds, int $marketId, ?User $grantedBy = null): BulkMarketAccessResult
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        $selected = count($companyIds);

        if ($selected === 0) {
            return new BulkMarketAccessResult(0, 0, 0, 0);
        }

        $market = Market::query()->find($marketId);

        if (! $market || ! $market->is_active) {
            throw ValidationException::withMessages([
                'market_id' => 'The selected market is invalid or inactive.',
            ]);
        }

        $companies = Company::query()
            ->with('portal')
            ->whereIn('id', $companyIds)
            ->get()
            ->keyBy('id');

        $existingAccess = ResellerMarketAccess::query()
            ->where('market_id', $marketId)
            ->whereIn('company_id', $companyIds)
            ->pluck('company_id')
            ->flip();

        $changed = 0;
        $skipped = 0;
        $failed = 0;
        $skipReasons = [];
        $rows = [];
        $now = now();

        foreach ($companyIds as $companyId) {
            $company = $companies->get($companyId);

            if (! $company) {
                $failed++;
                $skipReasons[] = "Company #{$companyId} was not found.";

                continue;
            }

            if (! $this->isResellerCompany($company)) {
                $skipped++;
                $skipReasons[] = "{$company->name} is not a reseller company.";

                continue;
            }

            if ($existingAccess->has($companyId)) {
                $skipped++;
                $skipReasons[] = "{$company->name} already has access to {$market->name}.";

                continue;
            }

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'market_id' => $marketId,
                'granted_by' => $grantedBy?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $changed++;
        }

        if ($rows !== []) {
            DB::transaction(function () use ($rows): void {
                ResellerMarketAccess::query()->insert($rows);
            });
        }

        return new BulkMarketAccessResult($selected, $changed, $skipped, $failed, $skipReasons);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function bulkRevokeMarketAccess(array $companyIds, int $marketId): BulkMarketAccessResult
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        $selected = count($companyIds);

        if ($selected === 0) {
            return new BulkMarketAccessResult(0, 0, 0, 0);
        }

        $market = Market::query()->find($marketId);

        if (! $market) {
            throw ValidationException::withMessages([
                'market_id' => 'The selected market is invalid.',
            ]);
        }

        $companies = Company::query()
            ->with('portal')
            ->whereIn('id', $companyIds)
            ->get()
            ->keyBy('id');

        $accessCounts = ResellerMarketAccess::query()
            ->select('company_id', DB::raw('COUNT(*) as total'))
            ->whereIn('company_id', $companyIds)
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        $assignedCompanyIds = ResellerMarketAccess::query()
            ->where('market_id', $marketId)
            ->whereIn('company_id', $companyIds)
            ->pluck('company_id')
            ->flip();

        $changed = 0;
        $skipped = 0;
        $failed = 0;
        $skipReasons = [];
        $deleteIds = [];

        foreach ($companyIds as $companyId) {
            $company = $companies->get($companyId);

            if (! $company) {
                $failed++;
                $skipReasons[] = "Company #{$companyId} was not found.";

                continue;
            }

            if (! $this->isResellerCompany($company)) {
                $skipped++;
                $skipReasons[] = "{$company->name} is not a reseller company.";

                continue;
            }

            if ($company->status !== CompanyStatus::ACTIVE) {
                $skipped++;
                $skipReasons[] = "{$company->name} is not an active reseller company.";

                continue;
            }

            if (! $assignedCompanyIds->has($companyId)) {
                $skipped++;
                $skipReasons[] = "{$company->name} does not have access to {$market->name}.";

                continue;
            }

            if ((int) ($accessCounts[$companyId] ?? 0) <= 1) {
                $skipped++;
                $skipReasons[] = "{$company->name} cannot lose its last allowed market.";

                continue;
            }

            $deleteIds[] = $companyId;
            $changed++;
        }

        if ($deleteIds !== []) {
            DB::transaction(function () use ($deleteIds, $marketId): void {
                ResellerMarketAccess::query()
                    ->where('market_id', $marketId)
                    ->whereIn('company_id', $deleteIds)
                    ->delete();
            });
        }

        return new BulkMarketAccessResult($selected, $changed, $skipped, $failed, $skipReasons);
    }

    protected function assertResellerCompany(Company $company): void
    {
        if (! $this->isResellerCompany($company)) {
            throw ValidationException::withMessages([
                'company' => 'Market access can only be managed for reseller companies.',
            ]);
        }
    }

    protected function isResellerCompany(Company $company): bool
    {
        $company->loadMissing('portal');

        return $company->portal?->code === PortalCode::RESELLER->value;
    }

    protected function resolveGrantableMarket(Market|int $market): Market
    {
        $marketModel = $market instanceof Market
            ? $market
            : Market::query()->find($market);

        if (! $marketModel || ! $marketModel->is_active) {
            throw ValidationException::withMessages([
                'allowed_market_ids' => 'The selected market is invalid or inactive.',
            ]);
        }

        return $marketModel;
    }
}
