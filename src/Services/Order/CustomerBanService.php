<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\CustomerBanEventType;
use Feeder\Core\Enums\CustomerBanStatus;
use Feeder\Core\Models\Country;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\CustomerBan;
use Feeder\Core\Models\CustomerBanEvent;
use Feeder\Core\Models\CustomerPhone;
use Feeder\Core\Support\CustomerPhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Global customer ban domain service.
 *
 * Customers are global (not reseller-owned). Company/permission boundaries for
 * who may ban/lift belong to the authenticated application layer (permission
 * slugs). This service enforces ban state machine rules only.
 */
class CustomerBanService
{
    public function __construct(
        private readonly CustomerPhoneNormalizer $phoneNormalizer,
        private readonly CustomerIdentityService $customerIdentityService,
    ) {
    }

    public function ban(
        Customer $customer,
        int $bannedByUserId,
        ?int $bannedByCompanyId,
        ?string $reason = null,
    ): CustomerBan {
        return DB::transaction(function () use ($customer, $bannedByUserId, $bannedByCompanyId, $reason) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $existingActive = CustomerBan::query()
                ->where('customer_id', $customer->id)
                ->where('status', CustomerBanStatus::ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existingActive !== null) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Customer already has an active ban.'],
                ]);
            }

            $ban = CustomerBan::query()->create([
                'customer_id' => $customer->id,
                'status' => CustomerBanStatus::ACTIVE,
                'reason' => $reason,
                'banned_by_user_id' => $bannedByUserId,
                'banned_by_company_id' => $bannedByCompanyId,
                'banned_at' => now(),
            ]);

            CustomerBanEvent::query()->create([
                'customer_ban_id' => $ban->id,
                'customer_id' => $customer->id,
                'event_type' => CustomerBanEventType::BANNED,
                'actor_user_id' => $bannedByUserId,
                'actor_company_id' => $bannedByCompanyId,
                'reason' => $reason,
                'payload_json' => null,
            ]);

            $customer->update([
                'is_banned' => true,
                'updated_by' => $bannedByUserId,
            ]);

            return $ban->fresh(['events']);
        });
    }

    public function lift(
        CustomerBan $ban,
        int $liftedByUserId,
        ?int $liftedByCompanyId,
        ?string $liftReason = null,
    ): CustomerBan {
        return DB::transaction(function () use ($ban, $liftedByUserId, $liftedByCompanyId, $liftReason) {
            $ban = CustomerBan::query()->lockForUpdate()->findOrFail($ban->id);

            if ($ban->status !== CustomerBanStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'ban' => ['Only an active ban can be lifted.'],
                ]);
            }

            $ban->update([
                'status' => CustomerBanStatus::LIFTED,
                'lifted_by_user_id' => $liftedByUserId,
                'lifted_by_company_id' => $liftedByCompanyId,
                'lifted_at' => now(),
                'lift_reason' => $liftReason,
            ]);

            CustomerBanEvent::query()->create([
                'customer_ban_id' => $ban->id,
                'customer_id' => $ban->customer_id,
                'event_type' => CustomerBanEventType::LIFTED,
                'actor_user_id' => $liftedByUserId,
                'actor_company_id' => $liftedByCompanyId,
                'reason' => $liftReason,
                'payload_json' => null,
            ]);

            $stillActive = CustomerBan::query()
                ->where('customer_id', $ban->customer_id)
                ->where('status', CustomerBanStatus::ACTIVE)
                ->exists();

            Customer::query()->whereKey($ban->customer_id)->update([
                'is_banned' => $stillActive,
                'updated_by' => $liftedByUserId,
            ]);

            return $ban->fresh(['events']);
        });
    }

    /**
     * Resolve ban state from a raw phone number via normalize → phone → customer.
     *
     * @return array{
     *     found: bool,
     *     customer: ?Customer,
     *     phone: ?CustomerPhone,
     *     is_banned: bool,
     *     active_ban: ?CustomerBan
     * }
     */
    public function findBanByRawPhone(string $rawPhone, int $countryId): array
    {
        $country = Country::query()->find($countryId);

        if ($country === null) {
            throw ValidationException::withMessages([
                'country_id' => ['The selected country is invalid.'],
            ]);
        }

        $normalized = $this->phoneNormalizer->normalize($rawPhone, $country);
        $phone = $this->customerIdentityService->findByCountryAndNormalizedPhone(
            (int) $country->id,
            $normalized,
        );

        if ($phone === null) {
            return [
                'found' => false,
                'customer' => null,
                'phone' => null,
                'is_banned' => false,
                'active_ban' => null,
            ];
        }

        $customer = Customer::query()->find($phone->customer_id);
        $activeBan = null;

        if ($customer !== null) {
            $activeBan = CustomerBan::query()
                ->where('customer_id', $customer->id)
                ->where('status', CustomerBanStatus::ACTIVE)
                ->first();
        }

        return [
            'found' => true,
            'customer' => $customer,
            'phone' => $phone,
            'is_banned' => $customer?->is_banned === true || $activeBan !== null,
            'active_ban' => $activeBan,
        ];
    }
}
