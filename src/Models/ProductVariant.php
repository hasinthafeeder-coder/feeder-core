<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'product_id',
        'name',
        'barcode',
        'cost',
        'selling_price',
        'weight',
        'suggested_price',
        'suggested_price_min',
        'suggested_price_max',
        'company_commission',
        'sort_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'weight' => 'decimal:3',
            'suggested_price' => 'decimal:2',
            'suggested_price_min' => 'decimal:2',
            'suggested_price_max' => 'decimal:2',
            'company_commission' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (empty($variant->uuid)) {
                $variant->uuid = (string) Str::uuid();
            }

            if ($variant->barcode === null || $variant->barcode === '') {
                $variant->barcode = 'AUTO-' . strtoupper(Str::random(12));
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function grnItems(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteItem::class, 'product_variant_id');
    }
}
