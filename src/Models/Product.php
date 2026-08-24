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
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
       'id',
       'supplier_id',
       'category_id',
       'name',
       'weight',
       'status',
       'system_visible',
       'web_visible',
       'price_locked',
       'created_by',
       'updated_by',
    ];

    protected function casts(): array
    {
       return [
           'weight' => 'decimal:3',
           'system_visible' => 'boolean',
           'web_visible' => 'boolean',
           'price_locked' => 'boolean',
           'status' => ProductStatus::class,
       ];
    }

    protected static function booted(): void
    {
       static::creating(function (Product $product): void {
           $product->id ??= (string) Str::uuid();
       });
    }

    public function getRouteKeyName(): string
    {
       return 'id';
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
       return $this->hasOne(ProductGuideline::class);
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
       return $query->where('supplier_id', $supplierId);
    }
}
