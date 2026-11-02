<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoiceLine;
use Illuminate\Support\Collection;

/**
 * Collects persisted earning obligations eligible for a reseller settlement batch.
 *
 * Authoritative source: reseller_earning_obligations for the batch settlement_date.
 * Excludes obligations already allocated to any invoice line (including other batches).
 * When rebuilding an OPEN batch, lines belonging to that batch are treated as free.
 *
 * Negative gross profit obligations are returned separately for review flagging —
 * they never become payable invoice lines.
 */
class ResellerSettlementEligibilityCollector
{
    /**
     * @return array{
     *     payable_candidates: list<array<string, mixed>>,
     *     review_candidates: list<array<string, mixed>>,
     *     skipped_already_allocated_count: int,
     *     skipped_currency_mismatch_count: int
     * }
     */
    public function collect(ResellerSettlementBatch $batch, Market $market, bool $includeCurrentBatchLines = false): array
    {
        $settlementDate = $batch->settlement_date?->format('Y-m-d')
            ?? (string) $batch->getRawOriginal('settlement_date');

        return $this->assemble($batch, $market, $settlementDate, $includeCurrentBatchLines);
    }

    /**
     * Eligible earning obligations for a market that are not already on an invoice line.
     * Business date is not selected by an admin. Negative gross stays in review_candidates.
     *
     * @return array{
     *     payable_candidates: list<array<string, mixed>>,
     *     review_candidates: list<array<string, mixed>>,
     *     skipped_already_allocated_count: int,
     *     skipped_currency_mismatch_count: int
     * }
     */
    public function collectOutstanding(Market $market): array
    {
        return $this->assemble(new ResellerSettlementBatch([
            'market_id' => $market->id,
        ]), $market, null, false);
    }

    /**
     * @return array{
     *     payable_candidates: list<array<string, mixed>>,
     *     review_candidates: list<array<string, mixed>>,
     *     skipped_already_allocated_count: int,
     *     skipped_currency_mismatch_count: int
     * }
     */
    private function assemble(
        ResellerSettlementBatch $batch,
        Market $market,
        ?string $settlementDate,
        bool $includeCurrentBatchLines,
    ): array {
        $market->loadMissing('currency');
        $marketCurrencyId = (int) $market->currency_id;
        // Production currencies use iso_code; isolated settlement tests may use code.
        $marketCurrencyCode = strtoupper((string) (
            $market->currency?->iso_code
            ?? $market->currency?->code
            ?? ''
        ));

        $obligationQuery = ResellerEarningObligation::query()
            ->with(['order', 'reseller', 'resellerCompany'])
            ->where('market_id', $market->id)
            ->orderBy('business_date')
            ->orderBy('reseller_id')
            ->orderBy('currency_id')
            ->orderBy('id');

        if ($settlementDate !== null && $settlementDate !== '') {
            $obligationQuery->whereDate('business_date', $settlementDate);
        }

        $obligations = $obligationQuery->get();

        $allocatedObligationIds = $this->allocatedObligationIds($batch, $includeCurrentBatchLines);

        $payable = [];
        $review = [];
        $skippedAllocated = 0;
        $skippedCurrencyMismatch = 0;

        foreach ($obligations as $obligation) {
            /** @var ResellerEarningObligation $obligation */
            if (isset($allocatedObligationIds[(int) $obligation->id])) {
                $skippedAllocated++;

                continue;
            }

            // Market batches are single-currency: never mix LKR/MYR (or any other pair).
            $obligationCurrencyCode = strtoupper((string) $obligation->currency_code_snapshot);
            if (
                $marketCurrencyId > 0
                && (
                    (int) $obligation->currency_id !== $marketCurrencyId
                    || ($marketCurrencyCode !== '' && $obligationCurrencyCode !== $marketCurrencyCode)
                )
            ) {
                $skippedCurrencyMismatch++;

                continue;
            }

            $gross = number_format((float) $obligation->gross_reseller_profit, 2, '.', '');
            $businessDate = $obligation->business_date?->format('Y-m-d')
                ?? (string) $obligation->getRawOriginal('business_date');
            $candidate = $this->mapCandidate($obligation, $settlementDate ?: $businessDate, $gross);

            if ((float) $gross < 0) {
                $review[] = $candidate;

                continue;
            }

            // Zero and positive gross are eligible for settlement grouping.
            $payable[] = $candidate;
        }

        return [
            'payable_candidates' => $payable,
            'review_candidates' => $review,
            'skipped_already_allocated_count' => $skippedAllocated,
            'skipped_currency_mismatch_count' => $skippedCurrencyMismatch,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function allocatedObligationIds(ResellerSettlementBatch $batch, bool $includeCurrentBatchLines): array
    {
        $query = ResellerSettlementInvoiceLine::query()->select('earning_obligation_id');

        if ($includeCurrentBatchLines && $batch->id) {
            // Preview/rebuild: exclude this OPEN batch's current lines from "already allocated".
            $query->whereDoesntHave('invoice', function ($invoiceQuery) use ($batch): void {
                $invoiceQuery->where('batch_id', $batch->id);
            });
        }

        /** @var Collection<int, int|string> $ids */
        $ids = $query->pluck('earning_obligation_id');

        $map = [];
        foreach ($ids as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCandidate(ResellerEarningObligation $obligation, string $settlementDate, string $gross): array
    {
        /** @var Order|null $order */
        $order = $obligation->order;
        $reseller = $obligation->reseller;

        $name = trim((string) (
            $reseller?->name
            ?? $obligation->resellerCompany?->name
            ?? ('Reseller #'.$obligation->reseller_id)
        ));

        return [
            'earning_obligation_id' => (int) $obligation->id,
            'obligation_number' => (string) $obligation->obligation_number,
            'reseller_id' => (int) $obligation->reseller_id,
            'reseller_company_id' => $obligation->reseller_company_id !== null
                ? (int) $obligation->reseller_company_id
                : null,
            'reseller_name' => $name !== '' ? $name : ('Reseller #'.$obligation->reseller_id),
            'order_id' => (int) $obligation->order_id,
            'order_number' => (string) ($order?->order_number ?? ('#'.$obligation->order_id)),
            'market_id' => (int) $obligation->market_id,
            'currency_id' => (int) $obligation->currency_id,
            'currency_code' => strtoupper((string) $obligation->currency_code_snapshot),
            'business_date' => $obligation->business_date?->format('Y-m-d')
                ?? (string) $obligation->getRawOriginal('business_date'),
            'settlement_date' => $settlementDate,
            'cod_amount_snapshot' => number_format((float) $obligation->cod_amount_snapshot, 2, '.', ''),
            'service_charge_snapshot' => number_format((float) $obligation->service_charge_snapshot, 2, '.', ''),
            'courier_charge_snapshot' => number_format((float) $obligation->courier_charge_snapshot, 2, '.', ''),
            'company_commission_snapshot' => number_format((float) $obligation->company_commission_snapshot, 2, '.', ''),
            'gross_reseller_profit' => $gross,
        ];
    }
}
