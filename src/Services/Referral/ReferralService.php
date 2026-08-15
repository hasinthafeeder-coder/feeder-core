<?php

namespace Feeder\Core\Services\Referral;

use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\ReferralCode;
use Feeder\Core\Models\ReferralRelationship;
use Feeder\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = strtoupper(Str::random(8));

            if (! ReferralCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique referral code.');
    }

    public function ensureUserHasReferralCode(User $user): ReferralCode
    {
        $referralCode = $user->referralCode()->first();

        if ($referralCode) {
            return $referralCode;
        }

        return DB::transaction(function () use ($user): ReferralCode {
            $existing = $user->referralCode()->first();

            if ($existing) {
                return $existing;
            }

            return $user->referralCode()->create([
                'uuid' => (string) Str::uuid(),
                'code' => $this->generateUniqueCode(),
                'is_active' => true,
                'user_id' => $user->id,
            ]);
        });
    }

    public function resolveCode(?string $code): ?ReferralCode
    {
        $normalized = trim((string) ($code ?? ''));

        if ($normalized === '') {
            return null;
        }

        return ReferralCode::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper($normalized)])
            ->first();
    }

    public function resolveReferralCode(?string $code): ?ReferralCode
    {
        return $this->resolveCode($code);
    }

    public function validateCode(?string $code, ?User $candidateUser = null): ReferralCode
    {
        $referralCode = $this->resolveCode($code);

        if ($referralCode === null) {
            throw ValidationException::withMessages([
                'ref' => 'Invalid referral code.',
            ]);
        }

        $parentUser = $referralCode->user;

        if (! $parentUser) {
            throw ValidationException::withMessages([
                'ref' => 'Invalid referral code.',
            ]);
        }

        if ($parentUser->user_type !== UserType::OWNER->value) {
            throw ValidationException::withMessages([
                'ref' => 'Referral code belongs to a non-reseller account.',
            ]);
        }

        if (! $referralCode->is_active) {
            throw ValidationException::withMessages([
                'ref' => 'This referral link is inactive.',
            ]);
        }

        if ($candidateUser && $parentUser->id === $candidateUser->id) {
            throw ValidationException::withMessages([
                'ref' => 'You cannot refer yourself.',
            ]);
        }

        return $referralCode;
    }

    public function validateReferralCode(?string $code, ?User $candidateUser = null): ReferralCode
    {
        return $this->validateCode($code, $candidateUser);
    }

    public function toggleActivation(User $user, bool $isActive, User $actor): ReferralCode
    {
        $referralCode = $user->referralCode()->firstOrFail();

        $referralCode->update([
            'is_active' => $isActive,
            'activated_by_user_id' => $isActive ? $actor->id : $referralCode->activated_by_user_id,
            'activated_at' => $isActive ? now() : $referralCode->activated_at,
            'deactivated_at' => $isActive ? $referralCode->deactivated_at : now(),
            'last_changed_by_user_id' => $actor->id,
        ]);

        return $referralCode->fresh();
    }

    public function createRelationship(User $childUser, string $code, ?User $actor = null): ReferralRelationship
    {
        $referralCode = $this->validateCode($code, $childUser);
        $parentUser = $referralCode->user;

        if ($parentUser === null) {
            throw ValidationException::withMessages([
                'ref' => 'Invalid referral code.',
            ]);
        }

        if ($parentUser->id === $childUser->id) {
            throw ValidationException::withMessages([
                'ref' => 'You cannot refer yourself.',
            ]);
        }

        return DB::transaction(function () use ($childUser, $parentUser, $referralCode, $actor): ReferralRelationship {
            if ($childUser->parentReseller()->exists()) {
                throw ValidationException::withMessages([
                    'ref' => 'This reseller already has a referral parent.',
                ]);
            }

            if ($this->wouldCreateCircularRelationship($childUser, $parentUser)) {
                throw ValidationException::withMessages([
                    'ref' => 'Circular referral relationships are not allowed.',
                ]);
            }

            $relationship = ReferralRelationship::query()->where('child_user_id', $childUser->id)->first();

            if ($relationship) {
                throw ValidationException::withMessages([
                    'ref' => 'This reseller already has a referral parent.',
                ]);
            }

            $newRelationship = ReferralRelationship::query()->create([
                'uuid' => (string) Str::uuid(),
                'parent_user_id' => $parentUser->id,
                'child_user_id' => $childUser->id,
                'source_referral_code_id' => $referralCode->id,
            ]);

            if ($actor) {
                $referralCode->update([
                    'last_changed_by_user_id' => $actor->id,
                ]);
            }

            return $newRelationship;
        });
    }

    public function createPermanentRelationship(User $childUser, string $code, ?User $actor = null): ReferralRelationship
    {
        return $this->createRelationship($childUser, $code, $actor);
    }

    public function wouldCreateCircularRelationship(User $childUser, User $parentUser): bool
    {
        $current = $parentUser;

        while ($current) {
            $ancestor = $current->parentReseller()->first();

            if ($ancestor === null) {
                return false;
            }

            $ancestorUser = $ancestor->parent;

            if ($ancestorUser && $ancestorUser->id === $childUser->id) {
                return true;
            }

            $current = $ancestorUser;
        }

        return false;
    }
}
