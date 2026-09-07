<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Courier extends Model
{
    use SoftDeletes;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Courier $courier): void {
            if (empty($courier->uuid)) {
                $courier->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function services(): HasMany
    {
        return $this->hasMany(CourierService::class);
    }

    public function cities(): HasMany
    {
        return $this->hasMany(CourierCity::class);
    }

    public function marketPricings(): HasMany
    {
        return $this->hasMany(CourierMarketPricing::class);
    }

    public function supplierAccounts(): HasMany
    {
        return $this->hasMany(SupplierCourierAccount::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
