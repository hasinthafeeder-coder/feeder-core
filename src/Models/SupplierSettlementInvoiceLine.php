<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSettlementInvoiceLine extends Model
{
    public const LINE_PAYMENT_STATUS_UNPAID = 'UNPAID';

    public const LINE_PAYMENT_STATUS_PAID = 'PAID';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'settlement_date',
        'order_id',
        'order_item_id',
        'order_number_snapshot',
        'order_status_snapshot',
        'supplier_user_id',
        'shipment_id',
        'tracking_number_snapshot',
        'courier_service_id',
        'courier_receipt_import_id',
        'courier_receipt_import_row_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'quantity',
        'unit_cost',
        'line_payable',
        'currency_id',
        'currency_code_snapshot',
        'line_payment_status',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'line_payable' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            if (empty($line->uuid)) {
                $line->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementInvoice::class, 'invoice_id');
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

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function courierService(): BelongsTo
    {
        return $this->belongsTo(CourierService::class, 'courier_service_id');
    }

    public function courierReceiptImport(): BelongsTo
    {
        return $this->belongsTo(CourierReceiptImport::class, 'courier_receipt_import_id');
    }

    public function courierReceiptImportRow(): BelongsTo
    {
        return $this->belongsTo(CourierReceiptImportRow::class, 'courier_receipt_import_row_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
