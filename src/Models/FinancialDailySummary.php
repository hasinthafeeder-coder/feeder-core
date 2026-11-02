<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One market-currency business day inside a payment cycle.
 *
 * Amounts are rebuilt from financial_summary_contributions. Missing source
 * amounts stay null; a stored 0.00 remains 0.00. LKR and MYR never share a row.
 */
class FinancialDailySummary extends Model
{
    public const PROFIT_STATUS_AVAILABLE = 'available';

    public const PROFIT_STATUS_INCOMPLETE = 'incomplete';

    public const PROFIT_STATUS_UNAVAILABLE = 'unavailable';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'payment_cycle_id',
        'market_id',
        'currency_code',
        'business_date',
        'total_income',
        'total_income_row_count',
        'total_income_omitted_count',
        'courier_charges',
        'courier_charges_row_count',
        'courier_charges_omitted_count',
        'service_charge',
        'service_charge_row_count',
        'service_charge_omitted_count',
        'fine_charges',
        'fine_charges_row_count',
        'fine_charges_omitted_count',
        'return_charges',
        'return_charges_row_count',
        'return_charges_omitted_count',
        'supplier_settlement_total',
        'supplier_settlement_row_count',
        'reseller_settlement_total',
        'reseller_settlement_row_count',
        'supplier_payments_confirmed',
        'supplier_payments_confirmed_row_count',
        'supplier_payments_confirmed_omitted_count',
        'reseller_payments_confirmed',
        'reseller_payments_confirmed_row_count',
        'reseller_payments_confirmed_omitted_count',
        'supplier_outstanding_balance',
        'reseller_outstanding_balance',
        'introducer_bonus_reserved',
        'introducer_bonus_reserved_row_count',
        'introducer_bonus_reserved_omitted_count',
        'introducer_bonus_paid',
        'introducer_bonus_paid_row_count',
        'introducer_bonus_paid_omitted_count',
        'company_profit',
        'company_profit_status',
        'supplier_batch_generated',
        'reseller_batch_generated',
        'supplier_fully_paid',
        'reseller_fully_paid',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'total_income' => 'decimal:2',
            'courier_charges' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'fine_charges' => 'decimal:2',
            'return_charges' => 'decimal:2',
            'supplier_settlement_total' => 'decimal:2',
            'reseller_settlement_total' => 'decimal:2',
            'supplier_payments_confirmed' => 'decimal:2',
            'reseller_payments_confirmed' => 'decimal:2',
            'supplier_outstanding_balance' => 'decimal:2',
            'reseller_outstanding_balance' => 'decimal:2',
            'introducer_bonus_reserved' => 'decimal:2',
            'introducer_bonus_paid' => 'decimal:2',
            'company_profit' => 'decimal:2',
            'supplier_batch_generated' => 'boolean',
            'reseller_batch_generated' => 'boolean',
            'supplier_fully_paid' => 'boolean',
            'reseller_fully_paid' => 'boolean',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(FinancialPaymentCycle::class, 'payment_cycle_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(FinancialSummaryContribution::class, 'daily_summary_id');
    }
}
