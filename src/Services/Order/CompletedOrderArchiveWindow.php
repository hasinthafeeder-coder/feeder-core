<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Phase 2B completed/archive split.
 *
 * This is a time window, not an order status. Delivered orders use delivered_at.
 * Returned orders use returned_at. An outcome timestamp greater than or equal to
 * now minus DAYS stays in Completed. An older non-null outcome timestamp is
 * Archived. A completed status with a null outcome timestamp stays in Completed,
 * because age cannot be proven from persisted data.
 */
final class CompletedOrderArchiveWindow
{
    public const DAYS = 7;

    public static function apply(Builder $query, bool $withinWindow): void
    {
        $cutoff = now()->subDays(self::DAYS);
        $delivered = OrderStatus::DELIVERED->value;
        $returned = OrderStatus::RETURNED->value;

        $query->where(function (Builder $outer) use ($withinWindow, $cutoff, $delivered, $returned): void {
            $outer->where(function (Builder $deliveredQuery) use ($withinWindow, $cutoff, $delivered): void {
                $deliveredQuery->where('status', $delivered);
                self::applyOutcomeTimestamp($deliveredQuery, 'delivered_at', $withinWindow, $cutoff);
            })->orWhere(function (Builder $returnedQuery) use ($withinWindow, $cutoff, $returned): void {
                $returnedQuery->where('status', $returned);
                self::applyOutcomeTimestamp($returnedQuery, 'returned_at', $withinWindow, $cutoff);
            });
        });
    }

    private static function applyOutcomeTimestamp(Builder $query, string $column, bool $withinWindow, Carbon $cutoff): void
    {
        if ($withinWindow) {
            $query->where(function (Builder $time) use ($column, $cutoff): void {
                $time->whereNull($column)
                    ->orWhere($column, '>=', $cutoff);
            });

            return;
        }

        $query->whereNotNull($column)
            ->where($column, '<', $cutoff);
    }
}
