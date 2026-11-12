<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationRecipient extends Model
{
    protected $table = 'notification_recipients';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NotificationRecipient $recipient): void {
            if (empty($recipient->uuid)) {
                $recipient->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function recipientCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'recipient_company_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_recipient_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isOpened(): bool
    {
        return $this->opened_at !== null;
    }

    public function scopeForUserCompany(Builder $query, int $userId, int $companyId): Builder
    {
        return $query
            ->where('recipient_user_id', $userId)
            ->where('recipient_company_id', $companyId);
    }
}
