<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
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
        'published_at',
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
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
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

    public function guidelineFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'guideline_file_id');
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Products whose supplier is assigned to the given reseller.
     */
    public function scopeForReseller(Builder $query, int $resellerId): Builder
    {
        return $query->whereIn('supplier_id', function ($subquery) use ($resellerId) {
            $subquery->select('supplier_id')
                ->from('reseller_supplier_assignments')
                ->where('reseller_id', $resellerId);
        });
    }

    public function descriptionFor(string $languageCode): ?string
    {
        return $this->descriptions
            ->firstWhere('language_code', $languageCode)
            ?->description;
    }

    public function primaryVariant(): ?ProductVariant
    {
        return $this->variants->first();
    }

    public function primaryImage(): ?ProductImage
    {
        return $this->images->firstWhere('is_primary', true) ?? $this->images->first();
    }

    public function supplierCompanyName(): string
    {
        return $this->supplier?->company?->name ?: '—';
    }
}
