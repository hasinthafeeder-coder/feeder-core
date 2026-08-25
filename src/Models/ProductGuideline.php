<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductGuideline extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'product_id',
        'file_uuid',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductGuideline $guideline): void {
            if (empty($guideline->id) && static::shouldGenerateUuidPrimaryKey()) {
                $guideline->id = (string) Str::uuid();
            }
        });
    }

    public static function shouldGenerateUuidPrimaryKey(): bool
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return false;
        }

        $columnType = Schema::getColumnType((new static)->getTable(), 'id');

        return in_array($columnType, ['string', 'char', 'uuid', 'ulid'], true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
