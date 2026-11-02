<?php

namespace Feeder\Core\Models;

use Feeder\Core\Services\UuidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSettlementAccountDesignation extends Model
{
    public const ROLE_PLATFORM_ORIGIN = 'PLATFORM_ORIGIN';

    public const ROLE_SUPPLIER_BENEFICIARY = 'SUPPLIER_BENEFICIARY';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'role',
        'company_id',
        'currency_id',
        'company_bank_account_id',
        'designated_by',
        'designated_at',
    ];

    protected function casts(): array
    {
        return [
            'designated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $designation): void {
            if (empty($designation->uuid)) {
                $designation->uuid = UuidService::generate();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function companyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'company_bank_account_id');
    }

    public function designatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designated_by');
    }
}
