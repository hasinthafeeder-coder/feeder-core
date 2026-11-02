<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerSettlementReviewItem extends Model
{
    public const REASON_NEGATIVE_GROSS_PROFIT = 'NEGATIVE_GROSS_PROFIT';

    public const STATUS_OPEN = 'OPEN';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'batch_id',
        'earning_obligation_id',
        'reseller_id',
        'order_id',
        'order_number_snapshot',
        'obligation_number_snapshot',
        'currency_id',
        'currency_code_snapshot',
        'gross_reseller_profit',
        'review_reason',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'gross_reseller_profit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if (empty($item->uuid)) {
                $item->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementBatch::class, 'batch_id');
    }

    public function earningObligation(): BelongsTo
    {
        return $this->belongsTo(ResellerEarningObligation::class, 'earning_obligation_id');
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
