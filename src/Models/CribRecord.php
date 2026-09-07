<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CribRecord extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'market_id',
        'country_id',
        'normalized_phone',
        'risk_level',
        'risk_code',
        'risk_summary',
        'is_active',
        'last_import_batch_id',
        'source_updated_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'source_updated_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CribRecord $record): void {
            if (empty($record->uuid)) {
                $record->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function lastImportBatch(): BelongsTo
    {
        return $this->belongsTo(CribImportBatch::class, 'last_import_batch_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForPhones(Builder $query, array $normalizedPhones): Builder
    {
        return $query->whereIn('normalized_phone', $normalizedPhones);
    }
}
