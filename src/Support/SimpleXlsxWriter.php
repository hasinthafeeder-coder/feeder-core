<?php

namespace Feeder\Core\Support;

use RuntimeException;
use ZipArchive;

/**
 * Minimal multi-sheet XLSX writer (OOXML + ZipArchive).
 *
 * Canonical copy of the writer used by admin/reseller apps where PhpSpreadsheet
 * is not a project dependency. Compatible with
 * Feeder\Core\Services\Courier\ReceiptImport\CourierReceiptSpreadsheetReader.
 */
class SimpleXlsxWriter
{
    /**
     * @param  array<string, list<list<string|int|float|null>>>  $sheets  sheet name => matrix (including header)
     */
    public function write(string $absolutePath, array $sheets): void
    {
        if ($sheets === []) {
            throw new RuntimeException('At least one worksheet is required.');
        }

        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: '.$directory);
        }

        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create spreadsheet at '.$absolutePath);
        }

        $sharedStrings = [];
        $sharedIndex = [];
        $sheetParts = [];
        $sheetId = 0;

        foreach ($sheets as $name => $matrix) {
            $sheetId++;
            $sheetRows = '';
            foreach ($matrix as $r => $row) {
                $rowNumber = $r + 1;
                $sheetRows .= '<row r="'.$rowNumber.'">';
                foreach ($row as $c => $value) {
                    $col = $this->columnLetters($c).$rowNumber;
                    $stringValue = $this->cellString($value);
                    if (! array_key_exists($stringValue, $sharedIndex)) {
                        $sharedIndex[$stringValue] = count($sharedStrings);
                        $sharedStrings[] = $stringValue;
                    }
                    $idx = $sharedIndex[$stringValue];
                    $sheetRows .= '<c r="'.$col.'" t="s"><v>'.$idx.'</v></c>';
                }
                $sheetRows .= '</row>';
            }

            $sheetParts[] = [
                'id' => $sheetId,
                'name' => $this->sanitizeSheetName((string) $name),
                'path' => 'worksheets/sheet'.$sheetId.'.xml',
                'xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                    .'<sheetData>'.$sheetRows.'</sheetData></worksheet>',
            ];
        }

        $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            .count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">';
        foreach ($sharedStrings as $value) {
            $sharedXml .= '<si><t>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
        }
        $sharedXml .= '</sst>';

        $overrideParts = '';
        $workbookSheets = '';
        $workbookRels = '';
        foreach ($sheetParts as $part) {
            $rId = 'rId'.$part['id'];
            $overrideParts .= '<Override PartName="/xl/'.$part['path'].'" '
                .'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="'.htmlspecialchars($part['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" '
                .'sheetId="'.$part['id'].'" r:id="'.$rId.'"/>';
            $workbookRels .= '<Relationship Id="'.$rId.'" '
                .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                .'Target="'.$part['path'].'"/>';
        }

        $sharedRid = 'rId'.($sheetId + 1);
        $workbookRels .= '<Relationship Id="'.$sharedRid.'" '
            .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" '
            .'Target="sharedStrings.xml"/>';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrideParts
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$workbookRels
            .'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);

        foreach ($sheetParts as $part) {
            $zip->addFromString('xl/'.$part['path'], $part['xml']);
        }

        $zip->close();
    }

    private function cellString(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function columnLetters(int $zeroBasedIndex): string
    {
        $index = $zeroBasedIndex + 1;
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $name);
        if ($name === '') {
            $name = 'Sheet';
        }

        return mb_substr($name, 0, 31);
    }
}
