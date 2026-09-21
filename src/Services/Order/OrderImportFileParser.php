<?php

namespace Feeder\Core\Services\Order;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Parses reseller order import spreadsheets into associative row maps.
 *
 * Expected headers (exact, case-insensitive):
 * name, address, tp_1, tp_2, price, qty, item_code, delivery
 *
 * Formats: CSV (native), XLSX (OOXML via ZipArchive). Legacy .xls is not supported
 * by existing project infrastructure and is rejected with a clear message.
 */
class OrderImportFileParser
{
    public const REQUIRED_HEADERS = [
        'name',
        'address',
        'tp_1',
        'tp_2',
        'price',
        'qty',
        'item_code',
        'delivery',
    ];

    public const MAX_FILE_BYTES = 5_242_880; // 5 MB

    public const ALLOWED_EXTENSIONS = ['csv', 'xlsx'];

    /**
     * @return array{
     *     file_type: string,
     *     rows: list<array{
     *         row_number: int,
     *         name: string,
     *         address: string,
     *         tp_1: string,
     *         tp_2: string,
     *         price: string,
     *         qty: string,
     *         item_code: string,
     *         delivery: string
     *     }>
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw ValidationException::withMessages([
                'file' => ['Legacy .xls files are not supported. Please save as .xlsx or .csv.'],
            ]);
        }

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['Only CSV and XLSX files are supported.'],
            ]);
        }

        if ($file->getSize() !== null && $file->getSize() > self::MAX_FILE_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['The import file must be 5 MB or smaller.'],
            ]);
        }

        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be read.'],
            ]);
        }

        $matrix = match ($extension) {
            'csv' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw ValidationException::withMessages([
                'file' => ['Only CSV and XLSX files are supported.'],
            ]),
        };

        return [
            'file_type' => $extension,
            'rows' => $this->mapRows($matrix),
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['The CSV file could not be opened.'],
            ]);
        }

        $matrix = [];
        try {
            while (($data = fgetcsv($handle)) !== false) {
                if ($this->isEmptyMatrixRow($data)) {
                    continue;
                }
                $matrix[] = array_map(
                    static fn ($value) => trim((string) $value),
                    $data
                );
            }
        } finally {
            fclose($handle);
        }

        return $matrix;
    }

    /**
     * Minimal first-sheet XLSX reader for flat import templates.
     *
     * @return list<list<string>>
     */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'file' => ['The spreadsheet could not be read. Ensure it is a valid .xlsx file.'],
            ]);
        }

        try {
            $sharedStrings = $this->parseSharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $sheetPath = $this->resolveFirstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false || $sheetXml === '') {
                throw ValidationException::withMessages([
                    'file' => ['The spreadsheet worksheet could not be read.'],
                ]);
            }

            return $this->parseSheetXml($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private function resolveFirstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $sheetRid = null;
        if (preg_match('/<sheet[^>]*r:id="([^"]+)"/i', $workbook, $match) === 1) {
            $sheetRid = $match[1];
        }

        if ($sheetRid !== null
            && preg_match(
                '/Relationship[^>]*Id="'.preg_quote($sheetRid, '/').'"[^>]*Target="([^"]+)"/i',
                $rels,
                $relMatch
            ) === 1
        ) {
            $target = ltrim(str_replace('\\', '/', $relMatch[1]), '/');
            if (! str_starts_with($target, 'xl/')) {
                $target = 'xl/'.$target;
            }

            return $target;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function parseSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $document = new DOMDocument;
        if (@$document->loadXML($xml) !== true) {
            return [];
        }

        $strings = [];
        foreach ($document->getElementsByTagNameNS(
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
            'si'
        ) as $si) {
            $value = '';
            foreach ($si->getElementsByTagNameNS(
                'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
                't'
            ) as $textNode) {
                $value .= $textNode->textContent;
            }
            $strings[] = trim($value);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<list<string>>
     */
    private function parseSheetXml(string $xml, array $sharedStrings): array
    {
        $document = new DOMDocument;
        if (@$document->loadXML($xml) !== true) {
            throw ValidationException::withMessages([
                'file' => ['The spreadsheet worksheet XML is invalid.'],
            ]);
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $matrix = [];

        foreach ($document->getElementsByTagNameNS($ns, 'row') as $row) {
            $cells = [];
            foreach ($row->getElementsByTagNameNS($ns, 'c') as $cell) {
                if ($cell->parentNode !== $row) {
                    continue;
                }

                $ref = $cell->getAttribute('r');
                $colIndex = $this->columnIndexFromReference($ref);
                if ($colIndex === null) {
                    continue;
                }

                $type = $cell->getAttribute('t');
                $valueNodes = $cell->getElementsByTagNameNS($ns, 'v');
                $raw = $valueNodes->length > 0 ? (string) $valueNodes->item(0)?->textContent : '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $textNodes = $cell->getElementsByTagNameNS($ns, 't');
                    $value = $textNodes->length > 0
                        ? trim((string) $textNodes->item(0)?->textContent)
                        : '';
                } else {
                    $value = $this->normalizeNumericCell($raw);
                }

                $cells[$colIndex] = trim($value);
            }

            if ($cells === []) {
                continue;
            }

            ksort($cells);
            $max = max(array_keys($cells));
            $ordered = [];
            for ($i = 0; $i <= $max; $i++) {
                $ordered[] = $cells[$i] ?? '';
            }

            if (! $this->isEmptyMatrixRow($ordered)) {
                $matrix[] = $ordered;
            }
        }

        return $matrix;
    }

    private function columnIndexFromReference(string $reference): ?int
    {
        if (preg_match('/^([A-Z]+)/i', $reference, $match) !== 1) {
            return null;
        }

        $letters = strtoupper($match[1]);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private function normalizeNumericCell(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (! is_numeric($raw)) {
            return $raw;
        }

        $float = (float) $raw;
        if (floor($float) == $float) {
            return (string) (int) $float;
        }

        return rtrim(rtrim(sprintf('%.8F', $float), '0'), '.');
    }

    /**
     * @param  list<list<string>>  $matrix
     * @return list<array{
     *     row_number: int,
     *     name: string,
     *     address: string,
     *     tp_1: string,
     *     tp_2: string,
     *     price: string,
     *     qty: string,
     *     item_code: string,
     *     delivery: string
     * }>
     */
    private function mapRows(array $matrix): array
    {
        if ($matrix === []) {
            throw ValidationException::withMessages([
                'file' => ['The import file is empty.'],
            ]);
        }

        $headerRow = array_shift($matrix);
        $headerMap = $this->resolveHeaderMap($headerRow ?? []);

        if ($matrix === []) {
            throw ValidationException::withMessages([
                'file' => ['The import file has no data rows.'],
            ]);
        }

        $rows = [];
        foreach ($matrix as $index => $cells) {
            $rowNumber = $index + 2; // header is row 1
            $rows[] = [
                'row_number' => $rowNumber,
                'name' => $this->cell($cells, $headerMap['name']),
                'address' => $this->cell($cells, $headerMap['address']),
                'tp_1' => $this->cell($cells, $headerMap['tp_1']),
                'tp_2' => $this->cell($cells, $headerMap['tp_2']),
                'price' => $this->cell($cells, $headerMap['price']),
                'qty' => $this->cell($cells, $headerMap['qty']),
                'item_code' => $this->cell($cells, $headerMap['item_code']),
                'delivery' => $this->cell($cells, $headerMap['delivery']),
            ];
        }

        return $rows;
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

        $missing = [];
        foreach (self::REQUIRED_HEADERS as $required) {
            if (! array_key_exists($required, $normalized)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => [
                    'Malformed headers. Expected columns: '
                    .implode(', ', self::REQUIRED_HEADERS)
                    .'. Missing: '.implode(', ', $missing).'.',
                ],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $cells
     */
    private function cell(array $cells, int $index): string
    {
        return isset($cells[$index]) ? trim((string) $cells[$index]) : '';
    }

    /**
     * @param  list<mixed>  $row
     */
    private function isEmptyMatrixRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
