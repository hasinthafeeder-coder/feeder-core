<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\CourierReceiptImport;
use Feeder\Core\Models\CourierReceiptImportRow;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Collection;

/**
 * Plus Report eligibility from authoritative DELIVERED + confirmed remittance evidence.
 *
 * Dispatch date, shipment creation, courier booking, or a non-confirmed remittance
 * row is never treated as successful delivery.
 *
 * Evidence selection (deterministic):
 * 1. Preferred row from the active recording call, when still valid.
 * 2. Remittance row referenced by a persisted earning obligation, when still valid.
 *    Invalid obligation-linked evidence is surfaced — never silently replaced.
 * 3. Otherwise the single valid confirmed MATCHED row, or among multiple valid rows
 *    that share the same market business date, the lowest import-row id.
 * 4. Multiple valid rows that would yield different business dates → ambiguous.
 */
class ResellerEarningEligibilityEvaluator
{
    public function __construct(
        private readonly ResellerSettlementFinancialCalculator $financialCalculator,
        private readonly MarketBusinessDateResolver $businessDates,
    ) {}

    /**
     * @return array{
     *     eligible: bool,
     *     calculable: bool,
     *     exclusion_reason: string|null,
     *     exclusion_code: string|null,
     *     business_date: string|null,
     *     currency_code: string|null,
     *     financial: array<string, mixed>|null,
     *     import: CourierReceiptImport|null,
     *     import_row: CourierReceiptImportRow|null,
     *     existing_obligation: ResellerEarningObligation|null,
     *     evidence_resolution: string|null
     * }
     */
    public function evaluate(Order $order, ?CourierReceiptImportRow $preferredRow = null): array
    {
        $order->loadMissing(['items', 'market', 'currency', 'shipment', 'paymentSubmissions']);

        $existing = ResellerEarningObligation::query()
            ->where('order_id', $order->id)
            ->first();

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status !== OrderStatus::DELIVERED) {
            return $this->excluded(
                'ORDER_NOT_DELIVERED',
                'Order status is '.($status?->value ?? 'unknown').'; only DELIVERED orders earn reseller profit.',
                $existing,
            );
        }

        if ($order->reseller_id === null) {
            return $this->excluded(
                'RESELLER_MISSING',
                'Order has no reseller; cannot attribute earnings.',
                $existing,
            );
        }

        if ($order->market_id === null) {
            return $this->excluded(
                'MARKET_MISSING',
                'Order has no market; cannot resolve settlement business date or currency boundary.',
                $existing,
            );
        }

        if ($order->currency_id === null && blank($order->currency_code_snapshot)) {
            return $this->excluded(
                'CURRENCY_MISSING',
                'Order has no currency; cannot attribute earnings.',
                $existing,
            );
        }

        $evidence = $this->resolveConfirmedDeliveryEvidence($order, $preferredRow, $existing);

        if ($evidence['resolution'] === 'missing') {
            return $this->excluded(
                'DELIVERY_CONFIRMATION_MISSING',
                'No confirmed Admin courier remittance MATCHED row links this DELIVERED order.',
                $existing,
                'missing',
            );
        }

        if ($evidence['resolution'] === 'ambiguous') {
            return $this->excluded(
                'AMBIGUOUS_DELIVERY_EVIDENCE',
                'Multiple confirmed remittance rows disagree on delivery business date. '
                .'Unresolved — evidence was not switched automatically.',
                $existing,
                'ambiguous',
            );
        }

        if ($evidence['resolution'] === 'obligation_evidence_invalid') {
            return $this->excluded(
                'OBLIGATION_EVIDENCE_INVALID',
                'The remittance evidence linked to the existing earning obligation no longer validates. '
                .'Unresolved — newer remittance rows were not substituted.',
                $existing,
                'obligation_evidence_invalid',
            );
        }

        if ($evidence['resolution'] === 'inconsistent') {
            return $this->excluded(
                'DELIVERY_EVIDENCE_INCONSISTENT',
                'Confirmed remittance evidence no longer matches the order shipment.',
                $existing,
                'inconsistent',
            );
        }

        /** @var CourierReceiptImportRow $row */
        $row = $evidence['row'];
        /** @var CourierReceiptImport $import */
        $import = $evidence['import'];

        $market = $order->market instanceof Market
            ? $order->market
            : Market::query()->find($order->market_id);

        if ($market === null) {
            return $this->excluded(
                'MARKET_MISSING',
                'Order market record is missing; cannot resolve settlement business date.',
                $existing,
                $evidence['resolution'],
            );
        }

        $businessDate = $this->businessDates->businessDateString($market, $import->confirmed_at);
        $currencyCode = strtoupper(trim((string) (
            $order->currency_code_snapshot
            ?: $order->currency?->iso_code
            ?: ''
        )));

        if ($currencyCode === '') {
            return $this->excluded(
                'CURRENCY_MISSING',
                'Order currency code cannot be resolved.',
                $existing,
                $evidence['resolution'],
            );
        }

        $financial = $this->financialCalculator->calculateGrossResellerProfitBeforeDebt($order);

        if (! $financial['calculable']) {
            $exclusionCode = (string) ($financial['exclusion_code']
                ?? ResellerSettlementFinancialCalculator::EXCLUSION_MISSING_FINANCIAL_SNAPSHOTS);
            $missing = implode(', ', $financial['missing']);

            return [
                'eligible' => false,
                'calculable' => false,
                'exclusion_reason' => $this->financialExclusionReason($exclusionCode, $missing),
                'exclusion_code' => $exclusionCode,
                'business_date' => $businessDate,
                'currency_code' => $currencyCode,
                'financial' => $financial,
                'import' => $import,
                'import_row' => $row,
                'existing_obligation' => $existing,
                'evidence_resolution' => $evidence['resolution'],
            ];
        }

        return [
            'eligible' => true,
            'calculable' => true,
            'exclusion_reason' => null,
            'exclusion_code' => null,
            'business_date' => $businessDate,
            'currency_code' => $currencyCode,
            'financial' => $financial,
            'import' => $import,
            'import_row' => $row,
            'existing_obligation' => $existing,
            'evidence_resolution' => $evidence['resolution'],
        ];
    }

    /**
     * Deterministic remittance evidence resolution for an order.
     *
     * @return array{
     *     resolution: 'resolved'|'missing'|'ambiguous'|'obligation_evidence_invalid'|'inconsistent',
     *     row: CourierReceiptImportRow|null,
     *     import: CourierReceiptImport|null,
     *     candidate_count: int
     * }
     */
    public function resolveConfirmedDeliveryEvidence(
        Order $order,
        ?CourierReceiptImportRow $preferredRow = null,
        ?ResellerEarningObligation $obligation = null,
    ): array {
        $confirmedMatched = $this->collectConfirmedMatchedEvidence($order);
        $consistentCandidates = $this->filterConsistentEvidence($order, $confirmedMatched);

        if ($preferredRow !== null) {
            $preferredRow->loadMissing('import');
            $import = $preferredRow->import;
            if ($this->isConfirmedMatchedEvidence($preferredRow, $import, (int) $order->id)) {
                $shipment = $order->relationLoaded('shipment')
                    ? $order->shipment
                    : $order->shipment()->first();

                if (
                    $shipment instanceof Shipment
                    && $this->receiptLinksAreConsistent(
                        $preferredRow,
                        $order,
                        $shipment,
                        (int) $import->courier_service_id,
                    )
                ) {
                    return [
                        'resolution' => 'resolved',
                        'row' => $preferredRow,
                        'import' => $import,
                        'candidate_count' => $consistentCandidates->count(),
                    ];
                }
            }
        }

        if ($obligation !== null && $obligation->courier_receipt_import_row_id !== null) {
            $linked = $consistentCandidates->first(
                static fn (array $item): bool => (int) $item['row']->id === (int) $obligation->courier_receipt_import_row_id
            );

            if ($linked !== null) {
                return [
                    'resolution' => 'resolved',
                    'row' => $linked['row'],
                    'import' => $linked['import'],
                    'candidate_count' => $consistentCandidates->count(),
                ];
            }

            return [
                'resolution' => 'obligation_evidence_invalid',
                'row' => null,
                'import' => null,
                'candidate_count' => $confirmedMatched->count(),
            ];
        }

        if ($confirmedMatched->isEmpty()) {
            return [
                'resolution' => 'missing',
                'row' => null,
                'import' => null,
                'candidate_count' => 0,
            ];
        }

        if ($consistentCandidates->isEmpty()) {
            return [
                'resolution' => 'inconsistent',
                'row' => null,
                'import' => null,
                'candidate_count' => $confirmedMatched->count(),
            ];
        }

        if ($consistentCandidates->count() === 1) {
            $only = $consistentCandidates->first();

            return [
                'resolution' => 'resolved',
                'row' => $only['row'],
                'import' => $only['import'],
                'candidate_count' => 1,
            ];
        }

        $market = $order->market instanceof Market
            ? $order->market
            : Market::query()->find($order->market_id);

        if ($market === null) {
            return [
                'resolution' => 'ambiguous',
                'row' => null,
                'import' => null,
                'candidate_count' => $consistentCandidates->count(),
            ];
        }

        $byBusinessDate = [];
        foreach ($consistentCandidates as $item) {
            $businessDate = $this->businessDates->businessDateString($market, $item['import']->confirmed_at);
            $byBusinessDate[$businessDate][] = $item;
        }

        if (count($byBusinessDate) > 1) {
            return [
                'resolution' => 'ambiguous',
                'row' => null,
                'import' => null,
                'candidate_count' => $consistentCandidates->count(),
            ];
        }

        $chosen = $consistentCandidates
            ->sortBy(static fn (array $item): int => (int) $item['row']->id)
            ->first();

        return [
            'resolution' => 'resolved',
            'row' => $chosen['row'],
            'import' => $chosen['import'],
            'candidate_count' => $consistentCandidates->count(),
        ];
    }

    /**
     * Whether a persisted obligation's linked remittance evidence still validates.
     */
    public function obligationRemittanceEvidenceIsValid(
        ResellerEarningObligation $obligation,
        Order $order,
    ): bool {
        if ($obligation->courier_receipt_import_row_id === null) {
            return false;
        }

        $order->loadMissing(['shipment', 'market']);
        $evidence = $this->resolveConfirmedDeliveryEvidence($order, null, $obligation);

        if ($evidence['resolution'] !== 'resolved' || $evidence['row'] === null || $evidence['import'] === null) {
            return false;
        }

        $shipment = $order->shipment;
        if ($shipment === null) {
            return false;
        }

        return $this->receiptLinksAreConsistent(
            $evidence['row'],
            $order,
            $shipment,
            (int) $evidence['import']->courier_service_id,
        );
    }

    /**
     * @return Collection<int, array{row: CourierReceiptImportRow, import: CourierReceiptImport}>
     */
    private function collectConfirmedMatchedEvidence(Order $order): Collection
    {
        $rows = CourierReceiptImportRow::query()
            ->with('import')
            ->where('matched_order_id', $order->id)
            ->where('match_result', CourierReceiptImportRowMatchResult::MATCHED->value)
            ->whereHas('import', static function ($query): void {
                $query->where('status', CourierReceiptImportStatus::CONFIRMED->value)
                    ->whereNotNull('confirmed_at');
            })
            ->orderBy('id')
            ->get();

        $matched = collect();

        foreach ($rows as $row) {
            $import = $row->import;
            if ($this->isConfirmedMatchedEvidence($row, $import, (int) $order->id)) {
                $matched->push(['row' => $row, 'import' => $import]);
            }
        }

        return $matched;
    }

    /**
     * @param  Collection<int, array{row: CourierReceiptImportRow, import: CourierReceiptImport}>  $candidates
     * @return Collection<int, array{row: CourierReceiptImportRow, import: CourierReceiptImport}>
     */
    private function filterConsistentEvidence(Order $order, Collection $candidates): Collection
    {
        $order->loadMissing('shipment');
        $shipment = $order->shipment;

        if (! $shipment instanceof Shipment) {
            return collect();
        }

        return $candidates
            ->filter(function (array $item) use ($order, $shipment): bool {
                return $this->receiptLinksAreConsistent(
                    $item['row'],
                    $order,
                    $shipment,
                    (int) $item['import']->courier_service_id,
                );
            })
            ->values();
    }

    private function isConfirmedMatchedEvidence(
        CourierReceiptImportRow $row,
        ?CourierReceiptImport $import,
        int $orderId,
    ): bool {
        if ($import === null || $import->confirmed_at === null) {
            return false;
        }

        $status = $import->status instanceof CourierReceiptImportStatus
            ? $import->status
            : CourierReceiptImportStatus::tryFrom((string) $import->status);

        if ($status !== CourierReceiptImportStatus::CONFIRMED) {
            return false;
        }

        $match = $row->match_result instanceof CourierReceiptImportRowMatchResult
            ? $row->match_result
            : CourierReceiptImportRowMatchResult::tryFrom((string) $row->match_result);

        return $match === CourierReceiptImportRowMatchResult::MATCHED
            && (int) $row->matched_order_id === $orderId;
    }

    private function receiptLinksAreConsistent(
        CourierReceiptImportRow $row,
        Order $order,
        Shipment $shipment,
        int $importCourierServiceId,
    ): bool {
        if ((int) $row->matched_order_id !== (int) $order->id) {
            return false;
        }

        if ((int) $row->matched_shipment_id !== (int) $shipment->id) {
            return false;
        }

        if ((int) $shipment->order_id !== (int) $order->id) {
            return false;
        }

        if ((int) $shipment->courier_service_id !== $importCourierServiceId) {
            return false;
        }

        $tracking = trim((string) ($row->normalized_tracking_number ?? ''));

        return $tracking !== '' && trim((string) $shipment->tracking_number) === $tracking;
    }

    private function financialExclusionReason(string $code, string $missing): string
    {
        return match ($code) {
            ResellerSettlementFinancialCalculator::EXCLUSION_AMBIGUOUS_APPROVED_PAYMENT =>
                'Multiple APPROVED BANK_TRANSFER submissions exist for this order. '
                .'Earnings were not calculated from an ambiguous payment amount.',
            ResellerSettlementFinancialCalculator::EXCLUSION_NO_APPROVED_BANK_TRANSFER =>
                'Bank-transfer order has no APPROVED BANK_TRANSFER submission. '
                .'Customer payable was not used as a fallback.',
            ResellerSettlementFinancialCalculator::EXCLUSION_INVALID_APPROVED_PAYMENT_AMOUNT =>
                'The single APPROVED BANK_TRANSFER amount is missing, null, or invalid. '
                .'Customer payable was not used as a fallback.',
            ResellerSettlementFinancialCalculator::EXCLUSION_INVALID_SUPPLIER_COST_SNAPSHOT =>
                'One or more order items have a missing or invalid unit_cost_snapshot. '
                .'Historical supplier cost is required; live product cost was not used.',
            default => 'Missing or invalid financial snapshot(s): '.$missing
                .'. Non-calculable orders are excluded from payable earnings; '
                .'values are not invented from current Admin settings.',
        };
    }

    /**
     * @return array{
     *     eligible: bool,
     *     calculable: bool,
     *     exclusion_reason: string,
     *     exclusion_code: string,
     *     business_date: null,
     *     currency_code: null,
     *     financial: null,
     *     import: null,
     *     import_row: null,
     *     existing_obligation: ResellerEarningObligation|null,
     *     evidence_resolution: string|null
     * }
     */
    private function excluded(
        string $code,
        string $reason,
        ?ResellerEarningObligation $existing,
        ?string $evidenceResolution = null,
    ): array {
        return [
            'eligible' => false,
            'calculable' => false,
            'exclusion_reason' => $reason,
            'exclusion_code' => $code,
            'business_date' => null,
            'currency_code' => null,
            'financial' => null,
            'import' => null,
            'import_row' => null,
            'existing_obligation' => $existing,
            'evidence_resolution' => $evidenceResolution,
        ];
    }
}
