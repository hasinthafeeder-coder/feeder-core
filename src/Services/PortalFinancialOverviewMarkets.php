<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Active markets for the supplier and reseller financial overview cards.
 *
 * Activation is the existing markets.is_active flag, read through
 * MarketService::listActiveMarkets(). Sri Lanka is market code lk.
 * Malaysia is market code my. Other markets are not given a card section.
 *
 * Card visibility is further limited to markets the authenticated party is
 * registered to operate in:
 * - supplier: company.operation_market_id
 * - reseller: company.allowedMarkets (reseller_market_access)
 *
 * Eligibility is never inferred from settlement or invoice rows alone.
 */
class PortalFinancialOverviewMarkets
{
    public function __construct(private readonly MarketService $markets) {}

    /**
     * Active Sri Lanka / Malaysia markets configured on the platform.
     *
     * @return Collection<int, Market>
     */
    public function active(): Collection
    {
        $byCode = [];

        foreach ($this->markets->listActiveMarkets()->loadMissing('currency') as $market) {
            $code = strtolower(trim((string) $market->code));

            if ($code === 'lk' || $code === 'my') {
                $byCode[$code] = $market;
            }
        }

        return $this->ordered($byCode);
    }

    /**
     * Active Sri Lanka / Malaysia markets the actor is registered to operate in.
     *
     * @return Collection<int, Market>
     */
    public function forActor(User $actor): Collection
    {
        $active = $this->active();

        if ($active->isEmpty()) {
            return $active;
        }

        $actor->loadMissing([
            'company.portal',
            'company.operationMarket.currency',
            'company.allowedMarkets.currency',
        ]);

        $company = $actor->company;

        if ($company === null || $company->portal === null) {
            return new Collection();
        }

        $eligibleIds = match ($company->portal->code) {
            PortalCode::SUPPLIER->value => $this->supplierEligibleMarketIds($company->operation_market_id),
            PortalCode::RESELLER->value => $company->allowedMarkets
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
            default => [],
        };

        if ($eligibleIds === []) {
            return new Collection();
        }

        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $byCode = [];

        foreach ($active as $market) {
            if (isset($eligibleLookup[(int) $market->id])) {
                $byCode[strtolower(trim((string) $market->code))] = $market;
            }
        }

        return $this->ordered($byCode);
    }

    /**
     * @return array{overviewMarkets: Collection<int, Market>, overviewMarketsUnavailable: bool}
     */
    public function viewData(User $actor): array
    {
        try {
            return [
                'overviewMarkets' => $this->forActor($actor),
                'overviewMarketsUnavailable' => false,
            ];
        } catch (QueryException $exception) {
            report($exception);

            return [
                'overviewMarkets' => new Collection(),
                'overviewMarketsUnavailable' => true,
            ];
        }
    }

    /**
     * @return list<int>
     */
    private function supplierEligibleMarketIds(mixed $operationMarketId): array
    {
        if ($operationMarketId === null || $operationMarketId === '') {
            return [];
        }

        return [(int) $operationMarketId];
    }

    /**
     * @param  array<string, Market>  $byCode
     * @return Collection<int, Market>
     */
    private function ordered(array $byCode): Collection
    {
        $ordered = new Collection();

        foreach (['lk', 'my'] as $code) {
            if (isset($byCode[$code])) {
                $ordered->push($byCode[$code]);
            }
        }

        return $ordered;
    }
}
