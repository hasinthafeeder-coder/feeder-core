<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'product_id',
        'file_id',
        'sort_order',
        'is_primary',
    ];

    protected $appends = [
        'file_uuid',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    public function getFileUuidAttribute(): ?string
    {
        return $this->file?->uuid;
    }
}
