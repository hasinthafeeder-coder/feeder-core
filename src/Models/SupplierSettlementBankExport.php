<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierSettlementBankExport extends Model
{
    public const STATUS_READY = 'READY';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    public const PURPOSE_SETTLEMENT = 'SETTLEMENT';

    public const PURPOSE_REPAYMENT = 'REPAYMENT';

    public const DISPOSITION_NOT_SUBMITTED = 'NOT_SUBMITTED';

    public const DISPOSITION_BANK_REJECTED = 'BANK_REJECTED';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'batch_id',
        'currency_id',
        'currency_code_snapshot',
        'purpose',
        'attempt_number',
        'ready_version',
        'status',
        'value_date',
        'value_date_overridden',
        'value_date_overridden_by',
        'value_date_override_reason',
        'file_uuid',
        'content_sha256',
        'byte_size',
        'original_filename',
        'failure_reason',
        'superseded_at',
        'superseded_by_user_id',
        'supersede_reason',
        'previous_file_disposition',
        'download_count',
        'first_downloaded_at',
        'first_downloaded_by',
        'last_downloaded_at',
        'last_downloaded_by',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'ready_version' => 'integer',
            'value_date' => 'date',
            'value_date_overridden' => 'boolean',
            'byte_size' => 'integer',
            'download_count' => 'integer',
            'superseded_at' => 'datetime',
            'first_downloaded_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $export): void {
            if (empty($export->uuid)) {
                $export->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementBatch::class, 'batch_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function valueDateOverriddenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'value_date_overridden_by');
    }

    public function supersededByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by_user_id');
    }

    public function firstDownloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_downloaded_by');
    }

    public function lastDownloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_downloaded_by');
    }

    public function exportLines(): HasMany
    {
        return $this->hasMany(SupplierSettlementBankExportLine::class, 'bank_export_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(SupplierSettlementBankExportException::class, 'bank_export_id');
    }
}
