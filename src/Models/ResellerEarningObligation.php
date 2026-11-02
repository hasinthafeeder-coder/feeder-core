<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerEarningObligation extends Model
{
    public const SOURCE_COURIER_RECEIPT_CONFIRMATION = 'COURIER_RECEIPT_CONFIRMATION';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'obligation_number',
        'reseller_id',
        'reseller_company_id',
        'order_id',
        'market_id',
        'currency_id',
        'currency_code_snapshot',
        'business_date',
        'cod_amount_snapshot',
        'supplier_cost_snapshot',
        'service_charge_snapshot',
        'courier_charge_snapshot',
        'company_commission_snapshot',
        'gross_reseller_profit',
        'courier_receipt_import_id',
        'courier_receipt_import_row_id',
        'source_type',
        'source_idempotency_key',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'cod_amount_snapshot' => 'decimal:2',
            'supplier_cost_snapshot' => 'decimal:2',
            'service_charge_snapshot' => 'decimal:2',
            'courier_charge_snapshot' => 'decimal:2',
            'company_commission_snapshot' => 'decimal:2',
            'gross_reseller_profit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $obligation): void {
            if (empty($obligation->uuid)) {
                $obligation->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function resellerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'reseller_company_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function courierReceiptImport(): BelongsTo
    {
        return $this->belongsTo(CourierReceiptImport::class, 'courier_receipt_import_id');
    }

    public function courierReceiptImportRow(): BelongsTo
    {
        return $this->belongsTo(CourierReceiptImportRow::class, 'courier_receipt_import_row_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
