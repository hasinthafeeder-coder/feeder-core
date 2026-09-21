<?php

namespace Feeder\Core\Services\Courier\Fardar;

use Feeder\Core\Services\Courier\Curfox\MappedCourierCity;
use Feeder\Core\Services\Courier\Curfox\MappedCourierState;
use RuntimeException;

/**
 * Maps Fardar Express Domestic location CSV rows to shared courier location DTOs.
 *
 * ERP mapping (2-level schema, UI-compatible):
 * - Fardar District → courier_states (UI "District / State")
 * - Fardar City → courier_cities
 *
 * Expected headers (underscore or space variants accepted):
 *   city_id, city_name, zone_id, zone name, district_id, district name
 *
 * Zone columns are recognized for CSV compatibility but are not persisted;
 * the shared courier location schema has no zone storage.
 */
final class FardarLocationCsvMapper
{
    public const HEADER_DISTRICT_ID = 'district id';

    public const HEADER_DISTRICT_NAME = 'district name';

    public const HEADER_CITY_ID = 'city id';

    public const HEADER_CITY_NAME = 'city name';

    public const HEADER_ZONE_ID = 'zone id';

    public const HEADER_ZONE_NAME = 'zone name';

    /**
     * @return list<string>
     */
    public function requiredHeaders(): array
    {
        return [
            self::HEADER_DISTRICT_ID,
            self::HEADER_DISTRICT_NAME,
            self::HEADER_CITY_ID,
            self::HEADER_CITY_NAME,
        ];
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array<string, int> column name → index
     */
    public function validateAndIndexHeaders(array $headerRow): array
    {
        $normalized = [];

        foreach ($headerRow as $index => $header) {
            $name = $this->normalizeHeader((string) ($header ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized[$name] = (int) $index;
        }

        $missing = [];

        foreach ($this->requiredHeaders() as $required) {
            if (! array_key_exists($required, $normalized)) {
                $missing[] = $required;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Fardar CSV is missing required header(s): '.implode(', ', $missing).'.'
            );
        }

        return $normalized;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $columnIndex
     * @return array{
     *     ok: true,
     *     district: MappedCourierState,
     *     city: MappedCourierCity
     * }|array{
     *     ok: false,
     *     skip: bool,
     *     message: string|null
     * }
     */
    public function mapRow(array $row, array $columnIndex, int $lineNumber): array
    {
        if ($this->isBlankRow($row)) {
            return [
                'ok' => false,
                'skip' => true,
                'message' => null,
            ];
        }

        $districtIdRaw = $this->cell($row, $columnIndex, self::HEADER_DISTRICT_ID);
        $districtName = $this->cell($row, $columnIndex, self::HEADER_DISTRICT_NAME);
        $cityIdRaw = $this->cell($row, $columnIndex, self::HEADER_CITY_ID);
        $cityName = $this->cell($row, $columnIndex, self::HEADER_CITY_NAME);

        $errors = [];

        $districtId = $this->numericId($districtIdRaw);
        if ($districtId === null) {
            $errors[] = 'missing or invalid district id';
        }

        if ($districtName === '') {
            $errors[] = 'missing district name';
        }

        $cityId = $this->numericId($cityIdRaw);
        if ($cityId === null) {
            $errors[] = 'missing or invalid city id';
        }

        if ($cityName === '') {
            $errors[] = 'missing city name';
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'skip' => false,
                'message' => 'Line '.$lineNumber.': '.implode('; ', $errors).'.',
            ];
        }

        /** @var string $districtId */
        /** @var string $cityId */

        return [
            'ok' => true,
            'district' => new MappedCourierState(
                externalId: $districtId,
                externalRefNo: null,
                name: $districtName,
                isActive: true,
            ),
            'city' => new MappedCourierCity(
                externalId: $cityId,
                externalRefNo: null,
                name: $cityName,
                postalCode: null,
                providerStateExternalId: $districtId,
                isActive: true,
            ),
        ];
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));

        // Accept both city_id and "city id" (corrected CSV uses underscores; legacy used spaces).
        return str_replace('_', ' ', $header);
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $columnIndex
     */
    private function cell(array $row, array $columnIndex, string $header): string
    {
        $index = $columnIndex[$header] ?? null;

        if ($index === null || ! array_key_exists($index, $row)) {
            return '';
        }

        return trim((string) ($row[$index] ?? ''));
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Fardar external IDs must be numeric (stored as strings, matching other couriers).
     */
    private function numericId(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^\d+$/', $trimmed)) {
            return null;
        }

        // Normalize leading zeros while preserving a single zero if ever present.
        return (string) (int) $trimmed;
    }
}
