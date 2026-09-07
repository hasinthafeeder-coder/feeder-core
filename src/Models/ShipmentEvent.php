<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShipmentEvent extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'shipment_id',
        'external_status',
        'normalized_status',
        'description',
        'event_at',
        'raw_response',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'raw_response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShipmentEvent $event): void {
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

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
