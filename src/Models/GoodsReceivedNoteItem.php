<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GoodsReceivedNoteItem extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'grn_id',
        'product_id',
        'product_variant_id',
        'received_quantity',
        'damaged_quantity',
        'unit_cost',
        'product_name_snapshot',
        'variant_name_snapshot',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (GoodsReceivedNoteItem $item): void {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
