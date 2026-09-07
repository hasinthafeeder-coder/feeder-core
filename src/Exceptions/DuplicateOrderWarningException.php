<?php

namespace Feeder\Core\Exceptions;

use Feeder\Core\Models\Order;
use Illuminate\Validation\ValidationException;

class DuplicateOrderWarningException extends ValidationException
{
    /**
     * @param  list<Order>  $duplicates
     */
    public static function fromDuplicates(array $duplicates): self
    {
        $ids = array_map(static fn (Order $order) => $order->id, $duplicates);
        $numbers = array_map(
            static fn (Order $order) => $order->order_number ?? (string) $order->id,
            $duplicates
        );

        $exception = self::withMessages([
            'duplicate' => [
                'Potential duplicate orders were found for this customer and product variant(s). '
                .'Pass duplicate_warning_overridden=true to proceed.',
            ],
            'duplicate_order_ids' => $ids,
            'duplicate_order_numbers' => $numbers,
        ]);

        $exception->duplicates = $duplicates;

        return $exception;
    }

    /** @var list<Order> */
    public array $duplicates = [];
}
