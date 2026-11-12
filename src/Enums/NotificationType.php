<?php

namespace Feeder\Core\Enums;

enum NotificationType: string
{
    case ANNOUNCEMENT = 'ANNOUNCEMENT';
    case SUPPLIER_MESSAGE = 'SUPPLIER_MESSAGE';
    case PRODUCT_OFFER = 'PRODUCT_OFFER';
    case RESTOCK = 'RESTOCK';
    case PRODUCT_INFO_UPDATE = 'PRODUCT_INFO_UPDATE';
    case ORDER_DELIVERED = 'ORDER_DELIVERED';
    case DAILY_FINANCIAL_SUMMARY = 'DAILY_FINANCIAL_SUMMARY';
    case SETTLEMENT_READY = 'SETTLEMENT_READY';
    case SETTLEMENT_COMPLETED = 'SETTLEMENT_COMPLETED';
    case FINANCIAL_CORRECTION = 'FINANCIAL_CORRECTION';
    case MEETING_REMINDER = 'MEETING_REMINDER';

    public function label(): string
    {
        return match ($this) {
            self::ANNOUNCEMENT => 'Announcement',
            self::SUPPLIER_MESSAGE => 'Supplier message',
            self::PRODUCT_OFFER => 'Product offer',
            self::RESTOCK => 'Restock notification',
            self::PRODUCT_INFO_UPDATE => 'Product information update',
            self::ORDER_DELIVERED => 'Order delivered',
            self::DAILY_FINANCIAL_SUMMARY => 'Daily financial summary',
            self::SETTLEMENT_READY => 'Settlement ready',
            self::SETTLEMENT_COMPLETED => 'Settlement completed',
            self::FINANCIAL_CORRECTION => 'Financial correction notice',
            self::MEETING_REMINDER => 'Meeting reminder',
        };
    }

    public function isAdminManaged(): bool
    {
        return match ($this) {
            self::ANNOUNCEMENT, self::SUPPLIER_MESSAGE => true,
            default => false,
        };
    }

    public function isSupplierManaged(): bool
    {
        return match ($this) {
            self::PRODUCT_OFFER, self::RESTOCK, self::PRODUCT_INFO_UPDATE => true,
            default => false,
        };
    }

    public function isFinancialAutomated(): bool
    {
        return match ($this) {
            self::ORDER_DELIVERED,
            self::DAILY_FINANCIAL_SUMMARY,
            self::SETTLEMENT_READY,
            self::SETTLEMENT_COMPLETED,
            self::FINANCIAL_CORRECTION => true,
            default => false,
        };
    }

    public function isMeetingAutomated(): bool
    {
        return $this === self::MEETING_REMINDER;
    }

    /**
     * @return list<self>
     */
    public static function supplierManagedCases(): array
    {
        return [
            self::PRODUCT_OFFER,
            self::RESTOCK,
            self::PRODUCT_INFO_UPDATE,
        ];
    }

    /**
     * @return list<self>
     */
    public static function adminManagedCases(): array
    {
        return [
            self::ANNOUNCEMENT,
            self::SUPPLIER_MESSAGE,
        ];
    }

    /**
     * @return list<self>
     */
    public static function financialAutomatedCases(): array
    {
        return [
            self::ORDER_DELIVERED,
            self::DAILY_FINANCIAL_SUMMARY,
            self::SETTLEMENT_READY,
            self::SETTLEMENT_COMPLETED,
            self::FINANCIAL_CORRECTION,
        ];
    }
}
