<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotent source line that rolls into a daily summary metric.
 *
 * Unique on (source_type, source_id, metric). Reprocessing updates the same
 * row; voids set voided_at and drop the amount from the rolled-up summary.
 */
class FinancialSummaryContribution extends Model
{
    public const METRIC_INCOME = 'income';

    public const METRIC_COURIER = 'courier';

    public const METRIC_SERVICE_CHARGE = 'service_charge';

    public const METRIC_FINE = 'fine';

    public const METRIC_RETURN_CHARGE = 'return_charge';

    public const METRIC_SUPPLIER = 'supplier';

    public const METRIC_RESELLER = 'reseller';

    public const METRIC_SUPPLIER_PAYMENT = 'supplier_payment';

    public const METRIC_RESELLER_PAYMENT = 'reseller_payment';

    public const METRIC_INTRODUCER_RESERVE = 'introducer_reserve';

    public const METRIC_INTRODUCER_PAID = 'introducer_paid';

    public const SOURCE_COURIER_REMITTANCE = 'courier_remittance_application';

    public const SOURCE_BANK_TRANSFER = 'order_payment_submission';

    public const SOURCE_EARNING = 'reseller_earning_obligation';

    public const SOURCE_DEBT = 'reseller_debt_obligation';

    public const SOURCE_SUPPLIER_INVOICE = 'supplier_settlement_invoice';

    public const SOURCE_RESELLER_INVOICE = 'reseller_settlement_invoice';

    public const SOURCE_SUPPLIER_PAYMENT = 'supplier_payment';

    public const SOURCE_RESELLER_PAYMENT = 'reseller_payment';

    public const SOURCE_INTRODUCER_BATCH = 'introducer_bonus_monthly_batch';

    public const SOURCE_INTRODUCER_PAYMENT = 'introducer_bonus_payment';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'daily_summary_id',
        'payment_cycle_id',
        'metric',
        'source_type',
        'source_id',
        'order_id',
        'amount',
        'amount_present',
        'meta',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_present' => 'boolean',
            'meta' => 'array',
            'voided_at' => 'datetime',
        ];
    }

    public function dailySummary(): BelongsTo
    {
        return $this->belongsTo(FinancialDailySummary::class, 'daily_summary_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(FinancialPaymentCycle::class, 'payment_cycle_id');
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }
}
