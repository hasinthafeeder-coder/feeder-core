<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ResellerMeetingStatus;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\ResellerMeetingAttendance;
use Feeder\Core\Models\ResellerMeetingCheckInToken;
use Feeder\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reseller portal meeting catalog and per-company QR resolution.
 *
 * Active reseller companies may browse meetings and receive/ensure their own
 * company-scoped QR token for scheduled meetings. Tokens are never shared
 * across companies.
 */
class ResellerMeetingPortalService
{
    public function __construct(
        private readonly MeetingCheckInTokenService $tokens,
        private readonly MeetingEligibilityService $eligibility,
    ) {}

    /**
     * @return Collection<int, ResellerMeeting>
     */
    public function visibleMeetingsFor(User $actor): Collection
    {
        $this->assertActiveResellerActor($actor);

        return ResellerMeeting::query()
            ->with('bannerFile')
            ->whereIn('status', [
                ResellerMeetingStatus::SCHEDULED->value,
                ResellerMeetingStatus::CANCELLED->value,
            ])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->get();
    }

    public function findVisibleMeetingOrFail(User $actor, ResellerMeeting $meeting): ResellerMeeting
    {
        $this->assertActiveResellerActor($actor);

        $meeting->loadMissing('bannerFile');

        return $meeting;
    }

    /**
     * Active QR token for the actor's company only.
     * Issues a token when the meeting is scheduled and the company is eligible.
     */
    public function ownActiveToken(User $actor, ResellerMeeting $meeting): ?ResellerMeetingCheckInToken
    {
        $company = $this->assertActiveResellerActor($actor);

        if (! $meeting->isScheduled()) {
            return $this->ownExistingToken($meeting, $company);
        }

        if (! $this->eligibility->isCompanyEligibleForMeeting($company, $meeting)) {
            return null;
        }

        $token = $this->tokens->ensureActiveToken($meeting, $company, $actor);

        if ((int) $token->company_id !== (int) $company->id) {
            return null;
        }

        return $token;
    }

    public function attendanceFor(User $actor, ResellerMeeting $meeting): ?ResellerMeetingAttendance
    {
        $company = $this->assertActiveResellerActor($actor);

        return ResellerMeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->where('company_id', $company->id)
            ->first();
    }

    public function isAttendanceEligible(User $actor, ResellerMeeting $meeting): bool
    {
        $company = $this->assertActiveResellerActor($actor);

        return $this->eligibility->isCompanyEligibleForMeeting($company, $meeting);
    }

    /**
     * @return array{
     *     meeting: ResellerMeeting,
     *     token: ResellerMeetingCheckInToken|null,
     *     attendance: ResellerMeetingAttendance|null,
     *     attendanceEligible: bool
     * }
     */
    public function showPayloadFor(User $actor, ResellerMeeting $meeting): array
    {
        $meeting = $this->findVisibleMeetingOrFail($actor, $meeting);

        return [
            'meeting' => $meeting,
            'token' => $this->ownActiveToken($actor, $meeting),
            'attendance' => $this->attendanceFor($actor, $meeting),
            'attendanceEligible' => $this->isAttendanceEligible($actor, $meeting),
        ];
    }

    /**
     * @return array{
     *     meetings: Collection<int, ResellerMeeting>,
     *     tokensByMeeting: array<int, ResellerMeetingCheckInToken|null>,
     *     attendanceByMeeting: array<int, ResellerMeetingAttendance|null>,
     *     attendanceEligibleByMeeting: array<int, bool>,
     *     eligibleFrom: \Illuminate\Support\Carbon|null
     * }
     */
    public function indexPayloadFor(User $actor): array
    {
        $company = $this->assertActiveResellerActor($actor);
        $meetings = $this->visibleMeetingsFor($actor);

        $tokensByMeeting = [];
        $attendanceByMeeting = [];
        $attendanceEligibleByMeeting = [];

        foreach ($meetings as $meeting) {
            $id = (int) $meeting->id;
            $tokensByMeeting[$id] = $this->ownActiveToken($actor, $meeting);
            $attendanceByMeeting[$id] = $this->attendanceFor($actor, $meeting);
            $attendanceEligibleByMeeting[$id] = $this->eligibility
                ->isCompanyEligibleForMeeting($company, $meeting);
        }

        return [
            'meetings' => $meetings,
            'tokensByMeeting' => $tokensByMeeting,
            'attendanceByMeeting' => $attendanceByMeeting,
            'attendanceEligibleByMeeting' => $attendanceEligibleByMeeting,
            'eligibleFrom' => $this->eligibility->eligibleFrom($company),
        ];
    }

    private function ownExistingToken(ResellerMeeting $meeting, Company $company): ?ResellerMeetingCheckInToken
    {
        $token = $this->tokens->activeTokenForCompany($meeting, $company);

        if ($token === null || (int) $token->company_id !== (int) $company->id) {
            return null;
        }

        return $token;
    }

    private function assertActiveResellerActor(User $actor): Company
    {
        $actor->loadMissing('company.portal');
        $company = $actor->company;

        if ($company === null || $company->trashed()) {
            throw ValidationException::withMessages([
                'actor' => ['The reseller company could not be found.'],
            ]);
        }

        $status = $company->status instanceof CompanyStatus
            ? $company->status
            : CompanyStatus::tryFrom((string) $company->status);

        if ($status !== CompanyStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'actor' => ['The reseller company is not active.'],
            ]);
        }

        if ($company->portal?->code !== PortalCode::RESELLER->value) {
            throw ValidationException::withMessages([
                'actor' => ['Only reseller users can access meetings.'],
            ]);
        }

        return $company;
    }
}
