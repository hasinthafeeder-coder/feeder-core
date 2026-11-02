<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResellerPayment extends Model
{
    public const ENTRY_TYPE_PAYMENT = 'PAYMENT';

    public const ENTRY_TYPE_REVERSAL = 'REVERSAL';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'payment_number',
        'entry_type',
        'invoice_id',
        'reseller_id',
        'reseller_company_id',
        'currency_id',
        'currency_code_snapshot',
        'amount',
        'payment_reference',
        'confirmed_by',
        'confirmed_at',
        'reverses_payment_id',
        'reversal_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            if (empty($payment->uuid)) {
                $payment->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementInvoice::class, 'invoice_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function resellerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'reseller_company_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function reversedPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }
}
