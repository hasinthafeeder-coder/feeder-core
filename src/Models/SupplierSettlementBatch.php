<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierSettlementBatch extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_LOCKED = 'LOCKED';

    public const STATUS_CLOSED = 'CLOSED';

    public const KIND_DAILY = 'DAILY';

    public const KIND_CATCH_UP = 'CATCH_UP';

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
        return $this->hasMany(SupplierSettlementInvoice::class, 'batch_id');
    }

    public function returnedReferences(): HasMany
    {
        return $this->hasMany(SupplierSettlementReturnedReference::class, 'batch_id');
    }

    public function bankExports(): HasMany
    {
        return $this->hasMany(SupplierSettlementBankExport::class, 'batch_id');
    }
}
