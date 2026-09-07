<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Models\CribRecord;
use Illuminate\Support\Collection;

class CribLookupService
{
    public function findByPhone(int $marketId, string $normalizedPhone, bool $activeOnly = true): ?CribRecord
    {
        $query = CribRecord::query()
            ->where('market_id', $marketId)
            ->where('normalized_phone', $normalizedPhone);

        if ($activeOnly) {
            $query->active();
        }

        return $query->first();
    }

    /**
     * Batch lookup for Meta imports / multi-phone checks.
     *
     * @param  list<string>  $normalizedPhones
     * @return Collection<string, CribRecord>
     */
    public function findByPhones(int $marketId, array $normalizedPhones, bool $activeOnly = true): Collection
    {
        $phones = array_values(array_unique(array_filter($normalizedPhones)));

        if ($phones === []) {
            return collect();
        }

        $query = CribRecord::query()
            ->where('market_id', $marketId)
            ->forPhones($phones);

        if ($activeOnly) {
            $query->active();
        }

        return $query->get()->keyBy('normalized_phone');
    }
}
