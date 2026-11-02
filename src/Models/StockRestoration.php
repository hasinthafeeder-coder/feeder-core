<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockRestoration extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_id',
        'order_item_id',
        'product_variant_id',
        'stock_consumption_id',
        'quantity',
        'restored_by',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockRestoration $restoration): void {
            if (empty($restoration->uuid)) {
                $restoration->uuid = (string) Str::uuid();
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

    public function consumption(): BelongsTo
    {
        return $this->belongsTo(StockConsumption::class, 'stock_consumption_id');
    }

    public function restoredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
