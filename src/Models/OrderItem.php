<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'barcode_snapshot',
        'quantity',
        'unit_selling_price',
        'unit_cost_snapshot',
        'unit_company_commission_snapshot',
        'unit_weight_snapshot',
        'line_selling_total',
        'line_weight_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_selling_price' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:2',
            'unit_company_commission_snapshot' => 'decimal:2',
            'unit_weight_snapshot' => 'decimal:3',
            'line_selling_total' => 'decimal:2',
            'line_weight_total' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
