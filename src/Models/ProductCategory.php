<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $table = 'product_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'parent_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'parent_id'
        );
    }

    /**
     * Direct child categories.
     */
    public function children(): HasMany
    {
        return $this->hasMany(
            self::class,
            'parent_id'
        )
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * User who created the category.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * User who last updated the category.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }

    /**
     * Only active categories.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Only root categories.
     */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Categories in hierarchical display order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
