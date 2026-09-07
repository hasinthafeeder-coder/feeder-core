<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\OrderCommentContextType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderComment extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'order_id',
        'customer_id',
        'author_user_id',
        'author_company_id',
        'context_type',
        'context_ref',
        'body',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context_type' => OrderCommentContextType::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderComment $comment): void {
            if (empty($comment->uuid)) {
                $comment->uuid = (string) Str::uuid();
            }

            if ($comment->created_at === null) {
                $comment->created_at = now();
            }
        });

        static::updating(function (): bool {
            return false;
        });

        static::deleting(function (): bool {
            return false;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function authorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'author_company_id');
    }
}
