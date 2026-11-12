<?php

namespace Feeder\Core\Events;

/**
 * Raised after a notification record and its recipient/delivery rows are committed.
 *
 * Payload stays minimal: listeners should load the notification by id when needed.
 */
class NotificationCreated
{
    public function __construct(
        public readonly int $notificationId,
        public readonly string $notificationUuid,
        public readonly ?string $sourceEventKey = null,
    ) {
    }
}
