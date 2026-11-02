<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal, non-cash allocation from a later settlement invoice to an older
 * unpaid invoice. This is not a ResellerPayment.
 */
class ResellerSettlementInvoiceOffset extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'source_invoice_id',
        'source_batch_id',
        'target_invoice_id',
        'reseller_id',
        'market_id',
        'currency_id',
        'currency_code_snapshot',
        'allocated_amount',
        'target_outstanding_before',
        'target_outstanding_after',
        'target_offset_applied_before',
        'target_offset_applied_after',
        'allocation_sequence',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
            'target_outstanding_before' => 'decimal:2',
            'target_outstanding_after' => 'decimal:2',
            'target_offset_applied_before' => 'decimal:2',
            'target_offset_applied_after' => 'decimal:2',
            'allocation_sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $offset): void {
            if (empty($offset->uuid)) {
                $offset->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementInvoice::class, 'source_invoice_id');
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementBatch::class, 'source_batch_id');
    }

    public function targetInvoice(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementInvoice::class, 'target_invoice_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
