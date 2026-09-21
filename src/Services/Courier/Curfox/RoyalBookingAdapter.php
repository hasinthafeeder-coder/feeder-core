<?php

namespace Feeder\Core\Services\Courier\Curfox;

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
 * ROYAL / Curfox single-order booking adapter.
 *
 * Builds the Curfox payload and returns a normalized booking result.
 * Shipment persistence remains in ShipmentBookingService.
 */
final class RoyalBookingAdapter implements CourierBookingAdapter
{
    private const ORDER_PATH = '/api/public/merchant/order/single';

    public function __construct(
        private readonly CurfoxMerchantClient $client,
        private readonly RoyalAccountMeta $accountMeta,
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
                    'An active supplier ROYAL courier account is required before booking.',
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

        $meta = $this->accountMeta->requireFromAccount($account);
        $credentials = $account->getCredentials();
        $this->assertCredentialsPresent($credentials);
        $secrets = [
            (string) ($credentials['password'] ?? ''),
            (string) ($credentials['token'] ?? ''),
        ];

        $order->loadMissing(['address', 'items']);

        $payload = [
            'general_data' => [
                'merchant_business_id' => $meta[RoyalAccountMeta::KEY_MERCHANT_BUSINESS_ID],
                'origin_city_name' => $meta[RoyalAccountMeta::KEY_ORIGIN_CITY_NAME],
                'origin_state_name' => $meta[RoyalAccountMeta::KEY_ORIGIN_STATE_NAME],
            ],
            'order_data' => [
                $this->buildOrderData($order, $city, $weightKg, $courierFee),
            ],
        ];

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
                'ROYAL',
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
    private function buildOrderData(Order $order, CourierCity $city, float $weightKg, float $courierFee): array
    {
        $customerName = trim((string) $order->customer_name_snapshot);
        $customerPhone = trim((string) $order->primary_phone_snapshot);
        $customerAddress = $this->resolveCustomerAddress($order);
        $destinationCity = $this->resolveDestinationCityName($city);
        $destinationState = $this->resolveDestinationStateName($city);
        $orderNo = trim((string) $order->order_number);
        $cod = $this->resolveCodAmount($order, $courierFee);
        $weight = $this->resolveWeightKg($weightKg);
        $description = $this->resolveDescription($order);
        $secondaryPhone = trim((string) ($order->secondary_phone_snapshot ?? ''));

        $errors = [];

        if ($customerName === '') {
            $errors['customer_name'] = ['Customer name is required for ROYAL booking.'];
        }

        if ($customerPhone === '') {
            $errors['customer_phone'] = ['Customer phone is required for ROYAL booking.'];
        }

        if ($customerAddress === '') {
            $errors['customer_address'] = ['Customer address is required for ROYAL booking.'];
        }

        if ($destinationCity === '') {
            $errors['courier_city_id'] = ['Destination city is required for ROYAL booking.'];
        }

        if ($destinationState === '') {
            $errors['courier_city_id'] = ['Destination state is required for ROYAL booking.'];
        }

        if ($orderNo === '') {
            $errors['order_number'] = ['Order number is required for ROYAL booking.'];
        }

        if ($cod < 0) {
            $errors['cod'] = ['COD amount is invalid for ROYAL booking.'];
        }

        if ($weight <= 0) {
            $errors['weight'] = ['Order weight must be greater than zero for ROYAL booking.'];
        }

        if ($description === '') {
            $errors['description'] = ['Order description is required for ROYAL booking.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $row = [
            'order_no' => $orderNo,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_phone' => $customerPhone,
            'destination_city_name' => $destinationCity,
            'destination_state_name' => $destinationState,
            'cod' => $cod,
            'description' => $description,
            'weight' => $weight,
            'remark' => '',
        ];

        if ($secondaryPhone !== '') {
            $row['customer_secondary_phone'] = $secondaryPhone;
        }

        return $row;
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

    private function resolveDestinationCityName(CourierCity $city): string
    {
        return trim((string) ($city->name ?: $city->city_name));
    }

    private function resolveDestinationStateName(CourierCity $city): string
    {
        $city->loadMissing('state');

        $fromProviderState = trim((string) ($city->state?->name ?? ''));

        if ($fromProviderState !== '') {
            return $fromProviderState;
        }

        return trim((string) ($city->district_name ?? ''));
    }

    private function resolveCodAmount(Order $order, float $courierFee): int
    {
        $total = (float) $order->items_subtotal
            - (float) $order->discount_amount
            + $courierFee;

        return (int) round($total);
    }

    private function resolveWeightKg(float $weightKg): int
    {
        if ($weightKg <= 0) {
            return 0;
        }

        return (int) ceil($weightKg);
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
     * @param  array<string, mixed>  $credentials
     */
    private function assertCredentialsPresent(array $credentials): void
    {
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'supplier_courier_account' => [
                    'ROYAL account email/password are incomplete. Reconfigure the supplier courier account.',
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
        $data = $response['data'] ?? null;
        $sanitized = $this->sanitizeValue($response, $secrets);

        if (! is_array($data) || $data === []) {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'ROYAL',
                $httpStatus,
                $sanitized,
                'Booking failed: Curfox did not return a waybill number.',
                $debugRequest,
            ));
        }

        $first = $data[0] ?? null;

        if (! is_scalar($first)) {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'ROYAL',
                $httpStatus,
                $sanitized,
                'Booking failed: Curfox returned an invalid waybill number.',
                $debugRequest,
            ));
        }

        $waybill = trim((string) $first);

        if ($waybill === '') {
            $this->abort($order, $account, $courier, CourierProviderBookingException::rejected(
                'ROYAL',
                $httpStatus,
                $sanitized,
                'Booking failed: Curfox returned an empty waybill number.',
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
        if (array_key_exists('success', $response) && $this->isExplicitFalse($response['success'])) {
            return true;
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
        $message = $response['message'] ?? null;

        if (is_string($message)) {
            $trimmed = trim($message);

            if ($trimmed !== '' && ! $this->messageLooksSecret($trimmed)) {
                return 'Booking failed: '.(strlen($trimmed) > 240 ? substr($trimmed, 0, 240).'...' : $trimmed);
            }
        }

        return 'Booking failed: Royal Express rejected the order request.';
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
        $rows = $copy['order_data'] ?? null;

        if (is_array($rows)) {
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach (['customer_phone', 'customer_secondary_phone'] as $field) {
                    if (isset($row[$field]) && is_string($row[$field])) {
                        $copy['order_data'][$index][$field] = $this->debugSanitizer->maskPhone($row[$field]);
                    }
                }
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

        Log::warning('royal_booking_failed', [
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

