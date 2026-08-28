<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Market extends Model
{
    use SoftDeletes;

    protected $table = 'markets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function supplierCompanies(): HasMany
    {
        return $this->hasMany(Company::class, 'operation_market_id');
    }

    public function resellerCompanies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            'reseller_market_access',
            'market_id',
            'company_id'
        )->withPivot(['id', 'uuid', 'granted_by'])
            ->withTimestamps();
    }
}
