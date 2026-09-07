<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\CribImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CribImportBatch extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'market_id',
        'source_name',
        'source_file_hash',
        'imported_at',
        'record_count',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CribImportStatus::class,
            'imported_at' => 'datetime',
            'record_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CribImportBatch $batch): void {
            if (empty($batch->uuid)) {
                $batch->uuid = (string) Str::uuid();
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(CribRecord::class, 'last_import_batch_id');
    }
}
