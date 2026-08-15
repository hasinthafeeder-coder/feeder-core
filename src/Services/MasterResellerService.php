<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Referral\ReferralService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterResellerService
{
    public function __construct(
        private readonly ReferralService $referralService,
    ) {}

    public function getMaster(): ?User
    {
        return User::query()
            ->where('is_master_reseller', true)
            ->where('user_type', UserType::OWNER->value)
            ->first();
    }

    public function setMaster(User $user): User
    {
        if ($user->user_type !== UserType::OWNER->value) {
            throw ValidationException::withMessages([
                'user_type' => 'Only regular reseller accounts can be marked as the master reseller.',
            ]);
        }

        return DB::transaction(function () use ($user): User {
            $existing = User::query()
                ->where('is_master_reseller', true)
                ->whereKeyNot($user->getKey())
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'is_master_reseller' => 'Only one master reseller may exist at a time.',
                ]);
            }

            $user->forceFill(['is_master_reseller' => true])->save();
            $this->referralService->ensureUserHasReferralCode($user);

            return $user->fresh();
        });
    }

    public function clearMaster(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user->forceFill(['is_master_reseller' => false])->save();

            return $user->fresh();
        });
    }
}
