<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use Feeder\Core\Enums\CompanyStatus;

class Company extends Model
{
    use SoftDeletes;

    protected $table = 'companies';

    protected $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(Portal::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(CompanyAddress::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(CompanyBankAccount::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'is_active' => 'boolean',
        ];
    }
}
