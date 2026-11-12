<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Models\Company;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\ResellerMeetingCheckInToken;
use Feeder\Core\Models\User;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues opaque meeting-specific check-in tokens.
 *
 * Tokens are 64-char hex from random_bytes(32). QR payloads contain only the
 * token string — no personal data, company ids, or sequential identifiers.
 */
class MeetingCheckInTokenService
{
    public function __construct(
        private readonly MeetingEligibilityService $eligibility,
    ) {}

    public function issueForEligibleResellers(ResellerMeeting $meeting): int
    {
        if (! $meeting->isScheduled()) {
            return 0;
        }

        $issued = 0;

        foreach ($this->eligibility->eligibleResellerOwnersFor($meeting) as $user) {
            $company = $user->company;

            if ($company === null) {
                continue;
            }

            $this->ensureActiveToken($meeting, $company, $user);
            $issued++;
        }

        return $issued;
    }

    public function ensureActiveToken(
        ResellerMeeting $meeting,
        Company $company,
        User $user,
    ): ResellerMeetingCheckInToken {
        if (! $meeting->isScheduled()) {
            throw ValidationException::withMessages([
                'meeting' => ['Check-in tokens can only be issued for scheduled meetings.'],
            ]);
        }

        if (! $this->eligibility->isCompanyEligibleForMeeting($company, $meeting)) {
            throw ValidationException::withMessages([
                'company' => ['This reseller was not eligible for the selected meeting.'],
            ]);
        }

        return DB::transaction(function () use ($meeting, $company, $user): ResellerMeetingCheckInToken {
            $existing = ResellerMeetingCheckInToken::query()
                ->where('meeting_id', $meeting->id)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isActive()) {
                if ((int) $existing->user_id !== (int) $user->id) {
                    $existing->forceFill(['user_id' => $user->id])->save();
                }

                return $existing->fresh() ?? $existing;
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'user_id' => $user->id,
                    'token' => $this->generateToken(),
                    'issued_at' => now(),
                    'revoked_at' => null,
                ])->save();

                return $existing->fresh() ?? $existing;
            }

            return ResellerMeetingCheckInToken::query()->create([
                'uuid' => UuidService::generate(),
                'meeting_id' => $meeting->id,
                'company_id' => $company->id,
                'user_id' => $user->id,
                'token' => $this->generateToken(),
                'issued_at' => now(),
            ]);
        });
    }

    public function findActiveByToken(string $token): ?ResellerMeetingCheckInToken
    {
        $normalized = strtolower(trim($token));

        if ($normalized === '' || ! preg_match('/^[a-f0-9]{64}$/', $normalized)) {
            return null;
        }

        return ResellerMeetingCheckInToken::query()
            ->where('token', $normalized)
            ->whereNull('revoked_at')
            ->with(['meeting', 'company', 'user'])
            ->first();
    }

    public function activeTokenForCompany(ResellerMeeting $meeting, Company $company): ?ResellerMeetingCheckInToken
    {
        return ResellerMeetingCheckInToken::query()
            ->where('meeting_id', $meeting->id)
            ->where('company_id', $company->id)
            ->whereNull('revoked_at')
            ->first();
    }

    public function revokeAllForMeeting(ResellerMeeting $meeting): void
    {
        ResellerMeetingCheckInToken::query()
            ->where('meeting_id', $meeting->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (
            ResellerMeetingCheckInToken::query()->where('token', $token)->exists()
        );

        return $token;
    }

    public function assertTokenIsUnpredictable(string $token): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        if (ctype_digit($token) || preg_match('/^(0+|1+|a+|f+)$/i', $token)) {
            return false;
        }

        return true;
    }
}
