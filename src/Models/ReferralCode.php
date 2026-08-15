<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReferralCode extends Model
{
    use SoftDeletes;

    protected $table = 'referral_codes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function lastChangedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_changed_by_user_id');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(ReferralRelationship::class, 'source_referral_code_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
