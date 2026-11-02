<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResellerSettlementBatch extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_LOCKED = 'LOCKED';

    public const STATUS_CLOSED = 'CLOSED';

    public const KIND_DAILY = 'DAILY';

    public const KIND_CATCH_UP = 'CATCH_UP';

    public const REPORT_TYPE_COMBINED = 'COMBINED';

    public const REPORT_TYPE_PLUS = 'PLUS';

    public const REPORT_TYPE_MINUS = 'MINUS';

    public const GENERATION_SOURCE_MANUAL = 'MANUAL';

    public const GENERATION_SOURCE_SCHEDULED = 'SCHEDULED';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'batch_number',
        'market_id',
        'market_code_snapshot',
        'timezone_snapshot',
        'settlement_date',
        'kind',
        'report_type',
        'sequence',
        'status',
        'generation_source',
        'generated_by',
        'generated_at',
        'locked_by',
        'locked_at',
        'closed_by',
        'closed_at',
        'close_reason',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'sequence' => 'integer',
            'generated_at' => 'datetime',
            'locked_at' => 'datetime',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            if (empty($batch->uuid)) {
                $batch->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(ResellerSettlementInvoice::class, 'batch_id');
    }

    public function reviewItems(): HasMany
    {
        return $this->hasMany(ResellerSettlementReviewItem::class, 'batch_id');
    }

    public function deductionLines(): HasMany
    {
        return $this->hasMany(ResellerSettlementDeductionLine::class, 'batch_id');
    }

    public function debtAllocations(): HasMany
    {
        return $this->hasMany(ResellerSettlementDebtAllocation::class, 'batch_id');
    }

    public function invoiceOffsets(): HasMany
    {
        return $this->hasMany(ResellerSettlementInvoiceOffset::class, 'source_batch_id');
    }
}
