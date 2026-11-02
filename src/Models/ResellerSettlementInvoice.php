<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResellerSettlementInvoice extends Model
{
    public const PAYMENT_STATUS_UNPAID = 'UNPAID';

    public const PAYMENT_STATUS_PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const PAYMENT_STATUS_PAID = 'PAID';

    /** Older invoice reduced by a later settlement, with balance still open and no cash. */
    public const PAYMENT_STATUS_PARTIALLY_OFFSET = 'PARTIALLY_OFFSET';

    /** Older invoice fully settled by later offsets, with no cash payment. */
    public const PAYMENT_STATUS_OFFSET_SETTLED = 'OFFSET_SETTLED';

    /** Outstanding cleared by a mix of cash and internal offsets. Cash is amount_paid only. */
    public const PAYMENT_STATUS_OFFSET_AND_PAID = 'OFFSET_AND_PAID';

    /** Outstanding remains after both a cash payment and an internal offset. */
    public const PAYMENT_STATUS_PARTIAL_AND_OFFSET = 'PARTIAL_AND_OFFSET';

    public const INSTRUCTION_STATUS_ACTIVE = 'ACTIVE';

    public const INSTRUCTION_STATUS_VOIDED = 'VOIDED';

    /** Fee configured for the invoice market/currency and snapshotted at generation. */
    public const TRANSFER_FEE_STATUS_APPLIED = 'applied';

    /** Market fee missing — never invent LKR or FX-convert. */
    public const TRANSFER_FEE_STATUS_NOT_CONFIGURED = 'transfer_fee_not_configured';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'invoice_number',
        'batch_id',
        'reseller_id',
        'reseller_company_id',
        'reseller_name_snapshot',
        'currency_id',
        'currency_code_snapshot',
        'gross_earnings_total',
        'debt_deduction_total',
        'adjustment_total',
        'transfer_fee_amount',
        'transfer_fee_currency_code_snapshot',
        'transfer_fee_status',
        'previous_invoice_offset_total',
        'net_payable',
        'amount_paid',
        'offset_applied_total',
        'outstanding_balance',
        'payment_status',
        'instruction_status',
        'effective_payment_id',
        'issued_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'gross_earnings_total' => 'decimal:2',
            'debt_deduction_total' => 'decimal:2',
            'adjustment_total' => 'decimal:2',
            'transfer_fee_amount' => 'decimal:2',
            'previous_invoice_offset_total' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'offset_applied_total' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if (empty($invoice->uuid)) {
                $invoice->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ResellerSettlementBatch::class, 'batch_id');
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

    public function effectivePayment(): BelongsTo
    {
        return $this->belongsTo(ResellerPayment::class, 'effective_payment_id');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function earningLines(): HasMany
    {
        return $this->hasMany(ResellerSettlementInvoiceLine::class, 'invoice_id');
    }

    public function debtAllocations(): HasMany
    {
        return $this->hasMany(ResellerSettlementDebtAllocation::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ResellerPayment::class, 'invoice_id');
    }

    public function offsetsIssued(): HasMany
    {
        return $this->hasMany(ResellerSettlementInvoiceOffset::class, 'source_invoice_id');
    }

    public function offsetsReceived(): HasMany
    {
        return $this->hasMany(ResellerSettlementInvoiceOffset::class, 'target_invoice_id');
    }

    /**
     * Cash status stays distinct from an internal offset.
     * A zero outstanding invoice with no cash and no offset received stays UNPAID
     * (debt and transfer fee can absorb a new invoice without a payment).
     */
    public static function resolvePaymentStatus(
        string $amountPaid,
        string $offsetApplied,
        string $outstanding,
    ): string {
        $paid = self::moneyIsPositive($amountPaid);
        $offset = self::moneyIsPositive($offsetApplied);
        $open = self::moneyIsPositive($outstanding);

        if ($open && $paid && $offset) {
            return self::PAYMENT_STATUS_PARTIAL_AND_OFFSET;
        }

        if ($open && $paid) {
            return self::PAYMENT_STATUS_PARTIALLY_PAID;
        }

        if ($open && $offset) {
            return self::PAYMENT_STATUS_PARTIALLY_OFFSET;
        }

        if ($open) {
            return self::PAYMENT_STATUS_UNPAID;
        }

        if ($paid && $offset) {
            return self::PAYMENT_STATUS_OFFSET_AND_PAID;
        }

        if ($paid) {
            return self::PAYMENT_STATUS_PAID;
        }

        if ($offset) {
            return self::PAYMENT_STATUS_OFFSET_SETTLED;
        }

        return self::PAYMENT_STATUS_UNPAID;
    }

    /**
     * Plain-language status. Cash and internal offsets stay separate.
     */
    public function paymentStatusCaption(): string
    {
        return match ((string) $this->payment_status) {
            self::PAYMENT_STATUS_PARTIAL_AND_OFFSET => 'Cash payment and an internal invoice offset both apply. Outstanding balance remains. The offset is not a bank transfer.',
            self::PAYMENT_STATUS_PARTIALLY_PAID => 'Cash payment recorded. No internal invoice offset. Outstanding balance remains.',
            self::PAYMENT_STATUS_PAID => 'Settled by a cash payment. No internal invoice offset.',
            self::PAYMENT_STATUS_PARTIALLY_OFFSET => 'Internal invoice offset recorded. No cash payment. Outstanding balance remains.',
            self::PAYMENT_STATUS_OFFSET_SETTLED => 'Settled by an internal invoice offset. No cash payment was recorded.',
            self::PAYMENT_STATUS_OFFSET_AND_PAID => 'Settled by a cash payment and an internal invoice offset. Cash is the amount paid only.',
            self::PAYMENT_STATUS_UNPAID => 'No cash payment and no internal offset applied to this invoice.',
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    public static function finalizedPaymentStatuses(): array
    {
        return [
            self::PAYMENT_STATUS_PAID,
            self::PAYMENT_STATUS_OFFSET_SETTLED,
            self::PAYMENT_STATUS_OFFSET_AND_PAID,
        ];
    }

    private static function moneyIsPositive(string $amount): bool
    {
        return number_format((float) $amount, 2, '.', '') !== '0.00'
            && (float) $amount > 0;
    }
}
