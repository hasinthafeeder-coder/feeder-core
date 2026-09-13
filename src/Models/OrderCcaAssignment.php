<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\OrderCcaAssignmentOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderCcaAssignment extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'order_id',
        'cca_id',
        'assigned_by',
        'origin',
        'assigned_at',
        'unassigned_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'origin' => OrderCcaAssignmentOrigin::class,
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderCcaAssignment $assignment): void {
            if (empty($assignment->uuid)) {
                $assignment->uuid = (string) Str::uuid();
            }
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

    public function cca(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cca_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
