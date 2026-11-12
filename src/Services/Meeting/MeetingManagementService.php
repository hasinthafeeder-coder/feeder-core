<?php

namespace Feeder\Core\Services\Meeting;

use DateTimeInterface;
use Feeder\Core\Enums\ApplicationType;
use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Enums\ResellerMeetingStatus;
use Feeder\Core\Models\File;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\User;
use Feeder\Core\Services\FileService;
use Feeder\Core\Services\UuidService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeetingManagementService
{
    public const PERMISSION_VIEW = 'meetings.view';

    public const PERMISSION_MANAGE = 'meetings.manage';

    public function __construct(
        private readonly MeetingCheckInTokenService $tokens,
        private readonly MeetingNotificationService $meetingNotifications,
        private readonly FileService $files,
    ) {}

    public function create(
        User $actor,
        string $title,
        DateTimeInterface|string $scheduledAt,
        ?string $description = null,
        ?string $location = null,
        ?UploadedFile $banner = null,
        bool $removeBanner = false,
        ?string $bannerFileUuid = null,
    ): ResellerMeeting {
        $scheduled = Carbon::parse($scheduledAt);
        $calendarMonth = $scheduled->format('Y-m');
        $resolvedBanner = $this->resolveBannerUuid($actor, $banner, $removeBanner, $bannerFileUuid, null);

        return DB::transaction(function () use (
            $actor,
            $title,
            $scheduled,
            $calendarMonth,
            $description,
            $location,
            $resolvedBanner,
        ): ResellerMeeting {
            $this->assertNoActiveMeetingInMonth($calendarMonth);

            $meeting = ResellerMeeting::query()->create([
                'uuid' => UuidService::generate(),
                'title' => trim($title),
                'description' => $description !== null ? trim($description) : null,
                'scheduled_at' => $scheduled,
                'location' => $location !== null ? trim($location) : null,
                'calendar_month' => $calendarMonth,
                'active_month_key' => $calendarMonth,
                'status' => ResellerMeetingStatus::SCHEDULED->value,
                'banner_file_uuid' => $resolvedBanner,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            // Tokens must exist before create-time notices so QR detail pages can resolve them.
            $this->tokens->issueForEligibleResellers($meeting);

            $published = $meeting->fresh(['checkInTokens', 'creator', 'bannerFile']) ?? $meeting;

            // Same transaction as meeting + tokens: eligible inbox rows commit together,
            // and a notification failure rolls the meeting back instead of leaving orphans.
            $this->meetingNotifications->publishCreatedMeeting($actor, $published);

            return $published->fresh(['checkInTokens', 'creator', 'bannerFile']) ?? $published;
        });
    }

    public function update(
        ResellerMeeting $meeting,
        User $actor,
        string $title,
        DateTimeInterface|string $scheduledAt,
        ?string $description = null,
        ?string $location = null,
        ?UploadedFile $banner = null,
        bool $removeBanner = false,
    ): ResellerMeeting {
        if ($meeting->isCancelled()) {
            throw ValidationException::withMessages([
                'meeting' => ['Cancelled meetings cannot be edited.'],
            ]);
        }

        $scheduled = Carbon::parse($scheduledAt);
        $calendarMonth = $scheduled->format('Y-m');
        $resolvedBanner = $this->resolveBannerUuid(
            $actor,
            $banner,
            $removeBanner,
            null,
            $meeting->banner_file_uuid,
        );

        return DB::transaction(function () use (
            $meeting,
            $actor,
            $title,
            $scheduled,
            $calendarMonth,
            $description,
            $location,
            $resolvedBanner,
        ): ResellerMeeting {
            $locked = ResellerMeeting::query()
                ->whereKey($meeting->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isCancelled()) {
                throw ValidationException::withMessages([
                    'meeting' => ['Cancelled meetings cannot be edited.'],
                ]);
            }

            if ($calendarMonth !== (string) $locked->calendar_month) {
                $this->assertNoActiveMeetingInMonth($calendarMonth, (int) $locked->id);
            }

            $previousMonth = (string) $locked->calendar_month;

            $locked->forceFill([
                'title' => trim($title),
                'description' => $description !== null ? trim($description) : null,
                'scheduled_at' => $scheduled,
                'location' => $location !== null ? trim($location) : null,
                'calendar_month' => $calendarMonth,
                'active_month_key' => $calendarMonth,
                'banner_file_uuid' => $resolvedBanner,
                'updated_by' => $actor->id,
            ])->save();

            if ($previousMonth !== $calendarMonth) {
                $this->tokens->revokeAllForMeeting($locked);
            }

            $this->tokens->issueForEligibleResellers($locked->fresh() ?? $locked);

            return $locked->fresh(['checkInTokens', 'creator', 'updater', 'bannerFile']) ?? $locked;
        });
    }

    public function cancel(ResellerMeeting $meeting, User $actor, ?string $reason = null): ResellerMeeting
    {
        if ($meeting->isCancelled()) {
            return $meeting;
        }

        return DB::transaction(function () use ($meeting, $actor, $reason): ResellerMeeting {
            $locked = ResellerMeeting::query()
                ->whereKey($meeting->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'meeting' => ['The meeting could not be found.'],
                ]);
            }

            if ($locked->isCancelled()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => ResellerMeetingStatus::CANCELLED->value,
                'active_month_key' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason !== null ? trim($reason) : null,
                'updated_by' => $actor->id,
            ])->save();

            $this->tokens->revokeAllForMeeting($locked);

            return $locked->fresh(['canceller', 'creator']) ?? $locked;
        });
    }

    public function findByUuid(string $uuid): ?ResellerMeeting
    {
        return ResellerMeeting::query()->where('uuid', $uuid)->first();
    }

    private function resolveBannerUuid(
        User $actor,
        ?UploadedFile $banner,
        bool $removeBanner,
        ?string $incomingUuid,
        ?string $currentUuid,
    ): ?string {
        if ($banner !== null) {
            return $this->storeBanner($actor, $banner);
        }

        if ($removeBanner) {
            return null;
        }

        if ($incomingUuid !== null && trim($incomingUuid) !== '') {
            return $this->assertBannerFileUuid($incomingUuid);
        }

        return $currentUuid;
    }

    private function storeBanner(User $actor, UploadedFile $banner): string
    {
        $uploadedBy = is_string($actor->uuid) && strlen($actor->uuid) === 10
            ? $actor->uuid
            : null;

        try {
            $response = $this->files->upload(
                $banner,
                ApplicationType::ADMIN->value,
                'MEETING',
                UuidService::generate(),
                FileCategory::MEETING_BANNER->value,
                $uploadedBy,
                ['purpose' => 'meeting_banner'],
            );
        } catch (RequestException|ConnectionException|Throwable) {
            throw ValidationException::withMessages([
                'banner' => ['Unable to store the meeting banner. Try again.'],
            ]);
        }

        $uuid = strtoupper(trim((string) data_get($response, 'file.uuid')));

        if ($uuid === '' || preg_match('/^[A-Z0-9]{10}$/', $uuid) !== 1) {
            throw ValidationException::withMessages([
                'banner' => ['Unable to store the meeting banner. Try again.'],
            ]);
        }

        return $this->assertBannerFileUuid($uuid);
    }

    private function assertBannerFileUuid(string $bannerFileUuid): string
    {
        $uuid = strtoupper(trim($bannerFileUuid));

        if (strlen($uuid) !== 10) {
            throw ValidationException::withMessages([
                'banner_file_uuid' => ['Banner file UUID must be a 10-character public id.'],
            ]);
        }

        $file = File::query()
            ->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first();

        if ($file === null
            || (string) $file->category !== FileCategory::MEETING_BANNER->value
            || (string) $file->status !== 'ACTIVE'
        ) {
            throw ValidationException::withMessages([
                'banner_file_uuid' => ['Banner must reference an active MEETING_BANNER file.'],
            ]);
        }

        return $uuid;
    }

    private function assertNoActiveMeetingInMonth(string $calendarMonth, ?int $exceptMeetingId = null): void
    {
        $query = ResellerMeeting::query()
            ->where('active_month_key', $calendarMonth)
            ->where('status', ResellerMeetingStatus::SCHEDULED->value);

        if ($exceptMeetingId !== null) {
            $query->whereKeyNot($exceptMeetingId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['Only one active meeting is allowed per calendar month.'],
            ]);
        }
    }
}
