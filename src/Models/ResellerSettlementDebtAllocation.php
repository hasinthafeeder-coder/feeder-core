<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerSettlementDebtAllocation extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'batch_id',
        'debt_obligation_id',
        'debt_obligation_number_snapshot',
        'obligation_type_snapshot',
        'return_reason_snapshot',
        'order_id',
        'order_number_snapshot',
        'currency_id',
        'currency_code_snapshot',
        'allocated_amount',
        'debt_outstanding_before',
        'debt_outstanding_after',
        'allocation_sequence',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
            'debt_outstanding_before' => 'decimal:2',
            'debt_outstanding_after' => 'decimal:2',
            'allocation_sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            if (empty($allocation->uuid)) {
                $allocation->uuid = UuidService::generate();
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementBatch::class, 'batch_id');
    }

    public function debtObligation(): BelongsTo
    {
        return $this->belongsTo(ResellerDebtObligation::class, 'debt_obligation_id');
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
