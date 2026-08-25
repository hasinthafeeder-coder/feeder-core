<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
       'id',
       'uuid',
       'supplier_id',
       'category_id',
       'name',
       'slug',
       'status',
       'system_visible',
       'web_visible',
       'price_locked',
       'guideline_file_id',
       'created_by',
       'updated_by',
    ];

    protected function casts(): array
    {
       return [
           'system_visible' => 'boolean',
           'web_visible' => 'boolean',
           'price_locked' => 'boolean',
           'status' => ProductStatus::class,
       ];
    }

    protected static function booted(): void
    {
       static::creating(function (Product $product): void {
           if (Schema::hasColumn($product->getTable(), 'uuid') && empty($product->uuid)) {
               $product->uuid = (string) Str::uuid();
           }

           if (empty($product->id) && static::shouldGenerateUuidPrimaryKey()) {
               $product->id = (string) Str::uuid();
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

    public function getRouteKeyName(): string
    {
       return Schema::hasColumn($this->getTable(), 'uuid') ? 'uuid' : 'id';
    }

    public function supplier(): BelongsTo
    {
       return $this->belongsTo(User::class, 'supplier_id');
    }

    public function category(): BelongsTo
    {
       return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function descriptions(): HasMany
    {
       return $this->hasMany(ProductDescription::class);
    }

    public function variants(): HasMany
    {
       return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    public function images(): HasMany
    {
       return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function guideline(): HasOne
    {
       if (! Schema::hasTable('product_guidelines')) {
           return $this->hasOne(ProductGuideline::class)->whereRaw('1 = 0');
       }

       return $this->hasOne(ProductGuideline::class);
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
       return $query->where('supplier_id', $supplierId);
    }
}
