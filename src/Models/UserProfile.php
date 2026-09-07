<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $guarded = [];

    protected $table = 'user_profiles';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            // Current Call Center Agent commission configuration per eligible delivered order.
            // Not historical earnings / ledger totals (those belong to future Order/Finance).
            'agent_commission_per_order' => 'decimal:2',
        ];
    }
}
