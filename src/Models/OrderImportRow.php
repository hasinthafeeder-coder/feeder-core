<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\OrderImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderImportRow extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'order_import_batch_id',
        'row_number',
        'raw_name',
        'raw_address',
        'raw_tp_1',
        'raw_tp_2',
        'raw_price',
        'raw_qty',
        'raw_item_code',
        'raw_delivery',
        'normalized_tp_1',
        'normalized_tp_2',
        'status',
        'validation_errors',
        'product_variant_id',
        'supplier_id',
        'market_id',
        'quantity',
        'unit_selling_price',
        'courier_fee_amount',
        'created_order_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderImportRowStatus::class,
            'row_number' => 'integer',
            'quantity' => 'integer',
            'unit_selling_price' => 'decimal:2',
            'courier_fee_amount' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OrderImportBatch::class, 'order_import_batch_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function createdOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'created_order_id');
    }
}
