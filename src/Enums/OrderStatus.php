<?php

namespace Feeder\Core\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case FIRST_ATTEMPT = '1ST_ATTEMPT';
    case SECOND_ATTEMPT = '2ND_ATTEMPT';
    case THIRD_ATTEMPT = '3RD_ATTEMPT';
    case HOLD = 'HOLD';
    case CONFIRMED = 'CONFIRMED';
    case PRINT = 'PRINT';
    case PRINTED = 'PRINTED';
    case PACKING = 'PACKING';
    case DISPATCHED = 'DISPATCHED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::FIRST_ATTEMPT => '1st Attempt',
            self::SECOND_ATTEMPT => '2nd Attempt',
            self::THIRD_ATTEMPT => '3rd Attempt',
            self::HOLD => 'Hold',
            self::CONFIRMED => 'Confirmed',
            self::PRINT => 'Print',
            self::PRINTED => 'Printed',
            self::PACKING => 'Packing',
            self::DISPATCHED => 'Dispatched',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Statuses visible in Reseller Call Center active lists.
     * Supplier Send to Print (CONFIRMED → PRINT) removes the order from this set.
     *
     * @return list<self>
     */
    public static function callCenterStatuses(): array
    {
        return [
            self::PENDING,
            self::FIRST_ATTEMPT,
            self::SECOND_ATTEMPT,
            self::THIRD_ATTEMPT,
            self::HOLD,
            self::CONFIRMED,
        ];
    }

    /**
     * Statuses Call Center / reseller operators may set manually.
     * Includes CANCELLED; excludes supplier-owned PRINT / PRINTED / PACKING / DISPATCHED.
     *
     * @return list<self>
     */
    public static function callCenterActionableStatuses(): array
    {
        return [
            ...self::callCenterStatuses(),
            self::CANCELLED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function callCenterStatusValues(): array
    {
        return array_map(
            static fn (self $status) => $status->value,
            self::callCenterStatuses()
        );
    }

    /**
     * @return list<string>
     */
    public static function callCenterActionableStatusValues(): array
    {
        return array_map(
            static fn (self $status) => $status->value,
            self::callCenterActionableStatuses()
        );
    }

    public function isCallCenterVisible(): bool
    {
        return in_array($this, self::callCenterStatuses(), true);
    }

    public function isIncomplete(): bool
    {
        return $this !== self::CANCELLED;
    }

    /**
     * Whether the order has not reached DISPATCHED yet (includes CANCELLED).
     */
    public function isBeforeDispatch(): bool
    {
        return $this !== self::DISPATCHED;
    }

    /**
     * Lifecycle rule: cancellation is allowed from any status before DISPATCHED.
     */
    public function canCancel(): bool
    {
        return $this !== self::DISPATCHED && $this !== self::CANCELLED;
    }

    /**
     * Canonical lifecycle transition validity (portal authorization is separate).
     *
     * Call Center may move freely among call-center statuses.
     * Supplier path is sequential: CONFIRMED → PRINT → PRINTED → PACKING → DISPATCHED.
     * CANCELLED is allowed from any pre-dispatch status via canCancel().
     */
    public static function canTransitionTo(self $from, self $to): bool
    {
        if ($from === $to) {
            return true;
        }

        if ($to === self::CANCELLED) {
            return $from->canCancel();
        }

        if ($from === self::CANCELLED) {
            return in_array($to, self::callCenterStatuses(), true);
        }

        $callCenter = self::callCenterStatuses();
        if (in_array($from, $callCenter, true) && in_array($to, $callCenter, true)) {
            return true;
        }

        $supplierForward = [
            self::CONFIRMED->value => self::PRINT,
            self::PRINT->value => self::PRINTED,
            self::PRINTED->value => self::PACKING,
            self::PACKING->value => self::DISPATCHED,
        ];

        return ($supplierForward[$from->value] ?? null) === $to;
    }

    /**
     * Whether discount remains locked after confirmation (including post-confirm fulfillment).
     */
    public function locksDiscount(): bool
    {
        return match ($this) {
            self::CONFIRMED,
            self::PRINT,
            self::PRINTED,
            self::PACKING,
            self::DISPATCHED => true,
            default => false,
        };
    }
}
