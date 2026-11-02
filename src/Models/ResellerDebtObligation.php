<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\ResellerDebtObligationType;
use Feeder\Core\Enums\ResellerNonDeliveryFineCondition;
use Feeder\Core\Enums\ResellerReturnReason;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerDebtObligation extends Model
{
    public const SOURCE_SUPPLIER_RETURN_SCAN = 'SUPPLIER_RETURN_SCAN';

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
        'obligation_type',
        'return_reason',
        'fine_condition',
        'original_amount',
        'outstanding_amount',
        'effective_date',
        'source_type',
        'source_idempotency_key',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'obligation_type' => ResellerDebtObligationType::class,
            'return_reason' => ResellerReturnReason::class,
            'fine_condition' => ResellerNonDeliveryFineCondition::class,
            'original_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'effective_date' => 'date',
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

    public function hasOutstandingBalance(): bool
    {
        return (float) $this->outstanding_amount > 0;
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

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
