<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DispatchNoteOrder extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'dispatch_note_id',
        'order_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (DispatchNoteOrder $row): void {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function dispatchNote(): BelongsTo
    {
        return $this->belongsTo(DispatchNote::class, 'dispatch_note_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
