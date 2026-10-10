<?php

namespace Feeder\Core\Services\Courier\ReceiptImport;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Minimal CSV / XLSX matrix reader for courier receipt imports.
 *
 * Mirrors OrderImportFileParser infrastructure (ZipArchive OOXML, no PhpSpreadsheet).
 * Does not evaluate formulas or macros; reads cached cell values only.
 */
class CourierReceiptSpreadsheetReader
{
    public const MAX_FILE_BYTES = 5_242_880; // 5 MB

    public const MAX_DATA_ROWS = 10_000;

    /**
     * @param  list<string>  $allowedExtensions
     * @return list<list<string>>
     */
    public function readMatrix(UploadedFile $file, array $allowedExtensions): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw ValidationException::withMessages([
                'file' => ['Legacy .xls files are not supported. Please save as .xlsx or .csv.'],
            ]);
        }

        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => ['Unsupported file type for this courier import format.'],
            ]);
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_FILE_BYTES) {
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

        // Prefer content sniffing over client MIME.
        if ($extension === 'xlsx' && ! $this->looksLikeZip($path)) {
            throw ValidationException::withMessages([
                'file' => ['The spreadsheet could not be read. Ensure it is a valid .xlsx file.'],
            ]);
        }

        $matrix = match ($extension) {
            'csv' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw ValidationException::withMessages([
                'file' => ['Unsupported file type for this courier import format.'],
            ]),
        };

        if ($matrix === []) {
            throw ValidationException::withMessages([
                'file' => ['The import file is empty.'],
            ]);
        }

        return $matrix;
    }

    private function looksLikeZip(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 4);

            return is_string($header) && str_starts_with($header, 'PK');
        } finally {
            fclose($handle);
        }
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
        $dataRows = 0;

        try {
            while (($data = fgetcsv($handle)) !== false) {
                if ($this->isEmptyMatrixRow($data)) {
                    continue;
                }

                $matrix[] = array_map(
                    static fn ($value) => trim((string) $value),
                    $data
                );

                if (count($matrix) > 1) {
                    $dataRows++;
                    if ($dataRows > self::MAX_DATA_ROWS) {
                        throw ValidationException::withMessages([
                            'file' => ['The import file exceeds the maximum of '.self::MAX_DATA_ROWS.' data rows.'],
                        ]);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $matrix;
    }

    /**
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
            // Reject obvious macro-enabled packages by presence of vbaProject.
            if ($zip->locateName('xl/vbaProject.bin') !== false) {
                throw ValidationException::withMessages([
                    'file' => ['Macro-enabled spreadsheets are not allowed. Export as .xlsx without macros.'],
                ]);
            }

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

    /**
     * @return list<string>
     */
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
        $dataRows = 0;

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

                // Prefer cached <v> values; do not execute formulas.
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

            if ($this->isEmptyMatrixRow($ordered)) {
                continue;
            }

            $matrix[] = $ordered;

            if (count($matrix) > 1) {
                $dataRows++;
                if ($dataRows > self::MAX_DATA_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => ['The import file exceeds the maximum of '.self::MAX_DATA_ROWS.' data rows.'],
                    ]);
                }
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
