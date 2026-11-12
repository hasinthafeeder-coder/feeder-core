<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\ProductApprovalStatus;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Support\YouTubeUrl;
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
        'market_id',
        'name',
        'slug',
        'status',
        'approval_status',
        'system_visible',
        'web_visible',
        'price_locked',
        'guideline_file_id',
        'youtube_url',
        'warranty_available',
        'warranty_period',
        'published_at',
        'submitted_for_review_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'pending_changes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'system_visible' => 'boolean',
            'web_visible' => 'boolean',
            'price_locked' => 'boolean',
            'warranty_available' => 'boolean',
            'status' => ProductStatus::class,
            'approval_status' => ProductApprovalStatus::class,
            'pending_changes' => 'array',
            'published_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
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

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ProductApprovalLog::class)->latest('id');
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('approval_status', ProductApprovalStatus::PENDING_REVIEW);
    }

    public function isPendingReview(): bool
    {
        return $this->approval_status === ProductApprovalStatus::PENDING_REVIEW;
    }

    public function isApprovalRejected(): bool
    {
        return $this->approval_status === ProductApprovalStatus::REJECTED;
    }

    public function isApprovalApproved(): bool
    {
        return $this->approval_status === ProductApprovalStatus::APPROVED
            || ($this->approval_status === null && $this->status === ProductStatus::ACTIVE);
    }

    /**
     * Explicit APPROVED rows (not ACTIVE + null grandfathering) require edit review.
     */
    public function requiresEditApproval(): bool
    {
        return $this->approval_status === ProductApprovalStatus::APPROVED;
    }

    public function hasPendingEditChanges(): bool
    {
        return is_array($this->pending_changes) && $this->pending_changes !== [];
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

    public function youtubeEmbedUrl(): ?string
    {
        return YouTubeUrl::embedUrl($this->youtube_url);
    }

    public function hasWarranty(): bool
    {
        return (bool) $this->warranty_available
            && filled(trim((string) $this->warranty_period));
    }

    public function scopeWithWarranty(Builder $query): Builder
    {
        return $query->where('warranty_available', true)
            ->whereNotNull('warranty_period')
            ->where('warranty_period', '!=', '');
    }
}
