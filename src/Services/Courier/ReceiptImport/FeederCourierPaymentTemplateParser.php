<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use Feeder\Core\Contracts\Courier\CourierReceiptInvoiceParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Feeder-owned courier payment import template (v1).
 *
 * Retained for DI and future generic-template use. No courier currently routes
 * here: ROYAL → RoyalExpressInvoiceParser, TRANSEXPRESS → TransExpressInvoiceParser,
 * FARDAR → FardarInvoiceParser.
 *
 * Required column: tracking_number
 * Optional columns: order_number, collected_cod, delivery_charge, notes
 */
class FeederCourierPaymentTemplateParser implements CourierReceiptInvoiceParser
{
    public const FORMAT_ID = 'FEEDER_COURIER_PAYMENT_TEMPLATE_V1';

    public const REQUIRED_HEADERS = ['tracking_number'];

    public const OPTIONAL_HEADERS = [
        'order_number',
        'collected_cod',
        'delivery_charge',
        'notes',
    ];

    public function __construct(
        private readonly CourierReceiptSpreadsheetReader $reader,
    ) {}

    public function courierCodes(): array
    {
        // Native parsers own ROYAL, TRANSEXPRESS, and FARDAR.
        return [];
    }

    public function formatId(): string
    {
        return self::FORMAT_ID;
    }

    public function formatLabel(): string
    {
        return 'Feeder import template (not a native courier invoice export)';
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
        $headers = array_merge(self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS);

        return implode(',', $headers)."\n"
            ."WAYBILL-EXAMPLE,,,,Feeder template sample row\n";
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
        $hasCod = false;
        $hasCharge = false;

        foreach ($matrix as $index => $cells) {
            $rowNumber = $index + 2;
            $cod = $this->optionalCell($cells, $headerMap, 'collected_cod');
            $charge = $this->optionalCell($cells, $headerMap, 'delivery_charge');

            if ($cod !== null && $cod !== '') {
                $hasCod = true;
            }
            if ($charge !== null && $charge !== '') {
                $hasCharge = true;
            }

            $remittance = (new CourierRemittanceColumnExtractor())->extract($headerMap, $cells, [
                'gross' => ['collected_cod'],
                'charge' => ['delivery_charge'],
                'currency' => ['currency'],
                'business_date' => ['business_date'],
            ]);

            $rows[] = [
                'row_number' => $rowNumber,
                'tracking_number' => $this->cell($cells, $headerMap['tracking_number']),
                'order_number' => $this->optionalCell($cells, $headerMap, 'order_number') ?? '',
                'collected_cod' => $cod,
                'delivery_charge' => $charge,
                'notes' => $this->optionalCell($cells, $headerMap, 'notes') ?? '',
            ] + $remittance;
        }

        return [
            'file_type' => $extension,
            'file_has_cod_amounts' => $hasCod,
            'file_has_delivery_charges' => $hasCharge,
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
            $key = strtolower(trim((string) $header));
            if ($key === '') {
                continue;
            }
            $normalized[$key] = (int) $index;
        }

        foreach (self::REQUIRED_HEADERS as $required) {
            if (! array_key_exists($required, $normalized)) {
                throw ValidationException::withMessages([
                    'file' => [
                        'Malformed headers for Feeder courier payment template. '
                        .'Required column: tracking_number. Optional: '
                        .implode(', ', self::OPTIONAL_HEADERS).'.',
                    ],
                ]);
            }
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $headerMap
     */
    private function cell(array $cells, int $index): string
    {
        return isset($cells[$index]) ? trim((string) $cells[$index]) : '';
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $headerMap
     */
    private function optionalCell(array $cells, array $headerMap, string $key): ?string
    {
        if (! array_key_exists($key, $headerMap)) {
            return null;
        }

        return $this->cell($cells, $headerMap[$key]);
    }
}
