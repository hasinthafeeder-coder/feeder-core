<?php

namespace Feeder\Core\Services\Order;

use Carbon\CarbonImmutable;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Exceptions\DuplicateOrderWarningException;
use Feeder\Core\Models\Currency;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderAddress;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly CustomerIdentityService $customerIdentityService,
        private readonly OrderAuthorizationGuard $authorizationGuard,
        private readonly AfterHoursDeterminationService $afterHoursDeterminationService,
        private readonly CallCenterAgentEligibilityService $ccaEligibilityService,
        private readonly OrderShipmentLockService $shipmentLockService,
    ) {
    }

    /**
     * @param  array{
     *     source?: string|OrderSource,
     *     market_id: int,
     *     reseller_id: int,
     *     reseller_company_id: int,
     *     supplier_id: int,
     *     cca_id?: int|null,
     *     available_in_pool?: bool,
     *     customer: array{
     *         display_name: string,
     *         primary_country_id: int,
     *         primary_phone: string,
     *         primary_phone_country_id: int,
     *         secondary_phone?: string|null,
     *         secondary_phone_country_id?: int|null
     *     },
     *     address: array{
     *         recipient_name: string,
     *         line1: string,
     *         line2?: string|null,
     *         city_name: string,
     *         district_name?: string|null,
     *         postal_code?: string|null,
     *         country_id: int,
     *         full_address_text?: string|null
     *     },
     *     items: list<array{
     *         product_variant_id: int,
     *         quantity: int,
     *         unit_selling_price: float|string
     *     }>,
     *     discount_amount?: float|string,
     *     courier_fee_amount?: float|string,
     *     reseller_commission_amount?: float|string,
     *     supplier_commission_amount?: float|string,
     *     company_commission_amount?: float|string,
     *     agent_commission_amount?: float|string,
     *     after_hours?: bool,
     *     after_hours_warning_shown?: bool,
     *     after_hours_penalty_amount?: float|string,
     *     duplicate_warning_shown?: bool,
     *     duplicate_warning_overridden?: bool,
     *     duplicate_reference_order_id?: int|null,
     *     duplicate_order_penalty_amount?: float|string,
     *     return_penalty_amount?: float|string,
     *     created_by?: int|null,
     *     evaluated_at?: CarbonImmutable|null
     * }  $payload
     */
    public function create(array $payload): Order
    {
        return DB::transaction(function () use ($payload) {
            $market = Market::query()->with('currency')->findOrFail((int) $payload['market_id']);
            $currency = $market->currency ?? Currency::query()->findOrFail((int) $market->currency_id);

            $context = $this->authorizationGuard->assertResellerContext(
                (int) $payload['reseller_id'],
                (int) $payload['reseller_company_id'],
            );

            $this->authorizationGuard->assertMarketAccess($context['company'], (int) $market->id);
            $this->authorizationGuard->assertSupplierAssigned($context['reseller'], (int) $payload['supplier_id']);

            $preparedItems = $this->prepareItems(
                items: $payload['items'] ?? [],
                supplierId: (int) $payload['supplier_id'],
                marketId: (int) $market->id,
            );

            $itemsSubtotal = $preparedItems['items_subtotal'];
            $totalWeight = $preparedItems['total_weight'];
            $discountAmount = round((float) ($payload['discount_amount'] ?? 0), 2);
            $courierFeeAmount = round((float) ($payload['courier_fee_amount'] ?? 0), 2);

            $this->assertPayableAmounts($itemsSubtotal, $discountAmount, $courierFeeAmount);

            $customerPayable = round($itemsSubtotal - $discountAmount + $courierFeeAmount, 2);

            $evaluatedAt = $payload['evaluated_at'] ?? null;
            if ($evaluatedAt !== null && ! $evaluatedAt instanceof CarbonImmutable) {
                $evaluatedAt = CarbonImmutable::parse($evaluatedAt);
            }

            $afterHours = $this->afterHoursDeterminationService->determine($market, $evaluatedAt);

            $source = $payload['source'] ?? OrderSource::MANUAL;
            if (is_string($source)) {
                $source = OrderSource::from($source);
            }

            // Identity resolution happens inside the same transaction so later
            // failures (duplicate gate, CCA eligibility) roll back customer/phone rows.
            $identity = $this->customerIdentityService->resolveOrCreate([
                ...$payload['customer'],
                'created_by' => $payload['created_by'] ?? null,
            ]);

            $customer = $identity['customer'];

            $variantIds = array_map(
                static fn (array $line) => (int) $line['product_variant_id'],
                $preparedItems['lines'],
            );

            $duplicates = $this->findPotentialDuplicates((int) $customer->id, $variantIds);
            $duplicateMeta = $this->resolveDuplicateMeta($duplicates, $payload);

            if (! empty($payload['cca_id'])) {
                $this->ccaEligibilityService->assertEligible(
                    (int) $payload['cca_id'],
                    (int) $payload['reseller_company_id'],
                );
            }

            $availableInPool = (bool) ($payload['available_in_pool'] ?? false);
            $ccaId = ! empty($payload['cca_id']) ? (int) $payload['cca_id'] : null;

            // Invariant: pool membership is only valid while unassigned.
            if ($ccaId !== null) {
                $availableInPool = false;
            }

            $order = Order::query()->create([
                'source' => $source,
                'status' => OrderStatus::PENDING,
                'market_id' => $market->id,
                'currency_id' => $currency->id,
                'market_code_snapshot' => $market->code,
                'currency_code_snapshot' => $currency->iso_code,
                'reseller_id' => (int) $payload['reseller_id'],
                'reseller_company_id' => (int) $payload['reseller_company_id'],
                'supplier_id' => (int) $payload['supplier_id'],
                'customer_id' => $customer->id,
                'cca_id' => $ccaId,
                'available_in_pool' => $availableInPool,
                'customer_name_snapshot' => $payload['customer']['display_name'],
                'primary_phone_snapshot' => (string) $payload['customer']['primary_phone'],
                'secondary_phone_snapshot' => $payload['customer']['secondary_phone'] ?? null,
                'primary_phone_country_id' => (int) $payload['customer']['primary_phone_country_id'],
                'secondary_phone_country_id' => isset($payload['customer']['secondary_phone'])
                    ? (int) ($payload['customer']['secondary_phone_country_id'] ?? $payload['customer']['primary_phone_country_id'])
                    : null,
                'items_subtotal' => $itemsSubtotal,
                'discount_amount' => $discountAmount,
                'courier_fee_amount' => $courierFeeAmount,
                'customer_payable_amount' => $customerPayable,
                'total_weight' => $totalWeight,
                'reseller_commission_amount' => round((float) ($payload['reseller_commission_amount'] ?? 0), 2),
                'supplier_commission_amount' => round((float) ($payload['supplier_commission_amount'] ?? 0), 2),
                'company_commission_amount' => round((float) ($payload['company_commission_amount'] ?? 0), 2),
                'agent_commission_amount' => round((float) ($payload['agent_commission_amount'] ?? 0), 2),
                'after_hours' => $afterHours['after_hours'],
                'after_hours_warning_shown' => (bool) ($payload['after_hours_warning_shown'] ?? false),
                'after_hours_penalty_amount' => $afterHours['after_hours_penalty_amount'],
                'duplicate_warning_shown' => $duplicateMeta['duplicate_warning_shown'],
                'duplicate_warning_overridden' => $duplicateMeta['duplicate_warning_overridden'],
                'duplicate_reference_order_id' => $duplicateMeta['duplicate_reference_order_id'],
                'duplicate_order_penalty_amount' => round((float) ($payload['duplicate_order_penalty_amount'] ?? 0), 2),
                'return_penalty_amount' => round((float) ($payload['return_penalty_amount'] ?? 0), 2),
                'created_by' => $payload['created_by'] ?? null,
                'updated_by' => $payload['created_by'] ?? null,
            ]);

            $order->update([
                'order_number' => $this->generateOrderNumber((int) $order->id),
            ]);

            foreach ($preparedItems['lines'] as $line) {
                OrderItem::query()->create([
                    ...$line,
                    'order_id' => $order->id,
                ]);
            }

            $address = $payload['address'];
            OrderAddress::query()->create([
                'order_id' => $order->id,
                'recipient_name' => $address['recipient_name'],
                'line1' => $address['line1'],
                'line2' => $address['line2'] ?? null,
                'city_name' => $address['city_name'],
                'district_name' => $address['district_name'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'country_id' => (int) $address['country_id'],
                'full_address_text' => $address['full_address_text'] ?? null,
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::PENDING,
                'changed_by' => $payload['created_by'] ?? null,
                'changed_by_company_id' => $payload['reseller_company_id'] ?? null,
                'reason' => 'Order created',
            ]);

            return $order->fresh($this->defaultRelations());
        });
    }

    public function generateOrderNumber(int $id): string
    {
        return sprintf('ORD-%d-%06d', now()->year, $id);
    }

    /**
     * Find incomplete orders that share the customer and any of the given variants.
     *
     * @param  list<int>  $productVariantIds
     * @return list<Order>
     */
    public function findPotentialDuplicates(int $customerId, array $productVariantIds): array
    {
        if ($productVariantIds === []) {
            return [];
        }

        return Order::query()
            ->incomplete()
            ->where('customer_id', $customerId)
            ->whereHas('items', function ($query) use ($productVariantIds) {
                $query->whereIn('product_variant_id', $productVariantIds);
            })
            ->with(['items'])
            ->get()
            ->all();
    }

    /**
     * Domain write path for quantity changes. Locked after successful shipment booking.
     */
    public function updateItemQuantity(
        Order $order,
        int $orderItemId,
        int $quantity,
        ?int $updatedBy = null,
        ?int $actorCompanyId = null,
    ): Order {
        return DB::transaction(function () use ($order, $orderItemId, $quantity, $updatedBy, $actorCompanyId) {
            $order = Order::query()->with(['items', 'shipment'])->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->shipmentLockService->assertOrderItemsMutable($order);

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    'quantity' => ['Quantity must be a positive integer.'],
                ]);
            }

            $item = $order->items->firstWhere('id', $orderItemId);

            if ($item === null) {
                throw ValidationException::withMessages([
                    'order_item_id' => ['The selected order item is invalid.'],
                ]);
            }

            $unitSellingPrice = (float) $item->unit_selling_price;
            $unitWeight = (float) $item->unit_weight_snapshot;

            $item->update([
                'quantity' => $quantity,
                'line_selling_total' => round($unitSellingPrice * $quantity, 2),
                'line_weight_total' => round($unitWeight * $quantity, 3),
            ]);

            $this->recalculateOrderTotals($order, $updatedBy);

            return $order->fresh($this->defaultRelations());
        });
    }

    /**
     * Domain write path for product/variant replacement. Locked after shipment booking.
     */
    public function replaceItemVariant(
        Order $order,
        int $orderItemId,
        int $productVariantId,
        ?int $updatedBy = null,
        ?int $actorCompanyId = null,
    ): Order {
        return DB::transaction(function () use ($order, $orderItemId, $productVariantId, $updatedBy, $actorCompanyId) {
            $order = Order::query()->with(['items', 'shipment'])->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->shipmentLockService->assertOrderItemsMutable($order);

            $item = $order->items->firstWhere('id', $orderItemId);

            if ($item === null) {
                throw ValidationException::withMessages([
                    'order_item_id' => ['The selected order item is invalid.'],
                ]);
            }

            $variant = ProductVariant::query()->with('product')->find($productVariantId);

            if ($variant === null || $variant->product === null) {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['The selected product variant is invalid.'],
                ]);
            }

            if ((int) $variant->product->supplier_id !== (int) $order->supplier_id) {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['Order items must belong to the order supplier.'],
                ]);
            }

            if ((int) $variant->product->market_id !== (int) $order->market_id) {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['Product market must match the order market.'],
                ]);
            }

            $quantity = (int) $item->quantity;
            $unitSellingPrice = round((float) $variant->selling_price, 2);
            $unitWeight = round((float) ($variant->weight ?? 0), 3);

            $item->update([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $variant->product->name,
                'variant_name_snapshot' => $variant->name,
                'barcode_snapshot' => $variant->barcode,
                'unit_selling_price' => $unitSellingPrice,
                'unit_cost_snapshot' => $variant->cost,
                'unit_company_commission_snapshot' => $variant->company_commission,
                'unit_weight_snapshot' => $unitWeight,
                'line_selling_total' => round($unitSellingPrice * $quantity, 2),
                'line_weight_total' => round($unitWeight * $quantity, 3),
            ]);

            $this->recalculateOrderTotals($order, $updatedBy);

            return $order->fresh($this->defaultRelations());
        });
    }

    /**
     * @param  list<array{product_variant_id: int, quantity: int, unit_selling_price: float|string}>  $items
     * @return array{lines: list<array<string, mixed>>, items_subtotal: float, total_weight: float}
     */
    protected function prepareItems(array $items, int $supplierId, int $marketId): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['At least one order item is required.'],
            ]);
        }

        $variantIds = collect($items)->pluck('product_variant_id');

        if ($variantIds->count() !== $variantIds->unique()->count()) {
            throw ValidationException::withMessages([
                'items' => ['Each product variant can only appear once on an order.'],
            ]);
        }

        $lines = [];
        $itemsSubtotal = 0.0;
        $totalWeight = 0.0;
        $resolvedSupplierIds = [];

        foreach ($items as $index => $item) {
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['Quantity must be a positive integer.'],
                ]);
            }

            $variant = ProductVariant::query()
                ->with('product')
                ->find($item['product_variant_id'] ?? null);

            if ($variant === null || $variant->product === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => ['The selected product variant is invalid.'],
                ]);
            }

            if ((int) $variant->product_id !== (int) $variant->product->id) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => ['The selected product variant does not belong to its product.'],
                ]);
            }

            $variantSupplierId = (int) $variant->product->supplier_id;
            $resolvedSupplierIds[$variantSupplierId] = true;

            if ($variantSupplierId !== $supplierId) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => ['Order items must belong to the order supplier.'],
                ]);
            }

            if ((int) $variant->product->market_id !== $marketId) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => ['Product market must match the order market.'],
                ]);
            }

            $unitSellingPrice = round((float) $item['unit_selling_price'], 2);
            $unitWeight = round((float) ($variant->weight ?? 0), 3);
            $lineSellingTotal = round($unitSellingPrice * $quantity, 2);
            $lineWeightTotal = round($unitWeight * $quantity, 3);

            $itemsSubtotal = round($itemsSubtotal + $lineSellingTotal, 2);
            $totalWeight = round($totalWeight + $lineWeightTotal, 3);

            $lines[] = [
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $variant->product->name,
                'variant_name_snapshot' => $variant->name,
                'barcode_snapshot' => $variant->barcode,
                'quantity' => $quantity,
                'unit_selling_price' => $unitSellingPrice,
                'unit_cost_snapshot' => $variant->cost,
                'unit_company_commission_snapshot' => $variant->company_commission,
                'unit_weight_snapshot' => $unitWeight,
                'line_selling_total' => $lineSellingTotal,
                'line_weight_total' => $lineWeightTotal,
            ];
        }

        if (count($resolvedSupplierIds) > 1) {
            throw ValidationException::withMessages([
                'items' => ['An order cannot contain variants from different suppliers.'],
            ]);
        }

        return [
            'lines' => $lines,
            'items_subtotal' => $itemsSubtotal,
            'total_weight' => $totalWeight,
        ];
    }

    protected function assertPayableAmounts(float $itemsSubtotal, float $discountAmount, float $courierFeeAmount): void
    {
        if ($discountAmount < 0) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount cannot be negative.'],
            ]);
        }

        if ($discountAmount > $itemsSubtotal) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount cannot exceed the items subtotal.'],
            ]);
        }

        $customerPayable = round($itemsSubtotal - $discountAmount + $courierFeeAmount, 2);

        if ($customerPayable < 0) {
            throw ValidationException::withMessages([
                'customer_payable_amount' => ['Customer payable cannot be negative.'],
            ]);
        }
    }

    /**
     * @param  list<Order>  $duplicates
     * @param  array<string, mixed>  $payload
     * @return array{
     *     duplicate_warning_shown: bool,
     *     duplicate_warning_overridden: bool,
     *     duplicate_reference_order_id: int|null
     * }
     */
    protected function resolveDuplicateMeta(array $duplicates, array $payload): array
    {
        if ($duplicates === []) {
            return [
                'duplicate_warning_shown' => false,
                'duplicate_warning_overridden' => false,
                'duplicate_reference_order_id' => null,
            ];
        }

        $explicitOverride = (bool) ($payload['duplicate_warning_overridden'] ?? false);

        if (! $explicitOverride) {
            throw DuplicateOrderWarningException::fromDuplicates($duplicates);
        }

        return [
            'duplicate_warning_shown' => true,
            'duplicate_warning_overridden' => true,
            'duplicate_reference_order_id' => $duplicates[0]->id,
        ];
    }

    protected function recalculateOrderTotals(Order $order, ?int $updatedBy = null): void
    {
        $order->load('items');

        $itemsSubtotal = round((float) $order->items->sum(fn (OrderItem $item) => (float) $item->line_selling_total), 2);
        $totalWeight = round((float) $order->items->sum(fn (OrderItem $item) => (float) $item->line_weight_total), 3);
        $discountAmount = round((float) $order->discount_amount, 2);
        $courierFeeAmount = round((float) $order->courier_fee_amount, 2);

        $this->assertPayableAmounts($itemsSubtotal, $discountAmount, $courierFeeAmount);

        $order->update([
            'items_subtotal' => $itemsSubtotal,
            'total_weight' => $totalWeight,
            'customer_payable_amount' => round($itemsSubtotal - $discountAmount + $courierFeeAmount, 2),
            'updated_by' => $updatedBy,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function defaultRelations(): array
    {
        return [
            'customer',
            'items',
            'address',
            'statusHistories',
            'shipment',
            'market',
            'currency',
        ];
    }
}
