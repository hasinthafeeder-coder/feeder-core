<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Open or closed payment cycle for one market currency on the admin overview.
 *
 * Generation of supplier/reseller batches does not close the cycle. Closure
 * requires both sides fully paid against verified invoice payment state.
 */
class FinancialPaymentCycle extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'market_id',
        'currency_code',
        'status',
        'open_slot',
        'opened_at',
        'closed_at',
        'supplier_batch_id',
        'reseller_batch_id',
        'supplier_generated_at',
        'reseller_generated_at',
        'supplier_fully_paid_at',
        'reseller_fully_paid_at',
        'settlement_date',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'supplier_generated_at' => 'datetime',
            'reseller_generated_at' => 'datetime',
            'supplier_fully_paid_at' => 'datetime',
            'reseller_fully_paid_at' => 'datetime',
            'settlement_date' => 'date',
            'open_slot' => 'integer',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(FinancialDailySummary::class, 'payment_cycle_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(FinancialSummaryContribution::class, 'payment_cycle_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
