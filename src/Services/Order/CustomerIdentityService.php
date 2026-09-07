<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Exceptions\ConflictingCustomerIdentityException;
use Feeder\Core\Models\Country;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\CustomerPhone;
use Feeder\Core\Support\CustomerPhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerIdentityService
{
    public function __construct(
        private readonly CustomerPhoneNormalizer $phoneNormalizer,
    ) {
    }

    /**
     * Resolve or create a global customer identity from one or two phones.
     *
     * @param  array{
     *     display_name: string,
     *     primary_country_id: int,
     *     primary_phone: string,
     *     primary_phone_country_id: int,
     *     secondary_phone?: string|null,
     *     secondary_phone_country_id?: int|null,
     *     created_by?: int|null
     * }  $payload
     * @return array{customer: Customer, primary_phone: CustomerPhone, secondary_phone: ?CustomerPhone}
     */
    public function resolveOrCreate(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $primaryCountry = $this->findCountry((int) $payload['primary_phone_country_id']);
            $primaryNormalized = $this->phoneNormalizer->normalize($payload['primary_phone'], $primaryCountry);

            $primaryExisting = CustomerPhone::query()
                ->where('country_id', $primaryCountry->id)
                ->where('normalized_phone', $primaryNormalized)
                ->lockForUpdate()
                ->first();

            $secondaryPhone = null;
            $secondaryExisting = null;
            $secondaryNormalized = null;
            $secondaryCountry = null;

            if (! empty($payload['secondary_phone'])) {
                $secondaryCountryId = (int) ($payload['secondary_phone_country_id'] ?? $payload['primary_phone_country_id']);
                $secondaryCountry = $this->findCountry($secondaryCountryId);
                $secondaryNormalized = $this->phoneNormalizer->normalize($payload['secondary_phone'], $secondaryCountry);

                $secondaryExisting = CustomerPhone::query()
                    ->where('country_id', $secondaryCountry->id)
                    ->where('normalized_phone', $secondaryNormalized)
                    ->lockForUpdate()
                    ->first();
            }

            if ($primaryExisting && $secondaryExisting && (int) $primaryExisting->customer_id !== (int) $secondaryExisting->customer_id) {
                throw new ConflictingCustomerIdentityException(
                    (int) $primaryExisting->customer_id,
                    (int) $secondaryExisting->customer_id,
                );
            }

            $customer = null;

            if ($primaryExisting) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($primaryExisting->customer_id);
            } elseif ($secondaryExisting) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($secondaryExisting->customer_id);
            } else {
                $customer = Customer::query()->create([
                    'display_name' => $payload['display_name'],
                    'primary_country_id' => (int) $payload['primary_country_id'],
                    'is_banned' => false,
                    'created_by' => $payload['created_by'] ?? null,
                    'updated_by' => $payload['created_by'] ?? null,
                ]);
            }

            $primaryPhone = $primaryExisting ?? CustomerPhone::query()->create([
                'customer_id' => $customer->id,
                'country_id' => $primaryCountry->id,
                'normalized_phone' => $primaryNormalized,
                'raw_phone' => $payload['primary_phone'],
                'label' => 'primary',
                'is_primary' => true,
            ]);

            if ($secondaryNormalized !== null && $secondaryCountry !== null && $secondaryExisting === null) {
                $secondaryPhone = CustomerPhone::query()->create([
                    'customer_id' => $customer->id,
                    'country_id' => $secondaryCountry->id,
                    'normalized_phone' => $secondaryNormalized,
                    'raw_phone' => $payload['secondary_phone'],
                    'label' => 'secondary',
                    'is_primary' => false,
                ]);
            } elseif ($secondaryExisting !== null) {
                $secondaryPhone = $secondaryExisting;
            }

            if (! $primaryPhone->is_primary) {
                CustomerPhone::query()
                    ->where('customer_id', $customer->id)
                    ->update(['is_primary' => false]);

                $primaryPhone->update(['is_primary' => true]);
            }

            return [
                'customer' => $customer->fresh(['phones']),
                'primary_phone' => $primaryPhone->fresh(),
                'secondary_phone' => $secondaryPhone?->fresh(),
            ];
        });
    }

    public function findByCountryAndNormalizedPhone(int $countryId, string $normalizedPhone): ?CustomerPhone
    {
        return CustomerPhone::query()
            ->where('country_id', $countryId)
            ->where('normalized_phone', $normalizedPhone)
            ->first();
    }

    private function findCountry(int $countryId): Country
    {
        $country = Country::query()->find($countryId);

        if ($country === null) {
            throw ValidationException::withMessages([
                'country_id' => ['The selected country is invalid.'],
            ]);
        }

        return $country;
    }
}
