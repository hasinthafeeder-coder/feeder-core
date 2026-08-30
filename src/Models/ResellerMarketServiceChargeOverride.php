<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ResellerMarketServiceChargeOverride extends Model
{
    protected $table = 'reseller_market_service_charge_overrides';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (ResellerMarketServiceChargeOverride $override): void {
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
