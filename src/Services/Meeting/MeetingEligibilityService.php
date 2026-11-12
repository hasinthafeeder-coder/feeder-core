<?php

namespace Feeder\Core\Services\Meeting;

use DateTimeInterface;
use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Meeting attendance / QR eligibility for active reseller companies.
 *
 * Rule: every ACTIVE reseller company is eligible for every meeting.
 * `approved_at` is retained only as an informational joining date when present;
 * it is not required for QR issuance, check-in, excuses, or absence scope.
 */
class MeetingEligibilityService
{
    public const ABSENCE_REVIEW_THRESHOLD = 3;

    public function eligibleFrom(Company $company): ?Carbon
    {
        if ($company->approved_at === null) {
            return null;
        }

        return Carbon::parse($company->approved_at)->startOfDay();
    }

    public function isCompanyEligibleForMeeting(Company $company, ResellerMeeting|DateTimeInterface|string $meeting): bool
    {
        return $this->isActiveResellerCompany($company);
    }

    /**
     * Eligible reseller company owners for a scheduled meeting.
     *
     * @return Collection<int, User>
     */
    public function eligibleResellerOwnersFor(ResellerMeeting $meeting): Collection
    {
        $companies = $this->eligibleCompaniesFor($meeting);
        $ownerIds = $companies
            ->pluck('owner_user_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ownerIds === []) {
            return collect();
        }

        $owners = User::query()
            ->whereNull('users.deleted_at')
            ->where('users.status', UserStatus::ACTIVE->value)
            ->whereIn('users.id', $ownerIds)
            ->with('company')
            ->orderBy('users.id')
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->id);

        return $companies
            ->map(static function (Company $company) use ($owners): ?User {
                $ownerId = (int) $company->owner_user_id;

                return $owners->get($ownerId);
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, Company>
     */
    public function eligibleCompaniesFor(ResellerMeeting $meeting): Collection
    {
        $resellerPortalId = DB::table('portals')
            ->where('code', PortalCode::RESELLER->value)
            ->value('id');

        if (! $resellerPortalId) {
            return collect();
        }

        return Company::query()
            ->whereNull('companies.deleted_at')
            ->where('companies.status', CompanyStatus::ACTIVE->value)
            ->where('companies.portal_id', $resellerPortalId)
            ->whereNotNull('companies.owner_user_id')
            ->with('owner')
            ->orderBy('companies.id')
            ->get();
    }

    public function isActiveResellerCompany(Company $company): bool
    {
        if ($company->trashed()) {
            return false;
        }

        $company->loadMissing('portal');

        if ($company->portal?->code !== PortalCode::RESELLER->value) {
            return false;
        }

        $status = $company->status;

        if ($status instanceof CompanyStatus) {
            return $status === CompanyStatus::ACTIVE;
        }

        return $status === CompanyStatus::ACTIVE->value;
    }

    private function meetingDate(ResellerMeeting|DateTimeInterface|string $meeting): Carbon
    {
        if ($meeting instanceof ResellerMeeting) {
            return Carbon::parse($meeting->scheduled_at)->startOfDay();
        }

        return Carbon::parse($meeting)->startOfDay();
    }
}
