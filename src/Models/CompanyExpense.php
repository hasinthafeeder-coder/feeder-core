<?php

namespace Feeder\Core\Models;

use Feeder\Core\Enums\CompanyExpenseCategory;
use Feeder\Core\Enums\CompanyExpenseStatus;
use Feeder\Core\Services\UuidService;
use Feeder\Core\Support\DecimalAmount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CompanyExpense extends Model
{
    protected $table = 'company_expenses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'category' => CompanyExpenseCategory::class,
            'status' => CompanyExpenseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function newEloquentBuilder($query): CompanyExpenseBuilder
    {
        return new CompanyExpenseBuilder($query);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function correctsExpense(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_expense_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_expense_id');
    }

    public function amountString(): string
    {
        return DecimalAmount::fromStored($this->getAttributes()['amount'] ?? null);
    }

    public function isPosted(): bool
    {
        return $this->status === CompanyExpenseStatus::POSTED;
    }

    protected static function booted(): void
    {
        static::creating(function (CompanyExpense $expense): void {
            if (empty($expense->uuid)) {
                $expense->uuid = UuidService::generate();
            }
        });

        static::updating(function (CompanyExpense $expense): void {
            $originalStatus = $expense->getOriginal('status');
            $originalStatusValue = $originalStatus instanceof CompanyExpenseStatus
                ? $originalStatus->value
                : (string) $originalStatus;

            if ($originalStatusValue === CompanyExpenseStatus::VOIDED->value) {
                throw ValidationException::withMessages([
                    'expense' => ['A voided expense is final and cannot be changed.'],
                ]);
            }

            $allowed = ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at'];
            $forbidden = array_diff(array_keys($expense->getDirty()), $allowed);

            if ($forbidden !== []) {
                throw ValidationException::withMessages([
                    'expense' => ['A posted expense cannot be edited. Void it and record a correction.'],
                ]);
            }

            if ($expense->isDirty('status')) {
                if ($expense->status !== CompanyExpenseStatus::VOIDED) {
                    throw ValidationException::withMessages([
                        'status' => ['An expense can only move from posted to voided.'],
                    ]);
                }

                if (trim((string) $expense->void_reason) === '') {
                    throw ValidationException::withMessages([
                        'void_reason' => ['A void reason is required.'],
                    ]);
                }
            }
        });

        static::deleting(function (): bool {
            return false;
        });
    }
}
