<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\OrderPaymentMethod;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderPaymentSubmission extends Model
{
    protected $table = 'order_payment_submissions';

    protected $fillable = [
        'uuid',
        'order_id',
        'method',
        'review_status',
        'reference_number',
        'amount',
        'description',
        'slip_path',
        'slip_file_uuid',
        'slip_original_name',
        'submitted_by_user_id',
        'submitted_by_company_id',
        'submitted_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'method' => OrderPaymentMethod::class,
            'review_status' => OrderPaymentReviewStatus::class,
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booting(): void
    {
        static::creating(function (OrderPaymentSubmission $submission): void {
            if (empty($submission->uuid)) {
                $submission->uuid = (string) Str::uuid();
            }
        });

        // Submission evidence is append-only. Only review fields may change after create.
        static::updating(function (OrderPaymentSubmission $submission): void {
            $immutable = [
                'uuid',
                'order_id',
                'method',
                'reference_number',
                'amount',
                'description',
                'slip_path',
                'slip_file_uuid',
                'slip_original_name',
                'submitted_by_user_id',
                'submitted_by_company_id',
                'submitted_at',
            ];

            foreach ($immutable as $field) {
                if ($submission->isDirty($field)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'payment' => [
                            'Payment submission evidence cannot be modified after creation.',
                        ],
                    ]);
                }
            }
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

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function submittedByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'submitted_by_company_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
