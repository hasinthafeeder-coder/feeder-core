<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\SupplierFulfillmentEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderFulfillmentEvent extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'order_id',
        'order_fulfillment_id',
        'event_type',
        'actor_user_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => SupplierFulfillmentEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderFulfillmentEvent $event): void {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }

            if ($event->created_at === null) {
                $event->created_at = now();
            }
        });

        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
