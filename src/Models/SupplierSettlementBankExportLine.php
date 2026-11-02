<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierSettlementBankExportLine extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'bank_export_id',
        'invoice_id',
        'supplier_company_id',
        'currency_id',
        'currency_code_snapshot',
        'origin_company_bank_account_id',
        'beneficiary_company_bank_account_id',
        'origin_account_number',
        'origin_account_name',
        'value_date_text',
        'beneficiary_bank_code',
        'beneficiary_branch_code',
        'beneficiary_account_number',
        'beneficiary_account_name',
        'amount',
        'particulars',
        'reference_text',
        'row_number',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'row_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            if (empty($line->uuid)) {
                $line->uuid = UuidService::generate();
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

    public function originCompanyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'origin_company_bank_account_id');
    }

    public function beneficiaryCompanyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'beneficiary_company_bank_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'bank_export_line_id');
    }
}
