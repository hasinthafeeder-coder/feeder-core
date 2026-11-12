<?php

namespace Feeder\Core\Services\Notification;

use DateTimeInterface;

/**
 * Editable notification fields after publication.
 *
 * Type, audience, recipients, and source-event identity stay immutable.
 */
final class UpdateNotificationData
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly DateTimeInterface|string $publishAt,
        public readonly DateTimeInterface|string|null $expiresAt = null,
        public readonly bool $removeBanner = false,
    ) {}
}
