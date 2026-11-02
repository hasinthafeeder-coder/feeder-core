<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourierReceiptImportRow extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'courier_receipt_import_id',
        'row_number',
        'raw_tracking_number',
        'normalized_tracking_number',
        'raw_order_number',
        'raw_collected_cod',
        'raw_delivery_charge',
        'collected_cod',
        'delivery_charge',
        'gross_remittance_amount',
        'courier_charge_amount',
        'net_remittance_amount',
        'remittance_currency',
        'remittance_business_date',
        'remittance_provided_fields',
        'match_result',
        'matched_order_id',
        'matched_shipment_id',
        'matched_supplier_id',
        'matched_order_number',
        'matched_supplier_label',
        'order_status_snapshot',
        'message',
        'applied_at',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'match_result' => CourierReceiptImportRowMatchResult::class,
            'row_number' => 'integer',
            'collected_cod' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'gross_remittance_amount' => 'decimal:2',
            'courier_charge_amount' => 'decimal:2',
            'net_remittance_amount' => 'decimal:2',
            'remittance_business_date' => 'date',
            'remittance_provided_fields' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CourierReceiptImport::class, 'courier_receipt_import_id');
    }

    public function matchedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'matched_order_id');
    }

    public function matchedShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'matched_shipment_id');
    }

    public function matchedSupplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_supplier_id');
    }

    public function remittanceApplication(): HasOne
    {
        return $this->hasOne(CourierRemittanceApplication::class, 'courier_receipt_import_row_id');
    }
}
