<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\CustomerBanEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerBanEvent extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'customer_ban_id',
        'customer_id',
        'event_type',
        'actor_user_id',
        'actor_company_id',
        'reason',
        'payload_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => CustomerBanEventType::class,
            'payload_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerBanEvent $event): void {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }

            if ($event->created_at === null) {
                $event->created_at = now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function ban(): BelongsTo
    {
        return $this->belongsTo(CustomerBan::class, 'customer_ban_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'actor_company_id');
    }
}
