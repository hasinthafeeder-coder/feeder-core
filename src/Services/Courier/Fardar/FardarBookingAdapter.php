<?php

namespace Feeder\Core\Services\Courier\Fardar;

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
 * Fardar Domestic single-order booking adapter.
 *
 * Builds form-urlencoded create-parcel payload and returns a normalized booking result.
 * Shipment persistence remains in ShipmentBookingService.
 * Exchange orders are not supported in Phase 1 — always sends exchange=0.
 */
final class FardarBookingAdapter implements CourierBookingAdapter
{
    /**
     * Phase 1: Feeder does not support exchange parcels. Keep explicit for later change.
     */
    private const EXCHANGE_NORMAL = '0';

    public function __construct(
        private readonly FardarApiClient $client,
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
                    'An active supplier Fardar courier account is required before booking.',
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
        $apiKey = (string) ($credentials['api_key'] ?? '');
        $secrets = [$apiKey];

        $order->loadMissing(['address', 'items']);

        $payload = $this->buildPayload($order, $city, $weightKg, $courierFee, $credentials);
        $debugRequest = $this->debugRequest($payload, $secrets);

        try {
            $result = $this->client->createParcel($payload, $secrets);
        } catch (CourierProviderBookingException $e) {
            $this->abort($order, $account, $courier, $e->withRequest($debugRequest));
        }

        $httpStatus = (int) $result['http_status'];
        $response = $result['body'];
        $providerStatus = $this->resolveProviderStatus($response);

        if ($providerStatus === null) {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'FARDAR',
                $httpStatus,
                $this->sanitizeValue($response, $secrets),
                'Booking failed: Fardar returned a response without a usable status.',
                $debugRequest,
            ));
        }

        if (! FardarStatusMessages::isSuccess($providerStatus)) {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'FARDAR',
                $httpStatus,
                $this->sanitizeValue($response, $secrets),
                'Booking failed: '.FardarStatusMessages::forStatus($providerStatus),
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
     * @param  array<string, mixed>  $credentials
     * @return array<string, scalar>
     */
    private function buildPayload(
        Order $order,
        CourierCity $city,
        float $weightKg,
        float $courierFee,
        array $credentials,
    ): array {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $apiKey = (string) ($credentials['api_key'] ?? '');
        $customerName = trim((string) $order->customer_name_snapshot);
        $customerPhone = trim((string) $order->primary_phone_snapshot);
        $secondaryPhone = trim((string) ($order->secondary_phone_snapshot ?? ''));
        $customerAddress = $this->resolveCustomerAddress($order);
        $orderId = $this->resolveOrderId($order);
        $cod = $this->resolveCodAmount($order, $courierFee);
        $weight = $this->resolveWeight($weightKg);
        $description = $this->resolveDescription($order);
        $recipientCity = $this->resolveRecipientCityName($city);

        $errors = [];

        if ($customerName === '') {
            $errors['customer_name'] = ['Customer name is required for Fardar booking.'];
        }

        if ($customerPhone === '') {
            $errors['customer_phone'] = ['Customer phone is required for Fardar booking.'];
        }

        if ($customerAddress === '') {
            $errors['customer_address'] = ['Customer address is required for Fardar booking.'];
        }

        if ($recipientCity === '') {
            $errors['courier_city_id'] = ['Recipient city name is missing for the selected Fardar destination city.'];
        }

        if ($weight === null) {
            $errors['weight'] = ['Order weight must be greater than zero for Fardar booking.'];
        }

        if ($description === '') {
            $errors['description'] = ['Order description is required for Fardar booking.'];
        }

        if ($cod < 0) {
            $errors['cod'] = ['COD amount is invalid for Fardar booking.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $payload = [
            'client_id' => $clientId,
            'api_key' => $apiKey,
            'parcel_weight' => (string) $weight,
            'parcel_description' => $description,
            'recipient_name' => $customerName,
            'recipient_contact_1' => $customerPhone,
            'recipient_contact_2' => $secondaryPhone,
            'recipient_address' => $customerAddress,
            'recipient_city' => $recipientCity,
            'amount' => (string) $cod,
            'exchange' => self::EXCHANGE_NORMAL,
        ];

        if ($orderId !== null) {
            $payload['order_id'] = $orderId;
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

    private function resolveOrderId(Order $order): int|string|null
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

    private function resolveWeight(float $weightKg): ?string
    {
        if ($weightKg <= 0) {
            return null;
        }

        return (string) (int) ceil($weightKg);
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

    /**
     * Fardar create-parcel expects the city name (e.g. Matara), not the CSV city id.
     */
    private function resolveRecipientCityName(CourierCity $city): string
    {
        $name = trim((string) ($city->city_name ?: $city->name));

        return $name;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function assertCredentialsPresent(array $credentials): void
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $apiKey = (string) ($credentials['api_key'] ?? '');

        if ($clientId === '' || $apiKey === '') {
            throw ValidationException::withMessages([
                'supplier_courier_account' => [
                    'Fardar account Client ID/API Key are incomplete. Reconfigure the supplier courier account.',
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function resolveProviderStatus(array $response): ?int
    {
        if (! array_key_exists('status', $response)) {
            return null;
        }

        $status = $response['status'];

        if (is_int($status)) {
            return $status;
        }

        if (is_string($status) && ctype_digit(trim($status))) {
            return (int) trim($status);
        }

        return null;
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
        $waybill = trim((string) ($response['waybill_no'] ?? ''));

        if ($waybill === '') {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'FARDAR',
                $httpStatus,
                $sanitized,
                'Booking failed: Fardar did not return a waybill number.',
                $debugRequest,
            ));
        }

        return $waybill;
    }

    /**
     * @param  array<string, scalar>  $payload
     * @param  list<string>  $secrets
     * @return array<string, mixed>
     */
    private function debugRequest(array $payload, array $secrets): array
    {
        $copy = $payload;

        foreach (['recipient_contact_1', 'recipient_contact_2'] as $field) {
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

        Log::warning('fardar_booking_failed', [
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
