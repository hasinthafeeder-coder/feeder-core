<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class StockConsumption extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_id',
        'order_item_id',
        'product_variant_id',
        'stock_reservation_id',
        'quantity',
        'consumed_by',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockConsumption $consumption): void {
            if (empty($consumption->uuid)) {
                $consumption->uuid = (string) Str::uuid();
            }
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

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    public function consumedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }

    public function restoration(): HasOne
    {
        return $this->hasOne(StockRestoration::class);
    }
}
