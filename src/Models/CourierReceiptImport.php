<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\CourierReceiptImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CourierReceiptImport extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'courier_service_id',
        'uploaded_by',
        'original_filename',
        'file_type',
        'stored_path',
        'content_hash',
        'format_id',
        'entered_order_count',
        'entered_total_collected_cod',
        'entered_delivery_charge',
        'entered_net_income',
        'file_row_count',
        'matched_count',
        'unmatched_count',
        'ambiguous_count',
        'duplicate_count',
        'previously_processed_count',
        'service_mismatch_count',
        'invalid_count',
        'already_applied_count',
        'file_has_cod_amounts',
        'file_has_delivery_charges',
        'imported_cod_total',
        'imported_delivery_charge_total',
        'order_count_matches',
        'cod_totals_match',
        'delivery_charge_totals_match',
        'net_income_consistent',
        'totals_reconcile',
        'reconciliation_notes',
        'status',
        'failure_reason',
        'confirmed_at',
        'confirmed_by',
        'confirmation_summary',
    ];

    protected function casts(): array
    {
        return [
            'status' => CourierReceiptImportStatus::class,
            'entered_order_count' => 'integer',
            'entered_total_collected_cod' => 'decimal:2',
            'entered_delivery_charge' => 'decimal:2',
            'entered_net_income' => 'decimal:2',
            'file_row_count' => 'integer',
            'matched_count' => 'integer',
            'unmatched_count' => 'integer',
            'ambiguous_count' => 'integer',
            'duplicate_count' => 'integer',
            'previously_processed_count' => 'integer',
            'service_mismatch_count' => 'integer',
            'invalid_count' => 'integer',
            'already_applied_count' => 'integer',
            'file_has_cod_amounts' => 'boolean',
            'file_has_delivery_charges' => 'boolean',
            'imported_cod_total' => 'decimal:2',
            'imported_delivery_charge_total' => 'decimal:2',
            'order_count_matches' => 'boolean',
            'cod_totals_match' => 'boolean',
            'delivery_charge_totals_match' => 'boolean',
            'net_income_consistent' => 'boolean',
            'totals_reconcile' => 'boolean',
            'reconciliation_notes' => 'array',
            'confirmed_at' => 'datetime',
            'confirmation_summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CourierReceiptImport $import): void {
            if (empty($import->uuid)) {
                $import->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function courierService(): BelongsTo
    {
        return $this->belongsTo(CourierService::class, 'courier_service_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CourierReceiptImportRow::class, 'courier_receipt_import_id');
    }
}
