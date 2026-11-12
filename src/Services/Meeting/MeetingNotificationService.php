<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Notification\NotificationPublicationService;
use Feeder\Core\Services\Notification\PublishNotificationData;
use Feeder\Core\Services\Notification\ResellerUserNotificationRecipientResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Publishes per-reseller meeting notices when a meeting is created/scheduled.
 *
 * Recipients are all active reseller users (not attendance-eligibility filtered).
 * QR check-in tokens remain gated by MeetingEligibilityService / token workflow.
 *
 * Idempotent event keys: meet.pub:{meetingId}:{userId}
 * Suppliers are never notified about reseller QR tokens.
 * 3-day / 1-day reminders remain separate (meet.rem.*) via MeetingReminderService.
 */
class MeetingNotificationService
{
    public function __construct(
        private readonly NotificationPublicationService $publisher,
        private readonly ResellerUserNotificationRecipientResolver $resellerUsers,
    ) {}

    /**
     * @return Collection<int, Notification>
     */
    public function publishCreatedMeeting(User $actor, ResellerMeeting $meeting): Collection
    {
        if (! $meeting->isScheduled()) {
            return collect();
        }

        $notifications = collect();
        $when = Carbon::parse($meeting->scheduled_at)->format('Y-m-d H:i');
        $location = $meeting->location ? ' Location: '.$meeting->location.'.' : '';
        $publishAt = Carbon::now();
        $expiresAt = Carbon::parse($meeting->scheduled_at);

        // Keep create-time notices immediately visible; never fail the publish
        // schedule when scheduled_at is too close to "now".
        if ($expiresAt->lte($publishAt)) {
            $expiresAt = $publishAt->copy()->addMinute();
        }

        foreach ($this->resellerUsers->allEligibleResellerUsers() as $user) {
            $key = $this->eventKey((int) $meeting->id, (int) $user->id);

            $notification = $this->publisher->publishMeetingEvent(
                $actor,
                new PublishNotificationData(
                    type: NotificationType::MEETING_REMINDER,
                    title: 'Meeting scheduled: '.$meeting->title,
                    body: 'Monthly reseller meeting "'.$meeting->title.'" is scheduled for '.$when.'.'
                        .$location
                        .' Open this notice or Meetings to view your unique QR check-in code.',
                    audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
                    recipientUserIds: [(int) $user->id],
                    publishAt: $publishAt,
                    expiresAt: $expiresAt,
                    sourceEventType: 'meeting.published',
                    sourceEventKey: $key,
                    meetingId: (int) $meeting->id,
                ),
            );

            $notifications->push($notification);
        }

        return $notifications;
    }

    public function eventKey(int $meetingId, int $userId): string
    {
        return 'meet.pub:'.$meetingId.':'.$userId;
    }
}
