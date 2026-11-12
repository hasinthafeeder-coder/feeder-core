<?php

namespace Feeder\Core\Services\Notification;

use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Models\File;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\NotificationRecipient;
use Feeder\Core\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Portal inbox reads and read-status updates for addressed recipients.
 *
 * Visibility is restricted to the actor's user id and company id, and only
 * notifications within their publish/expiry window are returned.
 */
class NotificationInboxService
{
    public const PERMISSION_VIEW = 'notifications.view';

    /**
     * @return Collection<int, NotificationRecipient>
     */
    public function visibleRecipientsFor(User $actor, ?Carbon $moment = null): Collection
    {
        return $this->visibleQuery($actor, $moment)
            ->orderByDesc('notifications.publish_at')
            ->orderByDesc('notification_recipients.id')
            ->get();
    }

    public function paginateVisibleFor(User $actor, int $perPage = 20, ?Carbon $moment = null): LengthAwarePaginator
    {
        return $this->visibleQuery($actor, $moment)
            ->orderByDesc('notifications.publish_at')
            ->orderByDesc('notification_recipients.id')
            ->paginate($perPage);
    }

    /**
     * Active dashboard banners for the actor: published, unexpired, addressed,
     * and backed by a valid NOTIFICATION_BANNER file. Text-only notices are omitted.
     *
     * @return Collection<int, NotificationRecipient>
     */
    public function activeBannerRecipientsFor(User $actor, ?Carbon $moment = null): Collection
    {
        return $this->visibleQuery($actor, $moment)
            ->whereNotNull('notifications.banner_file_uuid')
            ->where('notifications.banner_file_uuid', '!=', '')
            ->with(['notification.bannerFile', 'notification.product'])
            ->orderByDesc('notifications.publish_at')
            ->orderByDesc('notification_recipients.id')
            ->get()
            ->filter(function (NotificationRecipient $recipient): bool {
                $notification = $recipient->notification;
                $banner = $notification?->bannerFile;

                return $notification !== null
                    && $banner !== null
                    && (string) $banner->category === FileCategory::NOTIFICATION_BANNER->value
                    && (string) $banner->status === 'ACTIVE'
                    && $banner->deleted_at === null;
            })
            ->values();
    }

    public function findVisibleByNotificationUuid(
        User $actor,
        string $notificationUuid,
        ?Carbon $moment = null,
    ): NotificationRecipient {
        $uuid = strtoupper(trim($notificationUuid));

        $recipient = $this->visibleQuery($actor, $moment)
            ->where('notifications.uuid', $uuid)
            ->with(['notification.bannerFile', 'notification.product', 'notification.publisherCompany', 'deliveries'])
            ->first();

        if ($recipient === null) {
            throw ValidationException::withMessages([
                'notification' => ['The notification could not be found.'],
            ]);
        }

        return $recipient;
    }

    public function markRead(User $actor, string $recipientUuid, ?Carbon $moment = null): NotificationRecipient
    {
        $moment ??= Carbon::now();
        $uuid = strtoupper(trim($recipientUuid));

        return DB::transaction(function () use ($actor, $uuid, $moment): NotificationRecipient {
            $recipient = NotificationRecipient::query()
                ->with('notification')
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if ($recipient === null) {
                throw ValidationException::withMessages([
                    'recipient' => ['The notification could not be found.'],
                ]);
            }

            $this->assertOwnedByActor($actor, $recipient);

            $notification = $recipient->notification;

            if ($notification === null || ! $notification->isPublishedAt($moment)) {
                throw ValidationException::withMessages([
                    'recipient' => ['The notification is not currently available.'],
                ]);
            }

            if ($recipient->read_at === null) {
                $recipient->read_at = $moment;
                $recipient->save();
            }

            return $recipient->fresh(['notification']) ?? $recipient;
        });
    }

    public function markReadByNotificationUuid(
        User $actor,
        string $notificationUuid,
        ?Carbon $moment = null,
    ): NotificationRecipient {
        $recipient = $this->findVisibleByNotificationUuid($actor, $notificationUuid, $moment);

        return $this->markRead($actor, (string) $recipient->uuid, $moment);
    }

    /**
     * Record detail-page open. Also marks read/seen so opening the detail
     * updates unread counts. Does not invent delivery-channel statuses.
     */
    public function markOpenedByNotificationUuid(
        User $actor,
        string $notificationUuid,
        ?Carbon $moment = null,
    ): NotificationRecipient {
        $moment ??= Carbon::now();
        $recipient = $this->findVisibleByNotificationUuid($actor, $notificationUuid, $moment);

        return DB::transaction(function () use ($actor, $recipient, $moment): NotificationRecipient {
            $locked = NotificationRecipient::query()
                ->whereKey($recipient->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'recipient' => ['The notification could not be found.'],
                ]);
            }

            $this->assertOwnedByActor($actor, $locked);

            $changed = false;

            if ($locked->opened_at === null) {
                $locked->opened_at = $moment;
                $changed = true;
            }

            if ($locked->read_at === null) {
                $locked->read_at = $moment;
                $changed = true;
            }

            if ($changed) {
                $locked->save();
            }

            return $locked->fresh(['notification', 'deliveries']) ?? $locked;
        });
    }

    /**
     * Recent inbox preview rows for navbar dropdowns.
     *
     * @return Collection<int, NotificationRecipient>
     */
    public function recentVisibleFor(User $actor, int $limit = 5, ?Carbon $moment = null): Collection
    {
        $limit = max(1, min(20, $limit));

        return $this->visibleQuery($actor, $moment)
            ->orderByDesc('notifications.publish_at')
            ->orderByDesc('notification_recipients.id')
            ->limit($limit)
            ->get();
    }

    public function unreadCountFor(User $actor, ?Carbon $moment = null): int
    {
        return $this->visibleQuery($actor, $moment)
            ->whereNull('notification_recipients.read_at')
            ->count();
    }

    /**
     * Unread meeting notices for the Meetings sidebar badge.
     */
    public function unreadMeetingCountFor(User $actor, ?Carbon $moment = null): int
    {
        return $this->visibleQuery($actor, $moment)
            ->whereNull('notification_recipients.read_at')
            ->where('notifications.type', NotificationType::MEETING_REMINDER->value)
            ->count();
    }

    /**
     * Lightweight poll snapshot for open portal tabs.
     *
     * Does not create recipients or mutate read/opened state. Isolation remains
     * actor user id + company id via visibleQuery().
     *
     * @return array{
     *     unread_count: int,
     *     unread_meeting_count: int,
     *     latest_recipient_id: int,
     *     recipients: Collection<int, NotificationRecipient>
     * }
     */
    public function liveSnapshotFor(User $actor, int $recentLimit = 5, ?Carbon $moment = null): array
    {
        $recentLimit = max(1, min(20, $recentLimit));
        $moment ??= Carbon::now();

        $latestRecipientId = (int) ($this->visibleQuery($actor, $moment)
            ->max('notification_recipients.id') ?? 0);

        return [
            'unread_count' => $this->unreadCountFor($actor, $moment),
            'unread_meeting_count' => $this->unreadMeetingCountFor($actor, $moment),
            'latest_recipient_id' => $latestRecipientId,
            'recipients' => $this->recentVisibleFor($actor, $recentLimit, $moment),
        ];
    }

    /**
     * Portal file-proxy gate for NOTIFICATION_BANNER files.
     *
     * Non-banner files are left to existing auth-only proxy behaviour.
     * Banner files require the actor to be an addressed recipient of a
     * notification that references the file.
     */
    public function authorizeBannerFileAccess(User $actor, string $fileUuid): void
    {
        $fileUuid = strtoupper(trim($fileUuid));

        if ($fileUuid === '') {
            abort(404);
        }

        $file = File::query()->where('uuid', $fileUuid)->first();
        $referenced = Notification::query()
            ->where('banner_file_uuid', $fileUuid)
            ->exists();

        $isBanner = $referenced
            || ($file !== null && in_array((string) $file->category, [
                FileCategory::NOTIFICATION_BANNER->value,
                FileCategory::MEETING_BANNER->value,
            ], true));

        if (! $isBanner) {
            return;
        }

        if ($file !== null && (string) $file->category === FileCategory::MEETING_BANNER->value) {
            return;
        }

        if ($file === null
            || (string) $file->category !== FileCategory::NOTIFICATION_BANNER->value
            || (string) $file->status !== 'ACTIVE'
        ) {
            abort(403);
        }

        $companyId = (int) $actor->company_id;

        if ($companyId <= 0) {
            abort(403);
        }

        $allowed = NotificationRecipient::query()
            ->join('notifications', 'notifications.id', '=', 'notification_recipients.notification_id')
            ->forUserCompany((int) $actor->id, $companyId)
            ->where('notifications.banner_file_uuid', $fileUuid)
            ->exists();

        if (! $allowed) {
            abort(403);
        }
    }

    /**
     * @return Builder<NotificationRecipient>
     */
    private function visibleQuery(User $actor, ?Carbon $moment = null): Builder
    {
        $companyId = (int) $actor->company_id;

        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'actor' => ['The user must belong to a company to access notifications.'],
            ]);
        }

        $moment ??= Carbon::now();

        return NotificationRecipient::query()
            ->select('notification_recipients.*')
            ->with([
                'notification.bannerFile',
                'notification.meeting',
                'notification.publisherCompany',
                'deliveries',
            ])
            ->join('notifications', 'notifications.id', '=', 'notification_recipients.notification_id')
            ->forUserCompany((int) $actor->id, $companyId)
            ->where('notifications.publish_at', '<=', $moment)
            ->where(function (Builder $query) use ($moment): void {
                $query->whereNull('notifications.expires_at')
                    ->orWhere('notifications.expires_at', '>', $moment);
            });
    }

    private function assertOwnedByActor(User $actor, NotificationRecipient $recipient): void
    {
        $companyId = (int) $actor->company_id;

        if ((int) $recipient->recipient_user_id !== (int) $actor->id
            || (int) $recipient->recipient_company_id !== $companyId
        ) {
            throw ValidationException::withMessages([
                'recipient' => ['You are not allowed to access this notification.'],
            ]);
        }
    }
}
