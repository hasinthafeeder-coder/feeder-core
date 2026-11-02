<?php

namespace Feeder\Core\Services\Settlement;

use Feeder\Core\Models\SupplierSettlementBankExportLine;

/**
 * Builds the fixed ten-column supplier settlement bank-export workbook matrix.
 *
 * Column order and headers are part of the export contract for this phase.
 * Origin account columns remain for format compatibility and are left blank:
 * Feeder does not debit a platform account or initiate transfers.
 */
class SupplierSettlementBankExportWorkbookBuilder
{
    public const SHEET_NAME = 'Payment Instructions';

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'Origin Account Number',
            'Origin Account Name',
            'Value Date (DD/MM/YYYY)',
            'Beneficiary Bank Code',
            'Beneficiary Branch Code',
            'Beneficiary Account Number',
            'Beneficiary Account Name',
            'Amount',
            'Particulars',
            'References',
        ];
    }

    /**
     * @param  iterable<int, SupplierSettlementBankExportLine>  $lines
     * @return array<string, list<list<string>>>
     */
    public function buildSheets(iterable $lines): array
    {
        $matrix = [$this->headers()];

        foreach ($lines as $line) {
            $matrix[] = [
                (string) $line->origin_account_number,
                (string) $line->origin_account_name,
                (string) $line->value_date_text,
                (string) $line->beneficiary_bank_code,
                (string) $line->beneficiary_branch_code,
                (string) $line->beneficiary_account_number,
                (string) $line->beneficiary_account_name,
                $this->formatAmount((string) $line->amount),
                (string) $line->particulars,
                (string) $line->reference_text,
            ];
        }

        return [self::SHEET_NAME => $matrix];
    }

    private function formatAmount(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
