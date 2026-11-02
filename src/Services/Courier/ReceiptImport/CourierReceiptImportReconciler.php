<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Support\MoneyNormalizer;

/**
 * Compares admin-entered invoice summary values against imported file rows.
 *
 * Confirmation eligibility rules are documented for a later stage and are not
 * applied as mutations here.
 */
class CourierReceiptImportReconciler
{
    public function __construct(
        private readonly MoneyNormalizer $money,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $matchedRows
     * @return array{
     *     matched_count: int,
     *     unmatched_count: int,
     *     ambiguous_count: int,
     *     duplicate_count: int,
     *     previously_processed_count: int,
     *     service_mismatch_count: int,
     *     invalid_count: int,
     *     skipped_count: int,
     *     already_applied_count: int,
     *     file_row_count: int,
     *     imported_cod_total: string|null,
     *     imported_delivery_charge_total: string|null,
     *     order_count_matches: bool,
     *     cod_totals_match: bool|null,
     *     delivery_charge_totals_match: bool|null,
     *     net_income_consistent: bool,
     *     totals_reconcile: bool,
     *     reconciliation_notes: list<string>,
     *     confirmation_blocked_reasons: list<string>
     * }
     */
    public function reconcile(
        array $matchedRows,
        int $enteredOrderCount,
        string $enteredCod,
        string $enteredCharge,
        string $enteredNetIncome,
        bool $fileHasCodAmounts,
        bool $fileHasDeliveryCharges,
    ): array {
        $counts = [
            'matched_count' => 0,
            'unmatched_count' => 0,
            'ambiguous_count' => 0,
            'duplicate_count' => 0,
            'previously_processed_count' => 0,
            'service_mismatch_count' => 0,
            'invalid_count' => 0,
            'skipped_count' => 0,
            'already_applied_count' => 0,
        ];

        foreach ($matchedRows as $row) {
            $result = $row['match_result'];
            $result = $result instanceof CourierReceiptImportRowMatchResult
                ? $result
                : CourierReceiptImportRowMatchResult::from((string) $result);

            match ($result) {
                CourierReceiptImportRowMatchResult::MATCHED => $counts['matched_count']++,
                CourierReceiptImportRowMatchResult::UNMATCHED => $counts['unmatched_count']++,
                CourierReceiptImportRowMatchResult::AMBIGUOUS => $counts['ambiguous_count']++,
                CourierReceiptImportRowMatchResult::DUPLICATE => $counts['duplicate_count']++,
                CourierReceiptImportRowMatchResult::PREVIOUSLY_PROCESSED => $counts['previously_processed_count']++,
                CourierReceiptImportRowMatchResult::SERVICE_MISMATCH => $counts['service_mismatch_count']++,
                CourierReceiptImportRowMatchResult::INVALID => $counts['invalid_count']++,
                CourierReceiptImportRowMatchResult::SKIPPED => $counts['skipped_count']++,
                CourierReceiptImportRowMatchResult::ALREADY_APPLIED => $counts['already_applied_count']++,
            };

        }

        $grossCoverage = $this->amountCoverage($matchedRows, 'gross_remittance_amount');
        $chargeCoverage = $this->amountCoverage($matchedRows, 'courier_charge_amount');

        $fileRowCount = count($matchedRows);
        $eligibleRowCount = $fileRowCount - $counts['skipped_count'];
        $orderCountMatches = $enteredOrderCount === $eligibleRowCount;
        $netIncomeConsistent = $this->money->equals(
            $enteredNetIncome,
            $this->money->subtract($enteredCod, $enteredCharge)
        );

        $notes = [];
        $blocked = [];

        if ($counts['skipped_count'] > 0) {
            $notes[] = $counts['skipped_count'].' row(s) were skipped and are excluded from the order count.';
        }

        if (! $orderCountMatches) {
            $notes[] = 'Entered order count ('.$enteredOrderCount.') does not match eligible file row count ('
                .$eligibleRowCount.')'
                .($counts['skipped_count'] > 0 ? ' after excluding skipped rows.' : '.');
            $blocked[] = 'order_count_mismatch';
        }

        if (! $netIncomeConsistent) {
            $notes[] = 'Entered net income is not equal to Total Collected COD minus Delivery Charge.';
            $blocked[] = 'net_income_inconsistent';
        }

        $codCheck = $this->compareAvailableTotal(
            $grossCoverage,
            $enteredCod,
            'Total Collected COD',
            'collected amount',
            $fileHasCodAmounts,
        );
        $chargeCheck = $this->compareAvailableTotal(
            $chargeCoverage,
            $enteredCharge,
            'Delivery Charge',
            'courier charge',
            $fileHasDeliveryCharges,
        );
        $codTotalsMatch = $codCheck['matches'];
        $chargeTotalsMatch = $chargeCheck['matches'];
        $importedCod = $codCheck['imported_total'];
        $importedCharge = $chargeCheck['imported_total'];
        array_push($notes, ...$codCheck['notes']);
        array_push($notes, ...$chargeCheck['notes']);
        array_push($blocked, ...$codCheck['blocked']);
        array_push($blocked, ...$chargeCheck['blocked']);

        if ($counts['unmatched_count'] > 0) {
            $blocked[] = 'unmatched_rows';
        }
        if ($counts['ambiguous_count'] > 0) {
            $blocked[] = 'ambiguous_rows';
        }
        if ($counts['duplicate_count'] > 0) {
            $blocked[] = 'duplicate_rows';
        }
        if ($counts['previously_processed_count'] > 0) {
            $blocked[] = 'previously_processed_rows';
        }
        if ($counts['service_mismatch_count'] > 0) {
            $blocked[] = 'service_mismatch_rows';
        }
        if ($counts['invalid_count'] > 0) {
            $blocked[] = 'invalid_rows';
        }
        if ($counts['already_applied_count'] > 0) {
            $notes[] = $counts['already_applied_count']
                .' row(s) already have remittance evidence. Previous evidence is not overwritten.';
            $blocked[] = 'already_applied_rows';
        }
        if ($counts['matched_count'] === 0) {
            $blocked[] = 'no_matched_rows';
        }

        $totalsReconcile = $orderCountMatches
            && $netIncomeConsistent
            && ($codTotalsMatch ?? true)
            && ($chargeTotalsMatch ?? true);

        if ($totalsReconcile) {
            $notes[] = 'Invoice totals reconcile for checks that the file can support.';
        }

        $notes[] = 'Preview does not change order statuses. Confirmation remains blocked while any confirmation_blocked_reasons remain.';

        return $counts + [
            'file_row_count' => $fileRowCount,
            'imported_cod_total' => $importedCod,
            'imported_delivery_charge_total' => $importedCharge,
            'order_count_matches' => $orderCountMatches,
            'cod_totals_match' => $codTotalsMatch,
            'delivery_charge_totals_match' => $chargeTotalsMatch,
            'net_income_consistent' => $netIncomeConsistent,
            'totals_reconcile' => $totalsReconcile,
            'reconciliation_notes' => [
                'notes' => $notes,
                'confirmation_blocked_reasons' => array_values(array_unique($blocked)),
                'confirmation_rules_next_stage' => [
                    'Confirm Processing must not run while confirmation_blocked_reasons is non-empty.',
                    'Only MATCHED DISPATCHED rows are confirmed, as delivered or returned from the courier status.',
                    'A blocked import must not change any order.',
                    'Preview never records courier payment receipt or settlements.',
                ],
            ],
        ];
    }

    /**
     * Sum a remittance column across delivered rows that are part of the order count.
     * Skipped and returned rows are excluded — returns are not received remittance.
     * Null cells are missing, not zero.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{sum: string|null, present: int, missing: int}
     */
    private function amountCoverage(array $rows, string $field): array
    {
        $sum = '0.00';
        $present = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $result = $row['match_result'];
            $result = $result instanceof CourierReceiptImportRowMatchResult
                ? $result
                : CourierReceiptImportRowMatchResult::from((string) $result);

            if ($result === CourierReceiptImportRowMatchResult::SKIPPED) {
                continue;
            }

            if (($row['report_outcome'] ?? null) === CourierReportOutcomeClassifier::RETURNED) {
                continue;
            }

            $amount = $row[$field] ?? null;
            if ($amount === null || $amount === '') {
                $missing++;

                continue;
            }

            $present++;
            $sum = $this->money->add($sum, (string) $amount);
        }

        return [
            'sum' => $present > 0 ? $sum : null,
            'present' => $present,
            'missing' => $missing,
        ];
    }

    /**
     * Compare an entered total only when every counted row actually has that amount.
     * A partial or absent column is reported and does not block confirmation.
     *
     * @param  array{sum: string|null, present: int, missing: int}  $coverage
     * @return array{matches: bool|null, imported_total: string|null, notes: list<string>, blocked: list<string>}
     */
    private function compareAvailableTotal(
        array $coverage,
        string $entered,
        string $label,
        string $noun,
        bool $fileClaimsAmounts,
    ): array {
        if ($coverage['present'] === 0 || $coverage['missing'] > 0) {
            $note = $coverage['present'] === 0
                ? 'Imported file does not supply a per-row '.$noun
                    .'; entered '.$label.' cannot be verified from the file.'
                : $coverage['missing'].' row(s) have no '.$noun
                    .'. Entered '.$label.' cannot be verified from the file and was not treated as reconciled.';

            if ($fileClaimsAmounts && $coverage['present'] === 0) {
                $note = 'The file was marked as containing '.$noun
                    .' values, but no per-row amount was available. Entered '.$label
                    .' cannot be verified from the file.';
            }

            return [
                'matches' => null,
                'imported_total' => null,
                'notes' => [$note],
                'blocked' => [],
            ];
        }

        $matches = $this->money->equals($entered, (string) $coverage['sum']);

        return [
            'matches' => $matches,
            'imported_total' => $coverage['sum'],
            'notes' => $matches
                ? []
                : ['Entered '.$label.' ('.$entered.') does not match the file total ('.$coverage['sum'].').'],
            'blocked' => $matches
                ? []
                : [$label === 'Total Collected COD' ? 'cod_totals_mismatch' : 'delivery_charge_totals_mismatch'],
        ];
    }
}
