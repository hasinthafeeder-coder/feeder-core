<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\OrderImportBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderImportBatch extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'reseller_company_id',
        'reseller_id',
        'uploaded_by',
        'original_filename',
        'file_type',
        'stored_path',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'banned_rows',
        'created_rows',
        'status',
        'processed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderImportBatchStatus::class,
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'banned_rows' => 'integer',
            'created_rows' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderImportBatch $batch): void {
            if (empty($batch->uuid)) {
                $batch->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'reseller_company_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(OrderImportRow::class, 'order_import_batch_id');
    }
}
