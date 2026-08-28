<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ResellerSupplierAssignment extends Model
{
    protected $table = 'reseller_supplier_assignments';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (ResellerSupplierAssignment $assignment): void {
            if (empty($assignment->uuid)) {
                $assignment->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
