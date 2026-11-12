<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Enums\MeetingCheckInMethod;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\ResellerMeetingAttendance;
use Feeder\Core\Models\ResellerMeetingCheckInToken;
use Feeder\Core\Models\User;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingCheckInService
{
    public const PERMISSION_CHECK_IN = 'meetings.check_in';

    public function __construct(
        private readonly MeetingEligibilityService $eligibility,
        private readonly MeetingCheckInTokenService $tokens,
    ) {}

    /**
     * @return array{attendance: ResellerMeetingAttendance, created: bool}
     */
    public function checkInByToken(User $staff, string $rawToken): array
    {
        $token = $this->tokens->findActiveByToken($rawToken);

        if ($token === null) {
            throw ValidationException::withMessages([
                'token' => ['The check-in token is invalid or revoked.'],
            ]);
        }

        $meeting = $token->meeting;

        if ($meeting === null || ! $meeting->isScheduled()) {
            throw ValidationException::withMessages([
                'meeting' => ['Attendance cannot be recorded for a cancelled meeting.'],
            ]);
        }

        $company = $token->company;
        $user = $token->user;

        if ($company === null || $user === null) {
            throw ValidationException::withMessages([
                'token' => ['The check-in token is not linked to a reseller.'],
            ]);
        }

        if (! $this->eligibility->isCompanyEligibleForMeeting($company, $meeting)) {
            throw ValidationException::withMessages([
                'company' => ['This reseller was not eligible for the selected meeting.'],
            ]);
        }

        return $this->recordAttendance(
            meeting: $meeting,
            company: $company,
            resellerUser: $user,
            staff: $staff,
            method: MeetingCheckInMethod::QR,
            token: $token,
        );
    }

    /**
     * @return array{attendance: ResellerMeetingAttendance, created: bool}
     */
    public function checkInManually(
        User $staff,
        ResellerMeeting $meeting,
        Company|User|string $resellerLookup,
    ): array {
        if (! $meeting->isScheduled()) {
            throw ValidationException::withMessages([
                'meeting' => ['Attendance cannot be recorded for a cancelled meeting.'],
            ]);
        }

        [$company, $user] = $this->resolveReseller($resellerLookup);

        if (! $this->eligibility->isCompanyEligibleForMeeting($company, $meeting)) {
            throw ValidationException::withMessages([
                'company' => ['This reseller was not eligible for the selected meeting.'],
            ]);
        }

        $token = $this->tokens->ensureActiveToken($meeting, $company, $user);

        return $this->recordAttendance(
            meeting: $meeting,
            company: $company,
            resellerUser: $user,
            staff: $staff,
            method: MeetingCheckInMethod::MANUAL,
            token: $token,
        );
    }

    public function findAttendance(ResellerMeeting $meeting, Company $company): ?ResellerMeetingAttendance
    {
        return ResellerMeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->where('company_id', $company->id)
            ->first();
    }

    /**
     * @return array{attendance: ResellerMeetingAttendance, created: bool}
     */
    private function recordAttendance(
        ResellerMeeting $meeting,
        Company $company,
        User $resellerUser,
        User $staff,
        MeetingCheckInMethod $method,
        ?ResellerMeetingCheckInToken $token,
    ): array {
        $existing = $this->findAttendance($meeting, $company);

        if ($existing !== null) {
            return ['attendance' => $existing, 'created' => false];
        }

        try {
            $attendance = DB::transaction(function () use (
                $meeting,
                $company,
                $resellerUser,
                $staff,
                $method,
                $token,
            ): ResellerMeetingAttendance {
                $locked = ResellerMeetingAttendance::query()
                    ->where('meeting_id', $meeting->id)
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked !== null) {
                    return $locked;
                }

                return ResellerMeetingAttendance::query()->create([
                    'uuid' => UuidService::generate(),
                    'meeting_id' => $meeting->id,
                    'company_id' => $company->id,
                    'user_id' => $resellerUser->id,
                    'checked_in_at' => now(),
                    'check_in_method' => $method->value,
                    'checked_in_by' => $staff->id,
                    'token_id' => $token?->id,
                ]);
            });

            return ['attendance' => $attendance, 'created' => true];
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                $existing = $this->findAttendance($meeting, $company);

                if ($existing !== null) {
                    return ['attendance' => $existing, 'created' => false];
                }
            }

            throw $exception;
        }
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function resolveReseller(Company|User|string $lookup): array
    {
        if ($lookup instanceof User) {
            $lookup->loadMissing('company.portal');
            $company = $lookup->company;

            if ($company === null || $company->portal?->code !== PortalCode::RESELLER->value) {
                throw ValidationException::withMessages([
                    'reseller' => ['The selected user is not a reseller.'],
                ]);
            }

            return [$company, $lookup];
        }

        if ($lookup instanceof Company) {
            $lookup->loadMissing(['portal', 'owner']);

            if ($lookup->portal?->code !== PortalCode::RESELLER->value) {
                throw ValidationException::withMessages([
                    'reseller' => ['The selected company is not a reseller company.'],
                ]);
            }

            $owner = $lookup->owner;

            if ($owner === null) {
                throw ValidationException::withMessages([
                    'reseller' => ['The reseller company has no owner user.'],
                ]);
            }

            return [$lookup, $owner];
        }

        $value = trim($lookup);

        if ($value === '') {
            throw ValidationException::withMessages([
                'reseller' => ['Provide a reseller company UUID, user UUID, email, or phone.'],
            ]);
        }

        $company = Company::query()
            ->where('uuid', $value)
            ->whereHas('portal', fn ($q) => $q->where('code', PortalCode::RESELLER->value))
            ->with('owner')
            ->first();

        if ($company !== null && $company->owner !== null) {
            return [$company, $company->owner];
        }

        $user = User::query()
            ->where(function ($query) use ($value): void {
                $query->where('uuid', $value)
                    ->orWhere('email', $value)
                    ->orWhere('phone', $value);
            })
            ->with('company.portal')
            ->first();

        if ($user === null || $user->company === null
            || $user->company->portal?->code !== PortalCode::RESELLER->value
        ) {
            throw ValidationException::withMessages([
                'reseller' => ['No matching reseller was found.'],
            ]);
        }

        return [$user->company, $user];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $errorCode = (string) ($exception->errorInfo[1] ?? '');

        return $errorCode === '1062'
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
