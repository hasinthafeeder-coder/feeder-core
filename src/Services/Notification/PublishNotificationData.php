<?php

namespace Feeder\Core\Services\Notification;

use DateTimeInterface;
use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationType;

/**
 * Administrator-supplied notification fields.
 *
 * Recipient resolution, schedule defaults, banner validation, and delivery
 * rows are owned by NotificationPublicationService.
 */
final class PublishNotificationData
{
    /**
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
        public readonly NotificationAudienceType $audienceType,
        public readonly array $recipientUserIds = [],
        public readonly DateTimeInterface|string|null $publishAt = null,
        public readonly DateTimeInterface|string|null $expiresAt = null,
        public readonly ?string $bannerFileUuid = null,
        public readonly ?string $sourceEventType = null,
        public readonly ?string $sourceEventKey = null,
        public readonly ?int $meetingId = null,
    ) {}
}
