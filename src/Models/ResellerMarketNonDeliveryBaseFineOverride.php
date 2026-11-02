<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Per-reseller override for the BASE non-delivery fine only.
 *
 * Does not affect AFTER_HOURS / PROCESSING_OVERRIDE / COMBINED global totals.
 */
class ResellerMarketNonDeliveryBaseFineOverride extends Model
{
    protected $table = 'reseller_market_non_delivery_base_fine_overrides';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $override): void {
            if (empty($override->uuid)) {
                $override->uuid = (string) Str::uuid();
            }
        });
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
