<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Models\CourierReceiptImport;
use Feeder\Core\Support\MoneyNormalizer;

/**
 * Builds a file-derived invoice summary from an active parser's parse result.
 *
 * Does not re-read spreadsheets or invent financial totals. Collected COD is the
 * sum of collected COD / amount cells the parser kept. Delivery charge is the
 * sum of courier charge cells (delivery charge or delivery fee). Rows the parser
 * marks ineligible are excluded. Returned rows are counted as non-delivered and
 * are excluded from remittance money totals — a return produces no company COD
 * income and must not inflate received-remittance figures.
 */
class CourierReceiptInvoiceFileSummaryBuilder
{
    public const NOT_AVAILABLE_LABEL = 'Not available from this file format';

    public function __construct(
        private readonly MoneyNormalizer $money,
    ) {}

    /**
     * @param  array{
     *     file_type?: string,
     *     file_has_cod_amounts: bool,
     *     file_has_delivery_charges: bool,
     *     rows: list<array<string, mixed>>
     * }  $parsed
     * @return array{
     *     total_data_rows: int,
     *     delivered_eligible_rows: int,
     *     non_delivered_skipped_rows: int,
     *     invalid_tracking_rows: int,
     *     duplicate_tracking_rows: int,
     *     cod_total: string|null,
     *     delivery_charge_total: string|null,
     *     cod_total_available: bool,
     *     delivery_charge_total_available: bool,
     *     financials_available: bool
     * }
     */
    public function fromParsed(array $parsed): array
    {
        $rows = $parsed['rows'] ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $total = 0;
        $eligible = 0;
        $skipped = 0;
        $invalidTracking = 0;
        $duplicateTracking = 0;
        $seenTracking = [];
        $importedCod = '0.00';
        $importedCharge = '0.00';
        $codPresent = false;
        $chargePresent = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $total++;

            $eligibleForMatching = array_key_exists('eligible_for_matching', $row)
                ? (bool) $row['eligible_for_matching']
                : true;

            if (! $eligibleForMatching) {
                $skipped++;

                continue;
            }

            if (($row['report_outcome'] ?? null) === CourierReportOutcomeClassifier::RETURNED) {
                // Keep amount availability when the file lists return COD/charges, but do
                // not add those figures into received-remittance totals.
                $this->markAmountPresent($row, 'collected_cod', 'remittance_gross', $codPresent);
                $this->markAmountPresent($row, 'delivery_charge', 'remittance_charge', $chargePresent);
                $skipped++;

                continue;
            }

            $this->addAmount($row, 'collected_cod', 'remittance_gross', $importedCod, $codPresent);
            $this->addAmount($row, 'delivery_charge', 'remittance_charge', $importedCharge, $chargePresent);

            $eligible++;

            $tracking = trim((string) ($row['tracking_number'] ?? ''));
            if ($tracking === '') {
                $invalidTracking++;

                continue;
            }

            if (isset($seenTracking[$tracking])) {
                $duplicateTracking++;
            } else {
                $seenTracking[$tracking] = true;
            }
        }

        $codAvailable = (bool) ($parsed['file_has_cod_amounts'] ?? false) || $codPresent;
        $chargeAvailable = (bool) ($parsed['file_has_delivery_charges'] ?? false) || $chargePresent;

        return [
            'total_data_rows' => $total,
            'delivered_eligible_rows' => $eligible,
            'non_delivered_skipped_rows' => $skipped,
            'invalid_tracking_rows' => $invalidTracking,
            'duplicate_tracking_rows' => $duplicateTracking,
            'cod_total' => $codAvailable ? $importedCod : null,
            'delivery_charge_total' => $chargeAvailable ? $importedCharge : null,
            'cod_total_available' => $codAvailable,
            'delivery_charge_total_available' => $chargeAvailable,
            'financials_available' => $codAvailable || $chargeAvailable,
        ];
    }

    /**
     * Rebuild a file-derived summary from persisted import rows when parse output
     * is no longer available (e.g. reloading a PREVIEWED import).
     *
     * @return array{
     *     total_data_rows: int,
     *     delivered_eligible_rows: int,
     *     non_delivered_skipped_rows: int,
     *     invalid_tracking_rows: int,
     *     duplicate_tracking_rows: int,
     *     cod_total: string|null,
     *     delivery_charge_total: string|null,
     *     cod_total_available: bool,
     *     delivery_charge_total_available: bool,
     *     financials_available: bool
     * }
     */
    public function fromImport(CourierReceiptImport $import): array
    {
        $stored = $import->reconciliation_notes['file_summary'] ?? null;
        if (is_array($stored)
            && array_key_exists('total_data_rows', $stored)
            && array_key_exists('delivered_eligible_rows', $stored)
        ) {
            return $this->normalizeStoredSummary($stored, $import);
        }

        $import->loadMissing('rows');

        $total = 0;
        $eligible = 0;
        $skipped = 0;
        $invalidTracking = 0;
        $duplicateTracking = 0;
        $importedCod = '0.00';
        $importedCharge = '0.00';
        $codPresent = false;
        $chargePresent = false;

        foreach ($import->rows as $row) {
            $total++;

            $matchResult = $row->match_result instanceof CourierReceiptImportRowMatchResult
                ? $row->match_result
                : CourierReceiptImportRowMatchResult::tryFrom((string) $row->getRawOriginal('match_result'));

            if ($matchResult === CourierReceiptImportRowMatchResult::SKIPPED) {
                $skipped++;

                continue;
            }

            $provided = $row->remittance_provided_fields;
            $outcome = is_array($provided) ? ($provided['report_outcome'] ?? null) : null;
            if ($outcome === CourierReportOutcomeClassifier::RETURNED
                || ($matchResult === CourierReceiptImportRowMatchResult::INVALID
                    && $outcome !== CourierReportOutcomeClassifier::DELIVERED)
            ) {
                if ($outcome === CourierReportOutcomeClassifier::RETURNED) {
                    $amountRow = [
                        'collected_cod' => $row->collected_cod,
                        'remittance_gross' => $row->gross_remittance_amount,
                        'delivery_charge' => $row->delivery_charge,
                        'remittance_charge' => $row->courier_charge_amount,
                    ];
                    $this->markAmountPresent($amountRow, 'collected_cod', 'remittance_gross', $codPresent);
                    $this->markAmountPresent($amountRow, 'delivery_charge', 'remittance_charge', $chargePresent);
                }
                $skipped++;

                continue;
            }

            $this->addAmount(
                [
                    'collected_cod' => $row->collected_cod,
                    'remittance_gross' => $row->gross_remittance_amount,
                    'delivery_charge' => $row->delivery_charge,
                    'remittance_charge' => $row->courier_charge_amount,
                ],
                'collected_cod',
                'remittance_gross',
                $importedCod,
                $codPresent,
            );
            $this->addAmount(
                [
                    'collected_cod' => $row->collected_cod,
                    'remittance_gross' => $row->gross_remittance_amount,
                    'delivery_charge' => $row->delivery_charge,
                    'remittance_charge' => $row->courier_charge_amount,
                ],
                'delivery_charge',
                'remittance_charge',
                $importedCharge,
                $chargePresent,
            );

            $eligible++;

            $tracking = trim((string) ($row->normalized_tracking_number ?? $row->raw_tracking_number ?? ''));
            if ($tracking === '') {
                $invalidTracking++;
            } elseif ($matchResult === CourierReceiptImportRowMatchResult::DUPLICATE) {
                $duplicateTracking++;
            }
        }

        $codAvailable = (bool) $import->file_has_cod_amounts || $codPresent;
        $chargeAvailable = (bool) $import->file_has_delivery_charges || $chargePresent;

        return [
            'total_data_rows' => $total,
            'delivered_eligible_rows' => $eligible,
            'non_delivered_skipped_rows' => $skipped,
            'invalid_tracking_rows' => $invalidTracking,
            'duplicate_tracking_rows' => $duplicateTracking,
            'cod_total' => $codAvailable ? $importedCod : null,
            'delivery_charge_total' => $chargeAvailable ? $importedCharge : null,
            'cod_total_available' => $codAvailable,
            'delivery_charge_total_available' => $chargeAvailable,
            'financials_available' => $codAvailable || $chargeAvailable,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{
     *     total_data_rows: int,
     *     delivered_eligible_rows: int,
     *     non_delivered_skipped_rows: int,
     *     invalid_tracking_rows: int,
     *     duplicate_tracking_rows: int,
     *     cod_total: string|null,
     *     delivery_charge_total: string|null,
     *     cod_total_available: bool,
     *     delivery_charge_total_available: bool,
     *     financials_available: bool
     * }
     */
    private function normalizeStoredSummary(array $stored, CourierReceiptImport $import): array
    {
        $codAvailable = array_key_exists('cod_total_available', $stored)
            ? (bool) $stored['cod_total_available']
            : ((bool) $import->file_has_cod_amounts && $stored['cod_total'] !== null);
        $chargeAvailable = array_key_exists('delivery_charge_total_available', $stored)
            ? (bool) $stored['delivery_charge_total_available']
            : ((bool) $import->file_has_delivery_charges && $stored['delivery_charge_total'] !== null);

        return [
            'total_data_rows' => (int) ($stored['total_data_rows'] ?? 0),
            'delivered_eligible_rows' => (int) ($stored['delivered_eligible_rows'] ?? 0),
            'non_delivered_skipped_rows' => (int) ($stored['non_delivered_skipped_rows'] ?? 0),
            'invalid_tracking_rows' => (int) ($stored['invalid_tracking_rows'] ?? 0),
            'duplicate_tracking_rows' => (int) ($stored['duplicate_tracking_rows'] ?? 0),
            'cod_total' => $codAvailable && isset($stored['cod_total']) ? (string) $stored['cod_total'] : null,
            'delivery_charge_total' => $chargeAvailable && isset($stored['delivery_charge_total'])
                ? (string) $stored['delivery_charge_total']
                : null,
            'cod_total_available' => $codAvailable,
            'delivery_charge_total_available' => $chargeAvailable,
            'financials_available' => $codAvailable || $chargeAvailable,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function addAmount(array $row, string $primaryKey, string $fallbackKey, string &$total, bool &$present): void
    {
        $normalized = $this->normalizedAmount($row, $primaryKey, $fallbackKey);
        if ($normalized === null) {
            return;
        }

        $present = true;
        $total = $this->money->add($total, $normalized);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function markAmountPresent(array $row, string $primaryKey, string $fallbackKey, bool &$present): void
    {
        if ($this->normalizedAmount($row, $primaryKey, $fallbackKey) !== null) {
            $present = true;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalizedAmount(array $row, string $primaryKey, string $fallbackKey): ?string
    {
        $raw = $row[$primaryKey] ?? null;
        if ($raw === null || trim((string) $raw) === '') {
            $raw = $row[$fallbackKey] ?? null;
        }

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return $this->money->tryNormalizeNonNegative($raw);
    }
}
