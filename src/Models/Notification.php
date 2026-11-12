<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'audience_type' => NotificationAudienceType::class,
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Notification $notification): void {
            if (empty($notification->uuid)) {
                $notification->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function publisherCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'publisher_company_id');
    }

    public function bannerFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'banner_file_uuid', 'uuid');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ResellerMeeting::class, 'meeting_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_id');
    }

    public function isPublishedAt(?Carbon $moment = null): bool
    {
        $moment ??= Carbon::now();

        if ($this->publish_at !== null && $this->publish_at->gt($moment)) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->lte($moment)) {
            return false;
        }

        return true;
    }

    public function scopeCurrentlyVisible(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= Carbon::now();

        return $query
            ->where('publish_at', '<=', $moment)
            ->where(function (Builder $inner) use ($moment): void {
                $inner->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $moment);
            });
    }

    public function scopeForPublisherCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('publisher_company_id', $companyId);
    }

    public function scopeForCreator(Builder $query, int $userId): Builder
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Trusted sender label for portal inbox/detail display.
     *
     * Supplier notices use publisher_company_id → supplier company name.
     * Admin/system notices (null publisher company) display as "Admin".
     * Missing related company rows fall back gracefully.
     */
    public function senderLabel(): string
    {
        if ($this->publisher_company_id === null) {
            return 'Admin';
        }

        $this->loadMissing('publisherCompany');

        $name = trim((string) ($this->publisherCompany?->name ?? ''));

        return $name !== '' ? $name : 'Supplier';
    }
}
