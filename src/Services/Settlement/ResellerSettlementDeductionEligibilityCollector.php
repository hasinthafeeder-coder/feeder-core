<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\ResellerDebtObligation;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementDeductionLine;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Collects persisted reseller debt obligations eligible for a Minus Report batch.
 *
 * Authoritative source: reseller_debt_obligations with a remaining outstanding
 * balance. Does not create fines and does not reduce outstanding_amount.
 * A debt already claimed by a deduction line is not eligible again.
 */
class ResellerSettlementDeductionEligibilityCollector
{
    public function __construct(
        private readonly MarketBusinessDateResolver $businessDates,
    ) {}

    /**
     * @return array{
     *     candidates: list<array<string, mixed>>,
     *     skipped_already_reported_count: int,
     *     skipped_currency_mismatch_count: int
     * }
     */
    public function collectOutstanding(Market $market, ?string $asOfDate = null): array
    {
        if (! Schema::hasTable('reseller_debt_obligations')) {
            return [
                'candidates' => [],
                'skipped_already_reported_count' => 0,
                'skipped_currency_mismatch_count' => 0,
            ];
        }

        $asOf = $asOfDate ?: $this->businessDates->businessDateString($market);

        return $this->assemble($market, null, $asOf);
    }

    /**
     * @return array{
     *     candidates: list<array<string, mixed>>,
     *     skipped_already_reported_count: int,
     *     skipped_currency_mismatch_count: int
     * }
     */
    public function collectForBatch(ResellerSettlementBatch $batch, Market $market): array
    {
        $settlementDate = $batch->settlement_date?->format('Y-m-d')
            ?? (string) $batch->getRawOriginal('settlement_date');

        return $this->assemble($market, $settlementDate, $settlementDate);
    }

    /**
     * @return array{
     *     candidates: list<array<string, mixed>>,
     *     skipped_already_reported_count: int,
     *     skipped_currency_mismatch_count: int
     * }
     */
    private function assemble(Market $market, ?string $effectiveDate, string $asOfDate): array
    {
        $market->loadMissing('currency');
        $marketCurrencyId = (int) $market->currency_id;
        $marketCurrencyCode = strtoupper((string) (
            $market->currency?->iso_code
            ?? $market->currency?->code
            ?? ''
        ));

        $claimedIds = $this->claimedDebtIds();

        $query = ResellerDebtObligation::query()
            ->with(['order', 'reseller.profile'])
            ->where('market_id', $market->id)
            ->where('outstanding_amount', '>', 0)
            ->whereDate('effective_date', '<=', $asOfDate)
            ->orderBy('effective_date')
            ->orderBy('id');

        if ($effectiveDate !== null && $effectiveDate !== '') {
            $query->whereDate('effective_date', $effectiveDate);
        }

        $candidates = [];
        $skippedReported = 0;
        $skippedCurrency = 0;

        foreach ($query->get() as $debt) {
            /** @var ResellerDebtObligation $debt */
            if (isset($claimedIds[(int) $debt->id])) {
                $skippedReported++;

                continue;
            }

            $currencyCode = strtoupper((string) $debt->currency_code_snapshot);
            if (
                $marketCurrencyId > 0
                && (
                    (int) $debt->currency_id !== $marketCurrencyId
                    || ($marketCurrencyCode !== '' && $currencyCode !== $marketCurrencyCode)
                )
            ) {
                $skippedCurrency++;

                continue;
            }

            $candidates[] = $this->mapCandidate($debt);
        }

        return [
            'candidates' => $candidates,
            'skipped_already_reported_count' => $skippedReported,
            'skipped_currency_mismatch_count' => $skippedCurrency,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function claimedDebtIds(): array
    {
        if (! Schema::hasTable('reseller_settlement_deduction_lines')) {
            return [];
        }

        $map = [];
        foreach (ResellerSettlementDeductionLine::query()->pluck('debt_obligation_id') as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCandidate(ResellerDebtObligation $debt): array
    {
        $reseller = $debt->reseller;
        $name = trim((string) ($reseller?->name ?? ''));
        if ($name === '' && $reseller?->profile !== null) {
            $name = trim((string) (($reseller->profile->first_name ?? '').' '.($reseller->profile->last_name ?? '')));
        }

        $effectiveDate = $debt->effective_date?->format('Y-m-d')
            ?? (string) $debt->getRawOriginal('effective_date');

        return [
            'debt_obligation_id' => (int) $debt->id,
            'obligation_number' => (string) $debt->obligation_number,
            'reseller_id' => (int) $debt->reseller_id,
            'reseller_name' => $name !== '' ? $name : ('Reseller #'.$debt->reseller_id),
            'order_id' => $debt->order_id !== null ? (int) $debt->order_id : null,
            'order_number' => (string) ($debt->order?->order_number ?? ($debt->order_id ? '#'.$debt->order_id : '')),
            'currency_id' => (int) $debt->currency_id,
            'currency_code' => strtoupper((string) $debt->currency_code_snapshot),
            'effective_date' => $effectiveDate,
            'obligation_type' => (string) ($debt->obligation_type?->value ?? $debt->getRawOriginal('obligation_type')),
            'return_reason' => $debt->return_reason !== null
                ? (string) ($debt->return_reason->value ?? $debt->getRawOriginal('return_reason'))
                : null,
            'fine_condition' => $debt->fine_condition !== null
                ? (string) ($debt->fine_condition->value ?? $debt->getRawOriginal('fine_condition'))
                : null,
            'original_amount' => number_format((float) $debt->original_amount, 2, '.', ''),
            'outstanding_amount' => number_format((float) $debt->outstanding_amount, 2, '.', ''),
        ];
    }
}
