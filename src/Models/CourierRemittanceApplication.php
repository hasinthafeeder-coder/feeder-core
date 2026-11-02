<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Insert-only evidence that one remittance row was applied to one order.
 */
class CourierRemittanceApplication extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'courier_receipt_import_id',
        'courier_receipt_import_row_id',
        'order_id',
        'shipment_id',
        'courier_service_id',
        'normalized_tracking_number',
        'gross_remittance_amount',
        'courier_charge_amount',
        'net_remittance_amount',
        'currency_code',
        'business_date',
        'order_status_before',
        'order_status_after',
        'order_status_history_id',
        'applied_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_remittance_amount' => 'decimal:2',
            'courier_charge_amount' => 'decimal:2',
            'net_remittance_amount' => 'decimal:2',
            'business_date' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CourierRemittanceApplication $application): void {
            if (empty($application->uuid)) {
                $application->uuid = (string) Str::uuid();
            }
        });

        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CourierReceiptImport::class, 'courier_receipt_import_id');
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(CourierReceiptImportRow::class, 'courier_receipt_import_row_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function statusHistory(): BelongsTo
    {
        return $this->belongsTo(OrderStatusHistory::class, 'order_status_history_id');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
