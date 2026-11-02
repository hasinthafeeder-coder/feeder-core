<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

/**
 * Normalizes a courier report status cell.
 *
 * Delivered is only the exact token DELIVERED after case and whitespace
 * normalization. Any other non-empty status is a return outcome. An empty
 * status is ambiguous and is not treated as either outcome.
 *
 * This does not inspect amounts, waybills, or neighboring columns.
 */
class CourierReportOutcomeClassifier
{
    public const DELIVERED = 'DELIVERED';

    public const RETURNED = 'RETURNED';

    /**
     * @return array{
     *     outcome: 'DELIVERED'|'RETURNED'|null,
     *     normalized_status: string,
     *     ambiguous: bool,
     *     reason: string|null
     * }
     */
    public function classify(string $rawStatus): array
    {
        $normalized = strtoupper(trim($rawStatus));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        if ($normalized === '') {
            return [
                'outcome' => null,
                'normalized_status' => '',
                'ambiguous' => true,
                'reason' => 'Courier status is empty. The row was not treated as delivered or returned.',
            ];
        }

        if ($normalized === self::DELIVERED) {
            return [
                'outcome' => self::DELIVERED,
                'normalized_status' => $normalized,
                'ambiguous' => false,
                'reason' => null,
            ];
        }

        return [
            'outcome' => self::RETURNED,
            'normalized_status' => $normalized,
            'ambiguous' => false,
            'reason' => null,
        ];
    }
}
