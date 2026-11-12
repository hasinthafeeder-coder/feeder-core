<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductApprovalLog extends Model
{
    public const ACTION_SUBMITTED = 'SUBMITTED';

    public const ACTION_APPROVED = 'APPROVED';

    public const ACTION_REJECTED = 'REJECTED';

    public const SUBMISSION_TYPE_CREATE = 'CREATE';

    public const SUBMISSION_TYPE_EDIT = 'EDIT';

    protected $fillable = [
        'uuid',
        'product_id',
        'acted_by',
        'action',
        'submission_type',
        'remarks',
        'change_payload',
    ];

    protected function casts(): array
    {
        return [
            'change_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductApprovalLog $log): void {
            if (empty($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
