<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderFulfillmentItem extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_fulfillment_id',
        'order_item_id',
        'product_variant_id',
        'packed_quantity',
    ];

    protected function casts(): array
    {
        return [
            'packed_quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderFulfillmentItem $item): void {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (OrderFulfillmentItem $item): void {
            if ($item->packed_quantity < 0) {
                throw ValidationException::withMessages([
                    'packed_quantity' => ['Packed quantity cannot be negative.'],
                ]);
            }

            $orderedQuantity = (int) ($item->orderItem?->quantity
                ?? OrderItem::query()->whereKey($item->order_item_id)->value('quantity')
                ?? 0);

            if ($item->packed_quantity > $orderedQuantity) {
                throw ValidationException::withMessages([
                    'packed_quantity' => ['Packed quantity cannot exceed ordered quantity.'],
                ]);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isFullyPacked(): bool
    {
        $orderedQuantity = (int) ($this->orderItem?->quantity ?? 0);

        return $orderedQuantity > 0 && $this->packed_quantity >= $orderedQuantity;
    }
}
