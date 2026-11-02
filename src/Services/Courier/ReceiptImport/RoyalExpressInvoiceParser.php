<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Contracts\Courier\CourierReceiptInvoiceParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Native Royal Express delivery-report invoice parser.
 *
 * Matching uses Waybill Number → shipments.tracking_number scoped to the
 * selected courier service. An exact DELIVERED status is a delivery. Any
 * other non-empty Status is a return. An empty Status is ambiguous.
 * Invoice Order Number is ignored for matching.
 * Collected COD and Delivery Charge are stored as remittance evidence when
 * those columns are present. They are not the Feeder courier fee.
 */
class RoyalExpressInvoiceParser implements CourierReceiptInvoiceParser
{
    public const FORMAT_ID = 'ROYAL_EXPRESS_INVOICE_V1';

    public const COURIER_CODE = 'ROYAL';

    public const REQUIRED_HEADERS = [
        'waybill number',
        'status',
    ];

    public function __construct(
        private readonly CourierReceiptSpreadsheetReader $reader,
    ) {}

    public function courierCodes(): array
    {
        return [self::COURIER_CODE];
    }

    public function formatId(): string
    {
        return self::FORMAT_ID;
    }

    public function formatLabel(): string
    {
        return 'Royal Express delivery report (native invoice)';
    }

    public function templateFormat(): string
    {
        return 'csv';
    }

    public function allowedExtensions(): array
    {
        return ['csv', 'xlsx'];
    }

    public function supportsTemplateDownload(): bool
    {
        return true;
    }

    public function templateUnavailableReason(): ?string
    {
        return null;
    }

    public function templateCsv(): string
    {
        $headers = [
            'Waybill Number',
            'Order Number',
            'Customer Business',
            'Customer Name',
            'Customer Email',
            'COD',
            'Collected COD',
            'Delivery Charge',
            'Net Weight',
            'Destination Warehouse',
            'Description',
            'Status',
        ];

        return implode(',', $headers)."\n"
            .'RA05351900,1193770,,,,,,,,"Sample delivered row",DELIVERED'."\n"
            .'RA05351872,1193398,,,,,,,,"Sample returned row",RETURNED TO MERCHANT'."\n";
    }

    public function parse(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $matrix = $this->reader->readMatrix($file, $this->allowedExtensions());

        $headerRow = array_shift($matrix);
        $headerMap = $this->resolveHeaderMap($headerRow ?? []);

        if ($matrix === []) {
            throw ValidationException::withMessages([
                'file' => ['The import file has no data rows.'],
            ]);
        }

        $rows = [];

        foreach ($matrix as $index => $cells) {
            $rowNumber = $index + 2;
            $waybill = $this->cell($cells, $headerMap['waybill number']);
            $statusRaw = $this->cell($cells, $headerMap['status']);

            if ($waybill === '' && $statusRaw === '') {
                continue;
            }

            $classification = (new CourierReportOutcomeClassifier())->classify($statusRaw);
            $remittance = (new CourierRemittanceColumnExtractor())->extract($headerMap, $cells, [
                'gross' => ['collected cod'],
                'charge' => ['delivery charge'],
                'currency' => ['currency'],
                'business_date' => ['business date', 'remittance date', 'payment date'],
            ]);

            $rows[] = [
                'row_number' => $rowNumber,
                'tracking_number' => $waybill,
                // Never expose invoice Order Number for matching or display fallback.
                'order_number' => '',
                'collected_cod' => null,
                'delivery_charge' => null,
                'notes' => $statusRaw,
                'invoice_status' => $statusRaw,
                'eligible_for_matching' => ! $classification['ambiguous'],
                'status_ambiguous' => $classification['ambiguous'],
                'report_outcome' => $classification['outcome'],
                'skip_reason' => $classification['reason'],
            ] + $remittance;
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['The import file has no data rows.'],
            ]);
        }

        return [
            'file_type' => $extension,
            'file_has_cod_amounts' => false,
            'file_has_delivery_charges' => false,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $headerRow
     * @return array<string, int>
     */
    private function resolveHeaderMap(array $headerRow): array
    {
        $normalized = [];
        foreach ($headerRow as $index => $header) {
            $key = $this->normalizeHeader((string) $header);
            if ($key === '') {
                continue;
            }
            $normalized[$key] = (int) $index;
        }

        $missing = [];
        foreach (self::REQUIRED_HEADERS as $required) {
            if (! array_key_exists($required, $normalized)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => [
                    'Malformed headers for Royal Express invoice. '
                    .'Required columns: Waybill Number, Status. Missing: '
                    .implode(', ', $missing).'.',
                ],
            ]);
        }

        return $normalized;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return $header;
    }

    /**
     * @param  list<string>  $cells
     */
    private function cell(array $cells, int $index): string
    {
        return isset($cells[$index]) ? trim((string) $cells[$index]) : '';
    }
}
