<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntroducerBonusLine extends Model
{
    protected $table = 'introducer_bonus_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'bonus_rate_snapshot' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IntroducerBonusMonthlyBatch::class, 'batch_id');
    }

    public function introducer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'introducer_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function referralRelationship(): BelongsTo
    {
        return $this->belongsTo(ReferralRelationship::class, 'referral_relationship_id');
    }
}
