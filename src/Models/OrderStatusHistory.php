<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderStatusHistory extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'order_id',
        'from_status',
        'to_status',
        'changed_by',
        'changed_by_company_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderStatusHistory $history): void {
            if (empty($history->uuid)) {
                $history->uuid = (string) Str::uuid();
            }

            if ($history->created_at === null) {
                $history->created_at = now();
            }
        });

        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function changedByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'changed_by_company_id');
    }
}
