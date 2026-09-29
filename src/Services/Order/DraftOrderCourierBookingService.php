<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\OrderCommentContextType;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Exceptions\CourierProviderBookingException;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Services\Courier\CourierBookingAdapterResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Books a shipment from the order's saved draft courier selection.
 *
 * Create/Edit persist draft_courier_* only. Confirmation workflows book via
 * book() while the order is still pre-CONFIRMED, then transition to CONFIRMED.
 * bookSavedDraft() remains for confirmed-order retry of legacy gaps.
 */
class DraftOrderCourierBookingService
{
    public const FAILURE_CONTEXT_REF = 'courier-booking-failure';

    public function __construct(
        private readonly OrderCourierLookupService $courierLookupService,
        private readonly ShipmentBookingService $shipmentBookingService,
        private readonly CourierBookingAdapterResolver $adapterResolver,
        private readonly OrderCommentService $commentService,
    ) {
    }

    public function book(
        Order $order,
        int $courierId,
        ?int $courierServiceId,
        int $courierCityId,
        ?int $bookedBy = null,
        ?int $actorCompanyId = null,
    ): Shipment {
        $courier = $this->courierLookupService->requireEligibleCourier($order, $courierId);

        if ($courierServiceId === null || $courierServiceId < 1) {
            $service = $this->courierLookupService->firstActiveServiceForCourier($courierId);

            if ($service === null) {
                throw ValidationException::withMessages([
                    'courier_service_id' => [
                        'No active courier service is available for the selected courier.',
                    ],
                ]);
            }

            $courierServiceId = (int) $service->id;
        }

        $adapter = $this->adapterResolver->resolve($courier);

        return $this->shipmentBookingService->book(
            $order,
            $courierId,
            $courierServiceId,
            $courierCityId,
            $adapter,
            $bookedBy,
            $actorCompanyId,
        );
    }

    /**
     * Book using the courier, service, and city currently stored on the order.
     */
    public function bookSavedDraft(
        Order $order,
        ?int $bookedBy = null,
        ?int $actorCompanyId = null,
    ): Shipment {
        $order->loadMissing('shipment');

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status !== OrderStatus::CONFIRMED) {
            throw ValidationException::withMessages([
                'order' => ['Courier booking runs only after the order is confirmed.'],
            ]);
        }

        if ($order->shipment !== null || $order->hasBookedShipment()) {
            throw ValidationException::withMessages([
                'order_id' => ['Order already has a shipment.'],
            ]);
        }

        if (
            $order->draft_courier_id === null
            || $order->draft_courier_service_id === null
            || $order->draft_courier_city_id === null
        ) {
            throw ValidationException::withMessages([
                'courier_id' => ['Saved courier, service, and city are required before booking.'],
            ]);
        }

        return $this->book(
            $order,
            (int) $order->draft_courier_id,
            (int) $order->draft_courier_service_id,
            (int) $order->draft_courier_city_id,
            $bookedBy,
            $actorCompanyId,
        );
    }

    public function recordFailure(Order $order, Throwable $exception, ?int $actorId = null): void
    {
        $summary = $this->failureSummary($exception);

        Log::warning('Courier booking failed.', [
            'order_id' => (int) $order->id,
            'order_uuid' => (string) $order->uuid,
            'message' => $summary,
            'debug' => $exception instanceof CourierProviderBookingException ? $exception->debug : null,
        ]);

        $authorId = (int) ($actorId ?: $order->updated_by ?: $order->reseller_id);

        if ($authorId < 1 || $order->customer_id === null) {
            return;
        }

        $this->commentService->add(
            $order,
            $summary,
            OrderCommentContextType::SYSTEM,
            $authorId,
            $order->reseller_company_id !== null ? (int) $order->reseller_company_id : null,
            self::FAILURE_CONTEXT_REF,
        );
    }

    private function failureSummary(Throwable $exception): string
    {
        $detail = 'Courier booking failed. No shipment was created.';

        if ($exception instanceof ValidationException) {
            $first = collect($exception->errors())->flatten()->filter()->first();

            if (is_string($first) && trim($first) !== '') {
                $detail = trim($first);
            }
        }

        if (! str_starts_with($detail, 'Courier booking failed')) {
            $detail = 'Courier booking failed: '.$detail;
        }

        return mb_substr($detail, 0, 2000);
    }
}
