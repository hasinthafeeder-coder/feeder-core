<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductDescription extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'product_id',
        'language_code',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductDescription $description): void {
            if (empty($description->id) && static::shouldGenerateUuidPrimaryKey()) {
                $description->id = (string) Str::uuid();
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
