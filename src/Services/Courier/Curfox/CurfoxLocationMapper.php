<?php

namespace Feeder\Core\Services\Courier\Curfox;

/**
 * Maps Curfox API payloads to internal location DTOs.
 *
 * Curfox field names must not leak outside this integration layer.
 * State API is authoritative for states; City API is authoritative for cities.
 * Nested cities on a state payload are supported only as an optional convenience
 * and must not create duplicates when the City API is also used.
 */
final class CurfoxLocationMapper
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapState(array $payload): ?MappedCourierState
    {
        $externalId = $this->stringId($payload['id'] ?? null);

        if ($externalId === null) {
            return null;
        }

        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return new MappedCourierState(
            externalId: $externalId,
            externalRefNo: $this->nullableString($payload['ref_no'] ?? null),
            name: $name,
            isActive: $this->resolveIsActive($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapCity(array $payload, ?string $fallbackStateExternalId = null): ?MappedCourierCity
    {
        $externalId = $this->stringId($payload['id'] ?? null);
        $providerStateExternalId = $this->stringId($payload['state_id'] ?? null)
            ?? $fallbackStateExternalId;

        if ($externalId === null || $providerStateExternalId === null) {
            return null;
        }

        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return new MappedCourierCity(
            externalId: $externalId,
            externalRefNo: $this->nullableString($payload['ref_no'] ?? null),
            name: $name,
            postalCode: $this->nullableString($payload['postal_code'] ?? null),
            providerStateExternalId: $providerStateExternalId,
            isActive: $this->resolveIsActive($payload),
        );
    }

    /**
     * Map the /state response into state → nested city hierarchy.
     *
     * @param  list<mixed>|array<string, mixed>  $items
     * @return list<array{state: MappedCourierState, cities: list<MappedCourierCity>}>
     */
    public function mapStateHierarchy(array $items): array
    {
        $hierarchy = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $state = $this->mapState($item);

            if ($state === null) {
                continue;
            }

            $cities = [];
            $nested = $item['cities'] ?? null;

            if (is_array($nested)) {
                foreach ($nested as $cityItem) {
                    if (! is_array($cityItem)) {
                        continue;
                    }

                    $city = $this->mapCity($cityItem, $state->externalId);

                    if ($city !== null) {
                        $cities[] = $city;
                    }
                }
            }

            $hierarchy[] = [
                'state' => $state,
                'cities' => $cities,
            ];
        }

        return $hierarchy;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $items
     * @return list<MappedCourierState>
     */
    public function mapStates(array $items): array
    {
        $mapped = [];

        foreach ($this->mapStateHierarchy($items) as $row) {
            $mapped[] = $row['state'];
        }

        return $mapped;
    }

    /**
     * Flatten nested cities from a /state payload list.
     *
     * @param  list<mixed>|array<string, mixed>  $items
     * @return list<MappedCourierCity>
     */
    public function mapCitiesFromStateHierarchy(array $items): array
    {
        $mapped = [];

        foreach ($this->mapStateHierarchy($items) as $row) {
            foreach ($row['cities'] as $city) {
                $mapped[] = $city;
            }
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $items
     * @return list<MappedCourierCity>
     */
    public function mapCities(array $items): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $city = $this->mapCity($item);

            if ($city !== null) {
                $mapped[] = $city;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveIsActive(array $payload): bool
    {
        if (array_key_exists('deleted_at', $payload) && $payload['deleted_at'] !== null) {
            return false;
        }

        if (! array_key_exists('is_active', $payload)) {
            return true;
        }

        return $this->toBool($payload['is_active']);
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'active'], true);
        }

        return (bool) $value;
    }
}
