<?php

namespace Feeder\Core\Services\Courier\TransExpress;

use Feeder\Core\Services\Courier\Curfox\MappedCourierCity;
use Feeder\Core\Services\Courier\Curfox\MappedCourierState;

/**
 * Maps TransExpress location payloads to shared courier location DTOs.
 *
 * ERP mapping (2-level schema, UI-compatible):
 * - TransExpress District → courier_states (UI "District / State")
 * - TransExpress City → courier_cities
 * - TransExpress Province ID → courier_states.external_ref_no (parent link)
 *
 * Provinces are not stored as courier_states rows: province and district IDs
 * collide in the TransExpress namespace, and the order UI selects districts.
 */
final class TransExpressLocationMapper
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapProvince(array $payload): ?array
    {
        $externalId = $this->stringId($payload['id'] ?? null);
        $name = trim((string) ($payload['text'] ?? $payload['name'] ?? ''));

        if ($externalId === null || $name === '') {
            return null;
        }

        return [
            'external_id' => $externalId,
            'name' => $name,
        ];
    }

    /**
     * Map a TransExpress district into a courier_states DTO.
     *
     * @param  array<string, mixed>  $payload
     */
    public function mapDistrict(array $payload, string $provinceExternalId): ?MappedCourierState
    {
        $externalId = $this->stringId($payload['id'] ?? null);
        $name = trim((string) ($payload['text'] ?? $payload['name'] ?? ''));

        if ($externalId === null || $name === '') {
            return null;
        }

        return new MappedCourierState(
            externalId: $externalId,
            externalRefNo: $provinceExternalId,
            name: $name,
            isActive: true,
        );
    }

    /**
     * Map a TransExpress city; district relationship comes from the request context,
     * not from the city payload (which only contains id + text).
     *
     * @param  array<string, mixed>  $payload
     */
    public function mapCity(array $payload, string $districtExternalId): ?MappedCourierCity
    {
        $externalId = $this->stringId($payload['id'] ?? null);
        $name = trim((string) ($payload['text'] ?? $payload['name'] ?? ''));

        if ($externalId === null || $name === '' || $districtExternalId === '') {
            return null;
        }

        return new MappedCourierCity(
            externalId: $externalId,
            externalRefNo: null,
            name: $name,
            postalCode: null,
            providerStateExternalId: $districtExternalId,
            isActive: true,
        );
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $items
     * @return list<array{external_id: string, name: string}>
     */
    public function mapProvinces(array $items): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $province = $this->mapProvince($item);

            if ($province !== null) {
                $mapped[] = $province;
            }
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $items
     * @return list<MappedCourierState>
     */
    public function mapDistricts(array $items, string $provinceExternalId): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $district = $this->mapDistrict($item, $provinceExternalId);

            if ($district !== null) {
                $mapped[] = $district;
            }
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $items
     * @return list<MappedCourierCity>
     */
    public function mapCities(array $items, string $districtExternalId): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $city = $this->mapCity($item, $districtExternalId);

            if ($city !== null) {
                $mapped[] = $city;
            }
        }

        return $mapped;
    }

    private function stringId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
