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
        ];

        $importedCod = '0.00';
        $importedCharge = '0.00';
        $codPresent = false;
        $chargePresent = false;

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
            };

            if ($row['collected_cod'] !== null) {
                $codPresent = true;
                $importedCod = $this->money->add($importedCod, (string) $row['collected_cod']);
            }
            if ($row['delivery_charge'] !== null) {
                $chargePresent = true;
                $importedCharge = $this->money->add($importedCharge, (string) $row['delivery_charge']);
            }
        }

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
            $notes[] = $counts['skipped_count']
                .' row(s) skipped because Status is not DELIVERED (delivery-status import only).';
        }

        if (! $orderCountMatches) {
            $notes[] = 'Entered order count ('.$enteredOrderCount.') does not match eligible file row count ('
                .$eligibleRowCount.')'
                .($counts['skipped_count'] > 0 ? ' after excluding skipped non-DELIVERED rows.' : '.');
            $blocked[] = 'order_count_mismatch';
        }

        if (! $netIncomeConsistent) {
            $notes[] = 'Entered net income is not equal to Total Collected COD minus Delivery Charge.';
            $blocked[] = 'net_income_inconsistent';
        }

        $codTotalsMatch = null;
        if ($fileHasCodAmounts || $codPresent) {
            $codTotalsMatch = $this->money->equals($enteredCod, $importedCod);
            if (! $codTotalsMatch) {
                $notes[] = 'Entered Total Collected COD ('.$enteredCod.') does not match imported COD total ('.$importedCod.').';
                $blocked[] = 'cod_totals_mismatch';
            }
        } else {
            $notes[] = 'Imported file does not supply per-row COD amounts; COD total cannot be reconciled from the file.';
        }

        $chargeTotalsMatch = null;
        if ($fileHasDeliveryCharges || $chargePresent) {
            $chargeTotalsMatch = $this->money->equals($enteredCharge, $importedCharge);
            if (! $chargeTotalsMatch) {
                $notes[] = 'Entered Delivery Charge ('.$enteredCharge.') does not match imported delivery charge total ('.$importedCharge.').';
                $blocked[] = 'delivery_charge_totals_mismatch';
            }
        } else {
            $notes[] = 'Imported file does not supply per-row delivery charges; delivery charge total cannot be reconciled from the file.';
        }

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
            'imported_cod_total' => ($fileHasCodAmounts || $codPresent) ? $importedCod : null,
            'imported_delivery_charge_total' => ($fileHasDeliveryCharges || $chargePresent) ? $importedCharge : null,
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
                    'Only MATCHED DISPATCHED rows may transition to DELIVERED on confirmation.',
                    'Preview never records courier payment receipt or settlements.',
                ],
            ],
        ];
    }
}
