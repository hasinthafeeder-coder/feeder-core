<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReferralRelationship extends Model
{
    use SoftDeletes;

    protected $table = 'referral_relationships';

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(User::class, 'child_user_id');
    }

    public function sourceReferralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'source_referral_code_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
