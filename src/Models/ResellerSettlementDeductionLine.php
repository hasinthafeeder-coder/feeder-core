<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minus Report membership for one reseller debt obligation.
 *
 * This row records that the debt was included in a generated Minus Report.
 * It does not reduce outstanding_amount and it is not a payment or settlement.
 */
class ResellerSettlementDeductionLine extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'batch_id',
        'debt_obligation_id',
        'debt_obligation_number_snapshot',
        'obligation_type_snapshot',
        'return_reason_snapshot',
        'fine_condition_snapshot',
        'reseller_id',
        'reseller_name_snapshot',
        'order_id',
        'order_number_snapshot',
        'currency_id',
        'currency_code_snapshot',
        'original_amount_snapshot',
        'outstanding_amount_snapshot',
        'effective_date_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'original_amount_snapshot' => 'decimal:2',
            'outstanding_amount_snapshot' => 'decimal:2',
            'effective_date_snapshot' => 'date',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementBatch::class, 'batch_id');
    }

    public function debtObligation(): BelongsTo
    {
        return $this->belongsTo(ResellerDebtObligation::class, 'debt_obligation_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
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
