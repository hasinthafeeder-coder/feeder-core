<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Country;
use Feeder\Core\Models\Market;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class MarketService
{
    /**
     * Active countries that have at least one active market (for supplier operation country selection).
     *
     * @return Collection<int, Country>
     */
    public function listOperationCountries(): Collection
    {
        return Country::query()
            ->where('is_active', true)
            ->whereHas('markets', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Country>
     */
    public function listActiveCountries(): Collection
    {
        return Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Market>
     */
    public function listActiveMarkets(): Collection
    {
        return Market::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Market>
     */
    public function listMarketsForFinancialConfiguration(): Collection
    {
        return Market::query()
            ->with(['country', 'currency'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function findActiveCountryByUuid(string $uuid): ?Country
    {
        return Country::query()
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->first();
    }

    public function findActiveMarketByUuid(string $uuid): ?Market
    {
        return Market::query()
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  list<string>  $marketUuids
     * @return Collection<int, Market>
     */
    public function resolveActiveMarketsByUuids(array $marketUuids): Collection
    {
        $uuids = array_values(array_unique(array_filter($marketUuids)));

        if ($uuids === []) {
            return new Collection();
        }

        $markets = Market::query()
            ->whereIn('uuid', $uuids)
            ->where('is_active', true)
            ->get();

        if ($markets->count() !== count($uuids)) {
            throw ValidationException::withMessages([
                'allowed_market_ids' => 'One or more selected markets are invalid or inactive.',
            ]);
        }

        return $markets;
    }

    public function resolveActiveMarketForCountry(Country|int|string $country): Market
    {
        if (is_string($country)) {
            $countryModel = $this->findActiveCountryByUuid($country);

            if (! $countryModel) {
                throw ValidationException::withMessages([
                    'operation_country_id' => 'The selected operation country is invalid or inactive.',
                ]);
            }
        } elseif (is_int($country)) {
            $countryModel = Country::query()
                ->whereKey($country)
                ->where('is_active', true)
                ->first();

            if (! $countryModel) {
                throw ValidationException::withMessages([
                    'operation_country_id' => 'The selected operation country is invalid or inactive.',
                ]);
            }
        } else {
            $countryModel = $country;
        }

        $markets = Market::query()
            ->where('country_id', $countryModel->id)
            ->where('is_active', true)
            ->get();

        if ($markets->isEmpty()) {
            throw ValidationException::withMessages([
                'operation_country_id' => 'No active market is available for the selected country.',
            ]);
        }

        if ($markets->count() > 1) {
            throw ValidationException::withMessages([
                'operation_country_id' => 'Multiple active markets were found for the selected country.',
            ]);
        }

        return $markets->first();
    }
}
