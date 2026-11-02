<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntroducerBonusMonthlyBatch extends Model
{
    public const STATUS_GENERATED = 'GENERATED';

    public const STATUS_PAID = 'PAID';

    public const GENERATION_SOURCE_MANUAL = 'MANUAL';

    public const GENERATION_SOURCE_SCHEDULED = 'SCHEDULED';

    protected $table = 'introducer_bonus_monthly_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'bonus_rate_snapshot' => 'decimal:2',
            'payable_total' => 'decimal:2',
            'line_count' => 'integer',
            'rebuild_count' => 'integer',
            'generated_at' => 'datetime',
            'rebuilt_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function rebuiltBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rebuilt_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IntroducerBonusLine::class, 'batch_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(IntroducerBonusPayment::class, 'batch_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntroducerBonusEvent::class, 'batch_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
