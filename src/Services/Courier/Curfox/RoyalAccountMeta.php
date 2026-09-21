<?php

namespace Feeder\Core\Services\Courier\Curfox;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Models\SupplierCourierAccount;
use Illuminate\Validation\ValidationException;

/**
 * Non-secret ROYAL account metadata stored in supplier_courier_accounts.meta_json.
 */
final class RoyalAccountMeta
{
    public const KEY_MERCHANT_BUSINESS_ID = 'merchant_business_id';

    public const KEY_ORIGIN_STATE_ID = 'origin_state_id';

    public const KEY_ORIGIN_CITY_ID = 'origin_city_id';

    public const KEY_ORIGIN_STATE_NAME = 'origin_state_name';

    public const KEY_ORIGIN_CITY_NAME = 'origin_city_name';

    /**
     * @param  array{
     *     merchant_business_id?: mixed,
     *     origin_state_id?: mixed,
     *     origin_city_id?: mixed
     * }  $input
     * @return array{
     *     merchant_business_id: string,
     *     origin_state_id: int,
     *     origin_city_id: int,
     *     origin_state_name: string,
     *     origin_city_name: string
     * }
     */
    public function validateAndBuild(Courier $courier, array $input): array
    {
        $merchantBusinessId = trim((string) ($input[self::KEY_MERCHANT_BUSINESS_ID] ?? ''));

        if ($merchantBusinessId === '') {
            throw ValidationException::withMessages([
                'meta.merchant_business_id' => ['Merchant Business ID is required.'],
            ]);
        }

        $stateId = (int) ($input[self::KEY_ORIGIN_STATE_ID] ?? 0);
        $cityId = (int) ($input[self::KEY_ORIGIN_CITY_ID] ?? 0);

        if ($stateId <= 0) {
            throw ValidationException::withMessages([
                'meta.origin_state_id' => ['Origin state is required.'],
            ]);
        }

        if ($cityId <= 0) {
            throw ValidationException::withMessages([
                'meta.origin_city_id' => ['Origin city is required.'],
            ]);
        }

        $state = CourierState::query()
            ->where('courier_id', $courier->id)
            ->active()
            ->find($stateId);

        if ($state === null) {
            throw ValidationException::withMessages([
                'meta.origin_state_id' => ['Origin state is invalid for ROYAL.'],
            ]);
        }

        $city = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('courier_state_id', $state->id)
            ->active()
            ->find($cityId);

        if ($city === null) {
            throw ValidationException::withMessages([
                'meta.origin_city_id' => ['Origin city must belong to the selected ROYAL origin state.'],
            ]);
        }

        $stateName = trim((string) $state->name);
        $cityName = trim((string) ($city->city_name ?: $city->name));

        if ($stateName === '' || $cityName === '') {
            throw ValidationException::withMessages([
                'meta.origin_city_id' => ['Origin state/city names are incomplete in courier location data.'],
            ]);
        }

        return [
            self::KEY_MERCHANT_BUSINESS_ID => $merchantBusinessId,
            self::KEY_ORIGIN_STATE_ID => (int) $state->id,
            self::KEY_ORIGIN_CITY_ID => (int) $city->id,
            self::KEY_ORIGIN_STATE_NAME => $stateName,
            self::KEY_ORIGIN_CITY_NAME => $cityName,
        ];
    }

    /**
     * @return array{
     *     merchant_business_id: string,
     *     origin_state_name: string,
     *     origin_city_name: string,
     *     origin_state_id?: int,
     *     origin_city_id?: int
     * }
     */
    public function requireFromAccount(SupplierCourierAccount $account): array
    {
        $meta = is_array($account->meta_json) ? $account->meta_json : [];

        $merchantBusinessId = trim((string) ($meta[self::KEY_MERCHANT_BUSINESS_ID] ?? ''));
        $originStateName = trim((string) ($meta[self::KEY_ORIGIN_STATE_NAME] ?? ''));
        $originCityName = trim((string) ($meta[self::KEY_ORIGIN_CITY_NAME] ?? ''));

        $errors = [];

        if ($merchantBusinessId === '') {
            $errors['supplier_courier_account'][] = 'ROYAL merchant business ID is not configured for this supplier account.';
        }

        if ($originStateName === '') {
            $errors['supplier_courier_account'][] = 'ROYAL origin state is not configured for this supplier account.';
        }

        if ($originCityName === '') {
            $errors['supplier_courier_account'][] = 'ROYAL origin city is not configured for this supplier account.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $result = [
            self::KEY_MERCHANT_BUSINESS_ID => $merchantBusinessId,
            self::KEY_ORIGIN_STATE_NAME => $originStateName,
            self::KEY_ORIGIN_CITY_NAME => $originCityName,
        ];

        if (isset($meta[self::KEY_ORIGIN_STATE_ID])) {
            $result[self::KEY_ORIGIN_STATE_ID] = (int) $meta[self::KEY_ORIGIN_STATE_ID];
        }

        if (isset($meta[self::KEY_ORIGIN_CITY_ID])) {
            $result[self::KEY_ORIGIN_CITY_ID] = (int) $meta[self::KEY_ORIGIN_CITY_ID];
        }

        return $result;
    }

    /**
     * Safe Admin presentation of non-secret metadata.
     *
     * @return array{
     *     merchant_business_id: string|null,
     *     origin_state_id: int|null,
     *     origin_city_id: int|null,
     *     origin_state_name: string|null,
     *     origin_city_name: string|null
     * }
     */
    public function present(?array $meta): array
    {
        $meta = is_array($meta) ? $meta : [];

        return [
            self::KEY_MERCHANT_BUSINESS_ID => $this->nullableString($meta[self::KEY_MERCHANT_BUSINESS_ID] ?? null),
            self::KEY_ORIGIN_STATE_ID => isset($meta[self::KEY_ORIGIN_STATE_ID]) ? (int) $meta[self::KEY_ORIGIN_STATE_ID] : null,
            self::KEY_ORIGIN_CITY_ID => isset($meta[self::KEY_ORIGIN_CITY_ID]) ? (int) $meta[self::KEY_ORIGIN_CITY_ID] : null,
            self::KEY_ORIGIN_STATE_NAME => $this->nullableString($meta[self::KEY_ORIGIN_STATE_NAME] ?? null),
            self::KEY_ORIGIN_CITY_NAME => $this->nullableString($meta[self::KEY_ORIGIN_CITY_NAME] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

