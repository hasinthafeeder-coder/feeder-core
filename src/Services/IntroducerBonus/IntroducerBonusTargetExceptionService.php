<?php

namespace Feeder\Core\Services\IntroducerBonus;

use Feeder\Core\Models\IntroducerBonusEvent;
use Feeder\Core\Models\User;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enables or clears a reseller's monthly own-order target bypass.
 * The flag does not change the introducer bonus rate or formula.
 */
class IntroducerBonusTargetExceptionService
{
    public function update(User $reseller, bool $enabled, User $actor): User
    {
        $reseller->loadMissing('company.portal');

        if (! $reseller->company?->isResellerCompany()) {
            throw ValidationException::withMessages([
                'user' => 'Introducer bonus target exceptions can only be set on reseller profiles.',
            ]);
        }

        if ($actor->id === null) {
            throw ValidationException::withMessages([
                'actor' => 'An administrator is required to change the introducer bonus target exception.',
            ]);
        }

        return DB::transaction(function () use ($reseller, $enabled, $actor): User {
            $locked = User::query()->whereKey($reseller->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'user' => 'The reseller could not be found.',
                ]);
            }

            $current = (bool) $locked->introducer_bonus_target_exception;

            if ($current === $enabled) {
                return $locked;
            }

            $locked->forceFill([
                'introducer_bonus_target_exception' => $enabled,
                'introducer_bonus_target_exception_updated_by' => $actor->id,
                'introducer_bonus_target_exception_updated_at' => now(),
            ])->save();

            IntroducerBonusEvent::query()->create([
                'uuid' => UuidService::generate(),
                'event_type' => IntroducerBonusEvent::TYPE_TARGET_EXCEPTION_UPDATED,
                'batch_id' => null,
                'subject_user_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'payload' => [
                    'enabled' => $enabled,
                    'previous' => $current,
                ],
            ]);

            return $locked->fresh();
        });
    }
}
