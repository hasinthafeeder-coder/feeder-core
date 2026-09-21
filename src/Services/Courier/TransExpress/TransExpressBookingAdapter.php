<?php

namespace Feeder\Core\Services\Courier\TransExpress;

use Feeder\Core\Contracts\Courier\CourierBookingAdapter;
use Feeder\Core\Exceptions\CourierProviderBookingException;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Courier\CourierBookingDebugSanitizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * TransExpress single-order booking adapter.
 *
 * Builds the TransExpress payload and returns a normalized booking result.
 * Shipment persistence remains in ShipmentBookingService.
 */
final class TransExpressBookingAdapter implements CourierBookingAdapter
{
    private const ORDER_PATH = '/api/orders/upload/single-auto';

    public function __construct(
        private readonly TransExpressMerchantClient $client,
        private readonly CourierBookingDebugSanitizer $debugSanitizer,
    ) {
    }

    public function book(
        Order $order,
        Courier $courier,
        CourierService $service,
        CourierCity $city,
        ?SupplierCourierAccount $account,
        float $weightKg,
        float $courierFee,
    ): array {
        if ($account === null) {
            throw ValidationException::withMessages([
                'supplier_courier_account' => [
                    'An active supplier TransExpress courier account is required before booking.',
                ],
            ]);
        }

        if ((int) $account->supplier_id !== (int) $order->supplier_id) {
            throw ValidationException::withMessages([
                'supplier_courier_account' => [
                    'Supplier courier account does not belong to this order supplier.',
                ],
            ]);
        }

        if ((int) $account->courier_id !== (int) $courier->id) {
            throw ValidationException::withMessages([
                'supplier_courier_account' => [
                    'Supplier courier account does not match the selected courier.',
                ],
            ]);
        }

        $credentials = $account->getCredentials();
        $this->assertCredentialsPresent($credentials);
        $secrets = [
            (string) ($credentials['password'] ?? ''),
            (string) ($credentials['token'] ?? ''),
        ];

        $order->loadMissing(['address', 'items']);

        $payload = $this->buildPayload($order, $city, $courierFee);
        $debugRequest = $this->debugRequest($payload, $secrets);

        try {
            $result = $this->client->post($account, self::ORDER_PATH, $payload);
        } catch (CourierProviderBookingException $e) {
            $this->abort($order, $account, $courier, $e->withRequest($debugRequest));
        }

        $httpStatus = (int) $result['http_status'];
        $response = $result['body'];

        if ($this->bodyIndicatesFailure($response)) {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'TRANSEXPRESS',
                $httpStatus,
                $this->sanitizeValue($response, $secrets),
                $this->rejectionSummary($response),
                $debugRequest,
            ));
        }

        $trackingNumber = $this->extractWaybill($response, $httpStatus, $debugRequest, $secrets, $order, $account, $courier);

        return [
            'tracking_number' => $trackingNumber,
            'external_booking_ref' => null,
            'raw_response' => $this->sanitizeValue($response, $secrets),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Order $order, CourierCity $city, float $courierFee): array
    {
        $customerName = trim((string) $order->customer_name_snapshot);
        $customerPhone = trim((string) $order->primary_phone_snapshot);
        $secondaryPhone = trim((string) ($order->secondary_phone_snapshot ?? ''));
        $customerAddress = $this->resolveCustomerAddress($order);
        $orderNo = $this->resolveOrderNo($order);
        $cod = $this->resolveCodAmount($order, $courierFee);
        $description = $this->resolveDescription($order);
        $cityId = $this->resolveProviderCityId($city);

        $errors = [];

        if ($customerName === '') {
            $errors['customer_name'] = ['Customer name is required for TransExpress booking.'];
        }

        if ($customerPhone === '') {
            $errors['customer_phone'] = ['Customer phone is required for TransExpress booking.'];
        }

        if ($customerAddress === '') {
            $errors['customer_address'] = ['Customer address is required for TransExpress booking.'];
        }

        if ($orderNo === null) {
            $errors['order_number'] = ['Order number is required for TransExpress booking.'];
        }

        if ($cod < 0) {
            $errors['cod'] = ['COD amount is invalid for TransExpress booking.'];
        }

        if ($description === '') {
            $errors['description'] = ['Order description is required for TransExpress booking.'];
        }

        if ($cityId === null) {
            $errors['courier_city_id'] = ['TransExpress city ID is missing for the selected destination city.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $payload = [
            'order_no' => $orderNo,
            'customer_name' => $customerName,
            'address' => $customerAddress,
            'description' => $description,
            'phone_no' => $customerPhone,
            'cod' => $cod,
            'city_id' => $cityId,
            'note' => '',
        ];

        if ($secondaryPhone !== '') {
            $payload['phone_no2'] = $secondaryPhone;
        }

        return $payload;
    }

    private function resolveCustomerAddress(Order $order): string
    {
        $address = $order->address;

        if ($address === null) {
            return '';
        }

        $full = trim((string) ($address->full_address_text ?? ''));

        if ($full !== '') {
            return $full;
        }

        $parts = array_filter([
            trim((string) ($address->line1 ?? '')),
            trim((string) ($address->line2 ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }

    private function resolveOrderNo(Order $order): int|string|null
    {
        $orderNo = trim((string) $order->order_number);

        if ($orderNo === '') {
            return null;
        }

        if (ctype_digit($orderNo)) {
            return (int) $orderNo;
        }

        return $orderNo;
    }

    private function resolveCodAmount(Order $order, float $courierFee): int
    {
        $total = (float) $order->items_subtotal
            - (float) $order->discount_amount
            + $courierFee;

        return (int) round($total);
    }

    private function resolveDescription(Order $order): string
    {
        $parts = [];

        /** @var OrderItem $item */
        foreach ($order->items as $item) {
            $name = trim((string) $item->product_name_snapshot);
            $variant = trim((string) ($item->variant_name_snapshot ?? ''));
            $label = trim($name.($variant !== '' ? ' / '.$variant : ''));
            $qty = (int) $item->quantity;

            if ($label === '') {
                continue;
            }

            $parts[] = $qty > 1 ? $label.' x'.$qty : $label;
        }

        $description = implode(', ', $parts);

        if (strlen($description) > 500) {
            return substr($description, 0, 497).'...';
        }

        return $description;
    }

    private function resolveProviderCityId(CourierCity $city): ?int
    {
        $externalId = trim((string) ($city->external_id ?? ''));

        if ($externalId === '' || ! ctype_digit($externalId)) {
            return null;
        }

        $id = (int) $externalId;

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function assertCredentialsPresent(array $credentials): void
    {
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'supplier_courier_account' => [
                    'TransExpress account email/password are incomplete. Reconfigure the supplier courier account.',
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $debugRequest
     * @param  list<string>  $secrets
     */
    private function extractWaybill(
        array $response,
        int $httpStatus,
        array $debugRequest,
        array $secrets,
        Order $order,
        SupplierCourierAccount $account,
        Courier $courier,
    ): string {
        $sanitized = $this->sanitizeValue($response, $secrets);
        $orderPayload = $response['order'] ?? null;

        if (! is_array($orderPayload)) {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'TRANSEXPRESS',
                $httpStatus,
                $sanitized,
                'Booking failed: TransExpress did not return a waybill number.',
                $debugRequest,
            ));
        }

        $waybill = trim((string) ($orderPayload['waybill_id'] ?? ''));

        if ($waybill === '') {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'TRANSEXPRESS',
                $httpStatus,
                $sanitized,
                'Booking failed: TransExpress returned an empty waybill number.',
                $debugRequest,
            ));
        }

        return $waybill;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function bodyIndicatesFailure(array $response): bool
    {
        if (array_key_exists('success', $response)) {
            $success = $response['success'];

            if ($this->isExplicitFalse($success)) {
                return true;
            }

            // TransExpress success responses use a success message string.
            if (is_string($success) && trim($success) !== '') {
                return false;
            }
        }

        if (array_key_exists('status', $response) && $this->isExplicitFalse($response['status'])) {
            return true;
        }

        return false;
    }

    private function isExplicitFalse(mixed $value): bool
    {
        return $value === false || $value === 0 || $value === '0' || $value === 'false';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function rejectionSummary(array $response): string
    {
        foreach (['message', 'error', 'success'] as $key) {
            $message = $response[$key] ?? null;

            if (! is_string($message)) {
                continue;
            }

            $trimmed = trim($message);

            if ($trimmed !== '' && ! $this->messageLooksSecret($trimmed)) {
                return 'Booking failed: '.(strlen($trimmed) > 240 ? substr($trimmed, 0, 240).'...' : $trimmed);
            }
        }

        return 'Booking failed: TransExpress rejected the order request.';
    }

    private function messageLooksSecret(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'password')
            || str_contains($lower, 'token')
            || str_contains($lower, 'authorization')
            || str_contains($lower, 'bearer')
            || str_contains($lower, 'api_key');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $secrets
     * @return array<string, mixed>
     */
    private function debugRequest(array $payload, array $secrets): array
    {
        $copy = $payload;

        foreach (['phone_no', 'phone_no2'] as $field) {
            if (isset($copy[$field]) && is_string($copy[$field])) {
                $copy[$field] = $this->debugSanitizer->maskPhone($copy[$field]);
            }
        }

        $sanitized = $this->debugSanitizer->sanitize($copy, $secrets);

        return is_array($sanitized) ? $sanitized : [];
    }

    /**
     * @param  list<string>  $secrets
     * @return array<string, mixed>
     */
    private function sanitizeValue(array $value, array $secrets): array
    {
        $sanitized = $this->debugSanitizer->sanitize($value, $secrets);

        return is_array($sanitized) ? $sanitized : [];
    }

    private function abort(
        Order $order,
        SupplierCourierAccount $account,
        Courier $courier,
        CourierProviderBookingException $exception,
    ): never {
        $exception->withCourier((string) $courier->name, (string) $courier->code);

        Log::warning('transexpress_booking_failed', [
            'order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'courier_account_id' => $account->id,
            'http_status' => $exception->debug['http_status'] ?? null,
            'failure_type' => $exception->debug['failure_type'] ?? null,
            'response' => $exception->debug['response'] ?? null,
        ]);

        throw $exception;
    }
}
