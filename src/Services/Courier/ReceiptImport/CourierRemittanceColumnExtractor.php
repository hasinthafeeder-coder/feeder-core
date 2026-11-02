<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

/**
 * Reads remittance columns that a courier file actually contains.
 *
 * Absent columns stay absent. This class does not invent amounts, charges,
 * dates, or currencies from neighboring columns.
 */
class CourierRemittanceColumnExtractor
{
    /**
     * @param  array<string, int>  $headerMap
     * @param  list<string>  $cells
     * @param  array{
     *     gross?: list<string>,
     *     charge?: list<string>,
     *     net?: list<string>,
     *     currency?: list<string>,
     *     business_date?: list<string>,
     *     extras?: array<string, list<string>>
     * }  $columns
     * @return array{
     *     remittance_gross: string|null,
     *     remittance_charge: string|null,
     *     remittance_net: string|null,
     *     remittance_net_explicit: bool,
     *     remittance_currency: string|null,
     *     remittance_business_date: string|null,
     *     remittance_provided_fields: array<string, array<string, mixed>>
     * }
     */
    public function extract(array $headerMap, array $cells, array $columns): array
    {
        $provided = [];

        $gross = $this->read($headerMap, $cells, $columns['gross'] ?? [], $provided, 'gross');
        $charge = $this->read($headerMap, $cells, $columns['charge'] ?? [], $provided, 'charge');
        $netHeader = $this->read($headerMap, $cells, $columns['net'] ?? [], $provided, 'net');
        $currency = $this->read($headerMap, $cells, $columns['currency'] ?? [], $provided, 'currency');
        $businessDate = $this->read($headerMap, $cells, $columns['business_date'] ?? [], $provided, 'business_date');

        foreach ($columns['extras'] ?? [] as $key => $headers) {
            $this->read($headerMap, $cells, $headers, $provided, (string) $key);
        }

        return [
            'remittance_gross' => $gross['present'] ? $gross['raw'] : null,
            'remittance_charge' => $charge['present'] ? $charge['raw'] : null,
            'remittance_net' => $netHeader['present'] ? $netHeader['raw'] : null,
            'remittance_net_explicit' => $netHeader['present'],
            'remittance_currency' => $currency['present'] ? $currency['raw'] : null,
            'remittance_business_date' => $businessDate['present'] ? $businessDate['raw'] : null,
            'remittance_provided_fields' => $provided,
        ];
    }

    /**
     * Company-receipt totals grouped by a currency the file itself supplied.
     * Rows without a currency are counted apart and are not added to any currency.
     * MATCHED returned rows are excluded — a return is not received remittance.
     *
     * @param  list<array<string, mixed>>  $matchedRows
     * @return array<string, mixed>
     */
    public function totalsByCurrency(array $matchedRows): array
    {
        $totals = [];
        $unspecified = 0;

        foreach ($matchedRows as $row) {
            $result = $row['match_result'] ?? null;
            $resultValue = is_object($result) ? $result->value : (string) $result;
            if ($resultValue !== 'MATCHED') {
                continue;
            }

            if (($row['report_outcome'] ?? null) === CourierReportOutcomeClassifier::RETURNED) {
                continue;
            }

            $currency = $row['remittance_currency'] ?? null;
            $net = $row['net_remittance_amount'] ?? null;
            $gross = $row['gross_remittance_amount'] ?? null;
            $charge = $row['courier_charge_amount'] ?? null;

            if (! is_string($currency) || $currency === '') {
                if ($gross !== null || $charge !== null || $net !== null) {
                    $unspecified++;
                }

                continue;
            }

            if (! isset($totals[$currency])) {
                $totals[$currency] = [
                    'row_count' => 0,
                    'gross' => null,
                    'charge' => null,
                    'net' => null,
                ];
            }

            $totals[$currency]['row_count']++;
            $totals[$currency]['gross'] = $this->addNullable($totals[$currency]['gross'], $gross);
            $totals[$currency]['charge'] = $this->addNullable($totals[$currency]['charge'], $charge);
            $totals[$currency]['net'] = $this->addNullable($totals[$currency]['net'], $net);
        }

        ksort($totals);

        return [
            'by_currency' => $totals,
            'unspecified_currency_row_count' => $unspecified,
        ];
    }

    /**
     * @param  array<string, int>  $headerMap
     * @param  list<string>  $cells
     * @param  list<string>  $headers
     * @param  array<string, array<string, mixed>>  $provided
     * @return array{present: bool, raw: string|null, column: string|null}
     */
    private function read(array $headerMap, array $cells, array $headers, array &$provided, string $key): array
    {
        foreach ($headers as $header) {
            if (! array_key_exists($header, $headerMap)) {
                continue;
            }

            $raw = isset($cells[$headerMap[$header]]) ? trim((string) $cells[$headerMap[$header]]) : '';
            $provided[$key] = [
                'column' => $header,
                'raw' => $raw,
            ];

            return [
                'present' => true,
                'raw' => $raw,
                'column' => $header,
            ];
        }

        return [
            'present' => false,
            'raw' => null,
            'column' => null,
        ];
    }

    private function addNullable(?string $current, mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return $current;
        }

        $current ??= '0.00';

        if (function_exists('bcadd')) {
            return bcadd($current, (string) $amount, 2);
        }

        return number_format(((float) $current) + ((float) $amount), 2, '.', '');
    }
}
