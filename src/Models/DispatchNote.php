<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DispatchNote extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'dispatch_number',
        'supplier_id',
        'courier_id',
        'created_by',
        'orders_count',
        'total_cod_amount',
        'currency_code',
        'handed_over_at',
    ];

    protected function casts(): array
    {
        return [
            'orders_count' => 'integer',
            'total_cod_amount' => 'decimal:2',
            'handed_over_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DispatchNote $note): void {
            if (empty($note->uuid)) {
                $note->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'courier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function noteOrders(): HasMany
    {
        return $this->hasMany(DispatchNoteOrder::class, 'dispatch_note_id');
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }
}
