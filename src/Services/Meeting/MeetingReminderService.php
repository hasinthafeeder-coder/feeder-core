<?php

namespace Feeder\Core\Services\Meeting;

use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Enums\ResellerMeetingStatus;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\ResellerMeeting;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Notification\NotificationPublicationService;
use Feeder\Core\Services\Notification\PublishNotificationData;
use Feeder\Core\Services\Notification\ResellerUserNotificationRecipientResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sends 3-day and 1-day meeting reminders through the in-app notification foundation.
 *
 * Recipients are all active reseller users (attendance eligibility is not applied).
 *
 * Idempotent event keys:
 *   meet.rem.3d:{meetingId}:{userId}
 *   meet.rem.1d:{meetingId}:{userId}
 */
class MeetingReminderService
{
    public const OFFSET_THREE_DAYS = 3;

    public const OFFSET_ONE_DAY = 1;

    public function __construct(
        private readonly NotificationPublicationService $publisher,
        private readonly ResellerUserNotificationRecipientResolver $resellerUsers,
    ) {}

    /**
     * @return array{three_day: int, one_day: int, notifications: list<Notification>}
     */
    public function processDueReminders(User $actor, ?Carbon $now = null): array
    {
        $moment = ($now ?? Carbon::now())->copy();
        $created = [];
        $threeDay = 0;
        $oneDay = 0;

        foreach ($this->dueMeetingsForOffset($moment, self::OFFSET_THREE_DAYS) as $meeting) {
            foreach ($this->publishForMeeting($actor, $meeting, self::OFFSET_THREE_DAYS) as $notification) {
                $created[] = $notification;
                $threeDay++;
            }
        }

        foreach ($this->dueMeetingsForOffset($moment, self::OFFSET_ONE_DAY) as $meeting) {
            foreach ($this->publishForMeeting($actor, $meeting, self::OFFSET_ONE_DAY) as $notification) {
                $created[] = $notification;
                $oneDay++;
            }
        }

        return [
            'three_day' => $threeDay,
            'one_day' => $oneDay,
            'notifications' => $created,
        ];
    }

    /**
     * @return Collection<int, Notification>
     */
    public function publishForMeeting(User $actor, ResellerMeeting $meeting, int $daysBefore): Collection
    {
        if (! $meeting->isScheduled()) {
            return collect();
        }

        $offsetLabel = $daysBefore === self::OFFSET_THREE_DAYS ? '3d' : '1d';
        $notifications = collect();

        foreach ($this->resellerUsers->allEligibleResellerUsers() as $user) {
            $key = $this->eventKey($offsetLabel, (int) $meeting->id, (int) $user->id);

            $notification = $this->publisher->publishMeetingEvent(
                $actor,
                new PublishNotificationData(
                    type: NotificationType::MEETING_REMINDER,
                    title: $this->title($meeting, $daysBefore),
                    body: $this->body($meeting, $daysBefore),
                    audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
                    recipientUserIds: [(int) $user->id],
                    sourceEventType: 'meeting.reminder.'.$offsetLabel,
                    sourceEventKey: $key,
                    meetingId: (int) $meeting->id,
                ),
            );

            $notifications->push($notification);
        }

        return $notifications;
    }

    public function eventKey(string $offsetLabel, int $meetingId, int $userId): string
    {
        return 'meet.rem.'.$offsetLabel.':'.$meetingId.':'.$userId;
    }

    /**
     * @return Collection<int, ResellerMeeting>
     */
    private function dueMeetingsForOffset(Carbon $now, int $daysBefore): Collection
    {
        $targetDate = $now->copy()->startOfDay()->addDays($daysBefore)->toDateString();

        return ResellerMeeting::query()
            ->where('status', ResellerMeetingStatus::SCHEDULED->value)
            ->whereDate('scheduled_at', $targetDate)
            ->orderBy('id')
            ->get();
    }

    private function title(ResellerMeeting $meeting, int $daysBefore): string
    {
        return $daysBefore === self::OFFSET_THREE_DAYS
            ? 'Meeting in 3 days: '.$meeting->title
            : 'Meeting tomorrow: '.$meeting->title;
    }

    private function body(ResellerMeeting $meeting, int $daysBefore): string
    {
        $when = Carbon::parse($meeting->scheduled_at)->format('Y-m-d H:i');
        $location = $meeting->location ? ' Location: '.$meeting->location.'.' : '';

        return $daysBefore === self::OFFSET_THREE_DAYS
            ? 'Reminder: monthly reseller meeting "'.$meeting->title.'" is scheduled for '.$when.'.'.$location.' Bring your in-app QR check-in code.'
            : 'Final reminder: monthly reseller meeting "'.$meeting->title.'" is tomorrow at '.$when.'.'.$location.' Open Meetings in the reseller portal for your QR check-in code.';
    }
}
