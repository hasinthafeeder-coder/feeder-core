<?php

namespace Feeder\Core\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Authorization\Traits\HasPermissions;

class User extends Authenticatable
{
    use SoftDeletes;
    use HasPermissions;

    protected $guarded = [];

    protected $table = 'users';

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions'
        )->withPivot('allowed')->withTimestamps();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function referralCode(): HasOne
    {
        return $this->hasOne(ReferralCode::class, 'user_id');
    }

    public function parentReseller(): HasOne
    {
        return $this->hasOne(ReferralRelationship::class, 'child_user_id');
    }

    public function childResellers(): HasMany
    {
        return $this->hasMany(ReferralRelationship::class, 'parent_user_id');
    }

    public function isMasterReseller(): bool
    {
        return (bool) $this->is_master_reseller;
    }

    public function isReseller(): bool
    {
        return $this->user_type === UserType::OWNER->value;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Products supplied by this user.
     */
    public function suppliedProducts(): HasMany
    {
        return $this->hasMany(
            Product::class,
            'supplier_id'
        );
    }

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'is_master_reseller' => 'boolean',
            'last_login_at' => 'datetime',
            'reseller_service_charge_override' => 'decimal:2',
        ];
    }
}
