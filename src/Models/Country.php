<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Country extends Model
{
    use SoftDeletes;

    protected $table = 'countries';

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

    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }

    public function activeMarkets(): HasMany
    {
        return $this->markets()->where('is_active', true);
    }

    public function homeCompanies(): HasMany
    {
        return $this->hasMany(Company::class, 'home_country_id');
    }
}
