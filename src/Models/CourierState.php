<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CourierState extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'courier_id',
        'external_id',
        'external_ref_no',
        'name',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CourierState $state): void {
            if (empty($state->uuid)) {
                $state->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function cities(): HasMany
    {
        return $this->hasMany(CourierCity::class, 'courier_state_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
