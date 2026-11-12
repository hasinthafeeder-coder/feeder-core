<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ResellerMeetingStatus;
use Feeder\Core\Models\File;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Meeting banner catalog + file-proxy authorization for reseller portals.
 *
 * Dashboard slider banners remain attendance-eligibility gated.
 * File-proxy access for MEETING_BANNER images matches Meetings page visibility:
 * any active reseller company may load a banner referenced by a meeting.
 * Attendance eligibility is not required to view the image.
 */
class MeetingBannerService
{
    public function __construct(
        private readonly MeetingEligibilityService $eligibility,
    ) {}

    /**
     * @return Collection<int, ResellerMeeting>
     */
    public function activeBannersForReseller(User $actor, ?Carbon $moment = null): Collection
    {
        $moment ??= Carbon::now();
        $company = $actor->company;

        if ($company === null) {
            return collect();
        }

        return ResellerMeeting::query()
            ->with('bannerFile')
            ->where('status', ResellerMeetingStatus::SCHEDULED->value)
            ->whereNotNull('banner_file_uuid')
            ->where('banner_file_uuid', '!=', '')
            ->where('scheduled_at', '>', $moment)
            ->orderBy('scheduled_at')
            ->get()
            ->filter(function (ResellerMeeting $meeting) use ($company): bool {
                if (! $this->eligibility->isCompanyEligibleForMeeting($company, $meeting)) {
                    return false;
                }

                $banner = $meeting->bannerFile;

                return $banner !== null
                    && (string) $banner->category === FileCategory::MEETING_BANNER->value
                    && (string) $banner->status === 'ACTIVE'
                    && $banner->deleted_at === null;
            })
            ->values();
    }

    public function authorizeBannerFileAccess(User $actor, string $fileUuid): void
    {
        $fileUuid = strtoupper(trim($fileUuid));
        if ($fileUuid === '') {
            abort(404);
        }

        $file = File::query()->where('uuid', $fileUuid)->first();
        $referenced = ResellerMeeting::query()
            ->where('banner_file_uuid', $fileUuid)
            ->exists();

        $isMeetingBanner = $referenced
            || ($file !== null && (string) $file->category === FileCategory::MEETING_BANNER->value);

        if (! $isMeetingBanner) {
            return;
        }

        if ($file === null
            || (string) $file->category !== FileCategory::MEETING_BANNER->value
            || (string) $file->status !== 'ACTIVE'
            || $file->deleted_at !== null
        ) {
            abort(403);
        }

        if (! $referenced) {
            abort(403);
        }

        $actor->loadMissing('company.portal');
        $company = $actor->company;

        if ($company === null || $company->trashed()) {
            abort(403);
        }

        $status = $company->status instanceof CompanyStatus
            ? $company->status
            : CompanyStatus::tryFrom((string) $company->status);

        if ($status !== CompanyStatus::ACTIVE) {
            abort(403);
        }

        if ($company->portal?->code !== PortalCode::RESELLER->value) {
            abort(403);
        }
    }
}
