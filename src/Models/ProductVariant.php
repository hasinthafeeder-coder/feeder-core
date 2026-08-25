<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'uuid',
        'product_id',
        'name',
        'barcode',
        'cost',
        'selling_price',
        'weight',
        'suggested_price',
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
            'company_commission' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (Schema::hasColumn($variant->getTable(), 'uuid') && empty($variant->uuid)) {
                $variant->uuid = (string) Str::uuid();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
