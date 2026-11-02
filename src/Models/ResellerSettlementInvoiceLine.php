<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerSettlementInvoiceLine extends Model
{
    public const LINE_PAYMENT_STATUS_UNPAID = 'UNPAID';

    public const LINE_PAYMENT_STATUS_PAID = 'PAID';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'earning_obligation_id',
        'settlement_date',
        'order_id',
        'order_number_snapshot',
        'obligation_number_snapshot',
        'business_date_snapshot',
        'cod_amount_snapshot',
        'service_charge_snapshot',
        'courier_charge_snapshot',
        'company_commission_snapshot',
        'gross_reseller_profit',
        'currency_id',
        'currency_code_snapshot',
        'line_payment_status',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'business_date_snapshot' => 'date',
            'cod_amount_snapshot' => 'decimal:2',
            'service_charge_snapshot' => 'decimal:2',
            'courier_charge_snapshot' => 'decimal:2',
            'company_commission_snapshot' => 'decimal:2',
            'gross_reseller_profit' => 'decimal:2',
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
        return $this->belongsTo(ResellerSettlementInvoice::class, 'invoice_id');
    }

    public function earningObligation(): BelongsTo
    {
        return $this->belongsTo(ResellerEarningObligation::class, 'earning_obligation_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
