<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\StockReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class StockReservation extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_id',
        'order_item_id',
        'product_variant_id',
        'quantity',
        'status',
        'reserved_by',
        'reserved_at',
        'released_at',
        'released_by',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => StockReservationStatus::class,
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockReservation $reservation): void {
            if (empty($reservation->uuid)) {
                $reservation->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StockReservationStatus::ACTIVE->value);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reservedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function consumption(): HasOne
    {
        return $this->hasOne(StockConsumption::class);
    }
}
