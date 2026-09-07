<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\CustomerBanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CustomerBan extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'customer_id',
        'status',
        'reason',
        'banned_by_user_id',
        'banned_by_company_id',
        'banned_at',
        'lifted_by_user_id',
        'lifted_by_company_id',
        'lifted_at',
        'lift_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerBanStatus::class,
            'banned_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerBan $ban): void {
            if (empty($ban->uuid)) {
                $ban->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bannedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by_user_id');
    }

    public function bannedByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'banned_by_company_id');
    }

    public function liftedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by_user_id');
    }

    public function liftedByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'lifted_by_company_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerBanEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === CustomerBanStatus::ACTIVE;
    }
}
