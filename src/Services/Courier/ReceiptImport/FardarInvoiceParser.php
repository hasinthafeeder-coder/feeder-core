<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Contracts\Courier\CourierReceiptInvoiceParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Native Fardar delivery-report CSV invoice parser.
 *
 * Processes Delivered rows only. Matching uses Waybill ID →
 * shipments.tracking_number scoped to the selected courier service.
 * Invoice Order ID and financial columns are ignored.
 */
class FardarInvoiceParser implements CourierReceiptInvoiceParser
{
    public const FORMAT_ID = 'FARDAR_INVOICE_V1';

    public const COURIER_CODE = 'FARDAR';

    public const REQUIRED_HEADERS = [
        'waybill id',
        'delivery status',
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
        return 'Fardar delivery report (native invoice)';
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
            'Waybill ID',
            'Order ID',
            'Weight (kg)',
            'Client',
            'Order Date',
            'Last Scan Date',
            'Parcel Descp',
            'Delivered By',
            'Delivery Status',
            'Amount',
            'Delivery Fee',
            'COD Commission',
            'Return Fee',
        ];

        return implode(',', $headers)."\n"
            .'API4746450,939667,1,"Lucky Leo feeder.lk pvt ltd (17287)"," 2026-08-21 16:51:56"," 2026-08-29 08:39:28","""Leo Forest"" Car diffuser perfume","Rohan Nishantha (1702)",Delivered,1440,425.00,0,0.00'."\n"
            .'API4746451,939668,1,"Lucky Leo feeder.lk pvt ltd (17287)"," 2026-08-21 16:51:56"," 2026-08-29 08:39:28","Sample non-delivered row","Agent (1)",Returned,0,0.00,0,0.00'."\n";
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
            $waybill = $this->cell($cells, $headerMap['waybill id']);
            $statusRaw = $this->cell($cells, $headerMap['delivery status']);

            if ($waybill === '' && $statusRaw === '') {
                continue;
            }

            $normalizedStatus = $this->normalizeStatus($statusRaw);
            $eligible = $normalizedStatus === 'DELIVERED';

            $rows[] = [
                'row_number' => $rowNumber,
                'tracking_number' => $waybill,
                // Never expose invoice Order ID for matching or display fallback.
                'order_number' => '',
                'collected_cod' => null,
                'delivery_charge' => null,
                'notes' => $statusRaw,
                'invoice_status' => $statusRaw,
                'eligible_for_matching' => $eligible,
                'skip_reason' => $eligible
                    ? null
                    : 'Delivery Status is '
                        .($statusRaw !== '' ? $statusRaw : '(empty)')
                        .'; only Delivered rows are processed.',
            ];
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
                    'Malformed headers for Fardar invoice. '
                    .'Required columns: Waybill ID, Delivery Status. Missing: '
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

    private function normalizeStatus(string $status): string
    {
        $status = strtoupper(trim($status));
        $status = preg_replace('/\s+/', ' ', $status) ?? $status;

        return $status;
    }

    /**
     * @param  list<string>  $cells
     */
    private function cell(array $cells, int $index): string
    {
        return isset($cells[$index]) ? trim((string) $cells[$index]) : '';
    }
}
