<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Enums\MeetingExcuseStatus;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\ResellerMeetingExcuse;
use Feeder\Core\Models\User;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingExcuseService
{
    public const PERMISSION_MANAGE = 'meetings.excuses.manage';

    public function __construct(
        private readonly MeetingEligibilityService $eligibility,
    ) {}

    public function approve(
        User $actor,
        ResellerMeeting $meeting,
        Company $company,
        string $reason,
    ): ResellerMeetingExcuse {
        $normalizedReason = trim($reason);

        if ($normalizedReason === '') {
            throw ValidationException::withMessages([
                'reason' => ['An auditable excuse reason is required.'],
            ]);
        }

        if ($meeting->isCancelled()) {
            throw ValidationException::withMessages([
                'meeting' => ['Excuses cannot be recorded for cancelled meetings.'],
            ]);
        }

        if (! $this->eligibility->isCompanyEligibleForMeeting($company, $meeting)) {
            throw ValidationException::withMessages([
                'company' => ['This reseller was not eligible for the selected meeting.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $meeting, $company, $normalizedReason): ResellerMeetingExcuse {
            $existing = ResellerMeetingExcuse::query()
                ->where('meeting_id', $meeting->id)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'reason' => $normalizedReason,
                    'status' => MeetingExcuseStatus::APPROVED->value,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ])->save();

                return $existing->fresh(['approvedBy', 'company', 'meeting']) ?? $existing;
            }

            return ResellerMeetingExcuse::query()->create([
                'uuid' => UuidService::generate(),
                'meeting_id' => $meeting->id,
                'company_id' => $company->id,
                'reason' => $normalizedReason,
                'status' => MeetingExcuseStatus::APPROVED->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'created_by' => $actor->id,
            ])->load(['approvedBy', 'company', 'meeting']);
        });
    }

    public function findApproved(ResellerMeeting $meeting, Company $company): ?ResellerMeetingExcuse
    {
        return ResellerMeetingExcuse::query()
            ->where('meeting_id', $meeting->id)
            ->where('company_id', $company->id)
            ->where('status', MeetingExcuseStatus::APPROVED->value)
            ->first();
    }
}
