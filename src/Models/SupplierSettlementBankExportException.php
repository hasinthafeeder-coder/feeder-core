<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSettlementBankExportException extends Model
{
    public const REASON_CURRENCY_MISMATCH = 'CURRENCY_MISMATCH';

    public const REASON_INVOICE_NOT_ACTIVE = 'INVOICE_NOT_ACTIVE';

    public const REASON_INVOICE_ALREADY_PAID = 'INVOICE_ALREADY_PAID';

    public const REASON_INVOICE_ALREADY_EXPORTED = 'INVOICE_ALREADY_EXPORTED';

    public const REASON_INVALID_AMOUNT = 'INVALID_AMOUNT';

    public const REASON_MISSING_BENEFICIARY_DESIGNATION = 'MISSING_BENEFICIARY_DESIGNATION';

    public const REASON_AMBIGUOUS_BENEFICIARY_DESIGNATION = 'AMBIGUOUS_BENEFICIARY_DESIGNATION';

    public const REASON_MISSING_BENEFICIARY_ACCOUNT = 'MISSING_BENEFICIARY_ACCOUNT';

    public const REASON_AMBIGUOUS_BENEFICIARY_ACCOUNT = 'AMBIGUOUS_BENEFICIARY_ACCOUNT';

    public const REASON_BENEFICIARY_ACCOUNT_COMPANY_MISMATCH = 'BENEFICIARY_ACCOUNT_COMPANY_MISMATCH';

    public const REASON_MISSING_BENEFICIARY_BANK_CODE = 'MISSING_BENEFICIARY_BANK_CODE';

    public const REASON_MISSING_BENEFICIARY_BRANCH_CODE = 'MISSING_BENEFICIARY_BRANCH_CODE';

    public const REASON_MISSING_BENEFICIARY_ACCOUNT_NUMBER = 'MISSING_BENEFICIARY_ACCOUNT_NUMBER';

    public const REASON_MISSING_BENEFICIARY_ACCOUNT_NAME = 'MISSING_BENEFICIARY_ACCOUNT_NAME';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'bank_export_id',
        'invoice_id',
        'supplier_company_id',
        'currency_id',
        'reason_code',
        'reason_detail',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $exception): void {
            if (empty($exception->uuid)) {
                $exception->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function bankExport(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementBankExport::class, 'bank_export_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementInvoice::class, 'invoice_id');
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
