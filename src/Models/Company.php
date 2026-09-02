<?php

namespace Feeder\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\SupplierType;
use Feeder\Core\Services\SupplierOperationMarketService;

class Company extends Model
{
    use SoftDeletes;

    protected $table = 'companies';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (Company $company): void {
            if (! $company->isDirty('operation_market_id')) {
                return;
            }

            app(SupplierOperationMarketService::class)->preventMutation(
                $company,
                $company->operation_market_id
            );
        });
    }

    public function isSupplierCompany(): bool
    {
        $this->loadMissing('portal');

        return $this->portal?->code === PortalCode::SUPPLIER->value;
    }

    public function isResellerCompany(): bool
    {
        $this->loadMissing('portal');

        return $this->portal?->code === PortalCode::RESELLER->value;
    }

    public function isProSupplier(): bool
    {
        return $this->supplier_type === SupplierType::PRO;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(Portal::class);
    }

    public function operationMarket(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'operation_market_id');
    }

    public function homeCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'home_country_id');
    }

    public function allowedMarkets(): BelongsToMany
    {
        return $this->belongsToMany(
            Market::class,
            'reseller_market_access',
            'company_id',
            'market_id'
        )->withPivot(['id', 'uuid', 'granted_by'])
            ->withTimestamps();
    }

    public function address(): HasOne
    {
        return $this->hasOne(CompanyAddress::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(CompanyBankAccount::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'supplier_type' => SupplierType::class,
            'is_active' => 'boolean',
        ];
    }
}
