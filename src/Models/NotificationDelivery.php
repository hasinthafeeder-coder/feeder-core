<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\NotificationDeliveryChannel;
use Feeder\Core\Enums\NotificationDeliveryStatus;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $table = 'notification_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => NotificationDeliveryChannel::class,
            'status' => NotificationDeliveryStatus::class,
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NotificationDelivery $delivery): void {
            if (empty($delivery->uuid)) {
                $delivery->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'notification_recipient_id');
    }
}
