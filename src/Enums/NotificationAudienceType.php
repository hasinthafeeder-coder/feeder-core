<?php

namespace Feeder\Core\Enums;

enum NotificationAudienceType: string
{
    case ALL_ELIGIBLE_SUPPLIERS = 'ALL_ELIGIBLE_SUPPLIERS';
    case SELECTED_SUPPLIER_USERS = 'SELECTED_SUPPLIER_USERS';
    case ALL_ELIGIBLE_RESELLERS = 'ALL_ELIGIBLE_RESELLERS';
    case ALL_USERS = 'ALL_USERS';
    case ALL_ELIGIBLE_RESELLERS_FOR_PRODUCT = 'ALL_ELIGIBLE_RESELLERS_FOR_PRODUCT';
    case SELECTED_ELIGIBLE_RESELLERS_FOR_PRODUCT = 'SELECTED_ELIGIBLE_RESELLERS_FOR_PRODUCT';
    case ALL_ASSIGNED_RESELLERS_FOR_SUPPLIER = 'ALL_ASSIGNED_RESELLERS_FOR_SUPPLIER';
    case SELECTED_RESELLER_USERS = 'SELECTED_RESELLER_USERS';

    public function label(): string
    {
        return match ($this) {
            self::ALL_ELIGIBLE_SUPPLIERS => 'All Active Suppliers',
            self::SELECTED_SUPPLIER_USERS => 'Selected supplier users',
            self::ALL_ELIGIBLE_RESELLERS => 'All Active Resellers',
            self::ALL_USERS => 'All Users',
            self::ALL_ELIGIBLE_RESELLERS_FOR_PRODUCT => 'All eligible resellers for product',
            self::SELECTED_ELIGIBLE_RESELLERS_FOR_PRODUCT => 'Selected eligible resellers for product',
            self::ALL_ASSIGNED_RESELLERS_FOR_SUPPLIER => 'Assigned resellers',
            self::SELECTED_RESELLER_USERS => 'Selected reseller users',
        };
    }

    public function isAdminAudience(): bool
    {
        return match ($this) {
            self::ALL_ELIGIBLE_SUPPLIERS,
            self::ALL_ELIGIBLE_RESELLERS,
            self::ALL_USERS => true,
            default => false,
        };
    }

    public function isSupplierAudience(): bool
    {
        return match ($this) {
            self::ALL_ELIGIBLE_RESELLERS_FOR_PRODUCT,
            self::SELECTED_ELIGIBLE_RESELLERS_FOR_PRODUCT,
            self::ALL_ASSIGNED_RESELLERS_FOR_SUPPLIER => true,
            default => false,
        };
    }

    public function isFinancialAudience(): bool
    {
        return match ($this) {
            self::SELECTED_RESELLER_USERS, self::SELECTED_SUPPLIER_USERS => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function supplierManagedCases(): array
    {
        return [
            self::ALL_ASSIGNED_RESELLERS_FOR_SUPPLIER,
        ];
    }

    /**
     * @return list<self>
     */
    public static function supplierProductAudienceCases(): array
    {
        return [
            self::ALL_ELIGIBLE_RESELLERS_FOR_PRODUCT,
            self::SELECTED_ELIGIBLE_RESELLERS_FOR_PRODUCT,
        ];
    }

    /**
     * @return list<self>
     */
    public static function adminManagedCases(): array
    {
        return [
            self::ALL_ELIGIBLE_RESELLERS,
            self::ALL_ELIGIBLE_SUPPLIERS,
            self::ALL_USERS,
        ];
    }
}
