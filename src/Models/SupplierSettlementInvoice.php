<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierSettlementInvoice extends Model
{
    public const PAYMENT_STATUS_UNPAID = 'UNPAID';

    public const PAYMENT_STATUS_PAID = 'PAID';

    public const INSTRUCTION_STATUS_ACTIVE = 'ACTIVE';

    public const INSTRUCTION_STATUS_VOIDED = 'VOIDED';

    public const INSTRUCTION_STATUS_PAID = 'PAID';

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
        'supplier_company_id',
        'supplier_company_name_snapshot',
        'currency_id',
        'currency_code_snapshot',
        'payable_total',
        'transfer_fee_amount',
        'transfer_fee_currency_code_snapshot',
        'transfer_fee_status',
        'net_payable',
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
            'payable_total' => 'decimal:2',
            'transfer_fee_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
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

            // Preserve gross payable when callers omit fee snapshots (tests / legacy inserts).
            if (! array_key_exists('transfer_fee_amount', $invoice->getAttributes())
                || $invoice->transfer_fee_amount === null) {
                $invoice->transfer_fee_amount = '0.00';
            }

            if (! array_key_exists('transfer_fee_status', $invoice->getAttributes())
                || $invoice->transfer_fee_status === null
                || $invoice->transfer_fee_status === '') {
                $invoice->transfer_fee_status = self::TRANSFER_FEE_STATUS_NOT_CONFIGURED;
            }

            if (! array_key_exists('net_payable', $invoice->getAttributes())
                || $invoice->net_payable === null) {
                $gross = round((float) ($invoice->payable_total ?? 0), 2);
                $fee = round((float) ($invoice->transfer_fee_amount ?? 0), 2);
                $invoice->net_payable = number_format(max(0, $gross - $fee), 2, '.', '');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementBatch::class, 'batch_id');
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function effectivePayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'effective_payment_id');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function payableLines(): HasMany
    {
        return $this->hasMany(SupplierSettlementInvoiceLine::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'invoice_id');
    }

    public function bankExportLines(): HasMany
    {
        return $this->hasMany(SupplierSettlementBankExportLine::class, 'invoice_id');
    }
}
