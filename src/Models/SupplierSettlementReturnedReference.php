<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSettlementReturnedReference extends Model
{
    public const SOURCE_PRE_LOCK = 'PRE_LOCK';

    public const SOURCE_POST_LOCK_CATCH_UP = 'POST_LOCK_CATCH_UP';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'batch_id',
        'supplier_company_id',
        'currency_id',
        'currency_code_snapshot',
        'order_id',
        'order_item_id',
        'order_number_snapshot',
        'order_status_snapshot',
        'supplier_user_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'quantity',
        'unit_cost',
        'display_payable',
        'returned_at_business_date',
        'source',
        'prior_payable_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'display_payable' => 'decimal:2',
            'returned_at_business_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reference): void {
            if (empty($reference->uuid)) {
                $reference->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementBatch::class, 'batch_id');
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function supplierUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function priorPayableInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementInvoice::class, 'prior_payable_invoice_id');
    }
}
