<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Models\CourierMarketPricing;

class CourierFeeCalculator
{
    /**
     * Configurable weight-based fee formula using market pricing rows.
     * Does not hard-code business fee amounts.
     */
    public function calculate(CourierMarketPricing $pricing, float $weightKg): float
    {
        $weightKg = max(0.0, $weightKg);
        $firstKgFee = (float) $pricing->first_kg_fee;
        $additionalKgFee = (float) $pricing->additional_kg_fee;

        if ($weightKg <= 0) {
            return round($firstKgFee, 2);
        }

        $additionalUnits = (int) ceil(max(0.0, $weightKg - 1.0));

        return round($firstKgFee + ($additionalUnits * $additionalKgFee), 2);
    }
}
