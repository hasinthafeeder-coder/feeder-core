<?php

namespace Feeder\Core\Services\IntroducerBonus;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\CourierReceiptImport;
use Feeder\Core\Models\CourierReceiptImportRow;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Qualifying orders for introducer bonus.
 *
 * A qualifying order is courier-confirmed: status DELIVERED plus one consistent
 * MATCHED row on a confirmed receipt import. The business date is the market-local
 * calendar date of that import's confirmed_at. Reseller earning obligations and
 * financial snapshots are not consulted.
 */
class IntroducerBonusQualifyingOrderCollector
{
    public function __construct(
        private readonly MarketBusinessDateResolver $businessDates,
    ) {}

    /**
     * @return list<array{
     *     order_id: int,
     *     order_number: string|null,
     *     reseller_id: int,
     *     business_date: string,
     *     currency_code: string,
     *     import_id: int,
     *     import_row_id: int
     * }>
     */
    public function collect(Market $market, int $year, int $month, string $marketCurrencyCode): array
    {
        $marketCurrencyCode = strtoupper(trim($marketCurrencyCode));

        $orders = Order::query()
            ->where('market_id', $market->id)
            ->where('status', OrderStatus::DELIVERED->value)
            ->whereNotNull('reseller_id')
            ->get(['id', 'order_number', 'reseller_id', 'market_id', 'currency_id', 'currency_code_snapshot'])
            ->keyBy('id');

        if ($orders->isEmpty()) {
            return [];
        }

        $rows = $this->confirmedMatchedRows($orders->keys()->all());
        $rowsByOrder = $rows->groupBy(fn (CourierReceiptImportRow $row): int => (int) $row->matched_order_id);
        $shipments = $this->shipmentsFor($rows);

        $qualifying = [];

        foreach ($rowsByOrder as $orderId => $orderRows) {
            $order = $orders->get((int) $orderId);

            if (! $order instanceof Order) {
                continue;
            }

            $resolved = $this->resolveOrder(
                $market,
                $order,
                $orderRows,
                $shipments,
                $year,
                $month,
                $marketCurrencyCode,
            );

            if ($resolved !== null) {
                $qualifying[] = $resolved;
            }
        }

        usort($qualifying, fn (array $left, array $right): int => $left['order_id'] <=> $right['order_id']);

        return $qualifying;
    }

    /**
     * @param  list<int>  $orderIds
     * @return Collection<int, CourierReceiptImportRow>
     */
    private function confirmedMatchedRows(array $orderIds): Collection
    {
        $rows = collect();

        foreach (array_chunk($orderIds, 500) as $chunk) {
            $chunkRows = CourierReceiptImportRow::query()
                ->with('import')
                ->whereIn('matched_order_id', $chunk)
                ->where('match_result', CourierReceiptImportRowMatchResult::MATCHED->value)
                ->whereHas('import', function ($query): void {
                    $query->where('status', CourierReceiptImportStatus::CONFIRMED->value)
                        ->whereNotNull('confirmed_at');
                })
                ->orderBy('id')
                ->get();

            $rows = $rows->concat($chunkRows);
        }

        return $rows->sortBy('id')->values();
    }

    /**
     * @param  Collection<int, CourierReceiptImportRow>  $rows
     * @return Collection<int, Shipment>
     */
    private function shipmentsFor(Collection $rows): Collection
    {
        $shipmentIds = $rows
            ->pluck('matched_shipment_id')
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($shipmentIds === []) {
            return collect();
        }

        $shipments = collect();

        foreach (array_chunk($shipmentIds, 500) as $chunk) {
            $shipments = $shipments->concat(
                Shipment::query()->whereIn('id', $chunk)->get()
            );
        }

        return $shipments->keyBy('id');
    }

    /**
     * @param  Collection<int, CourierReceiptImportRow>  $orderRows
     * @param  Collection<int, Shipment>  $shipments
     * @return array{
     *     order_id: int,
     *     order_number: string|null,
     *     reseller_id: int,
     *     business_date: string,
     *     currency_code: string,
     *     import_id: int,
     *     import_row_id: int
     * }|null
     */
    private function resolveOrder(
        Market $market,
        Order $order,
        Collection $orderRows,
        Collection $shipments,
        int $year,
        int $month,
        string $marketCurrencyCode,
    ): ?array {
        $consistent = [];
        $inconsistentInMonth = false;

        foreach ($orderRows as $row) {
            $import = $row->import;

            if (! $import instanceof CourierReceiptImport || $import->confirmed_at === null) {
                continue;
            }

            $businessDate = $this->businessDate($market, $import->confirmed_at);
            $shipment = $shipments->get((int) $row->matched_shipment_id);
            $matches = $shipment instanceof Shipment
                && $this->linksAreConsistent($row, $order, $shipment, (int) $import->courier_service_id);

            if (! $matches) {
                if ($this->inMonth($businessDate, $year, $month)) {
                    $inconsistentInMonth = true;
                }

                continue;
            }

            $consistent[] = [
                'date' => $businessDate,
                'row' => $row,
                'import' => $import,
            ];
        }

        $orderLabel = $this->orderLabel($order);

        if ($inconsistentInMonth) {
            throw ValidationException::withMessages([
                'order' => "Order {$orderLabel} has a confirmed courier row that does not match its shipment. The introducer bonus batch was not created.",
            ]);
        }

        if ($consistent === []) {
            return null;
        }

        $dates = array_values(array_unique(array_column($consistent, 'date')));

        if (count($dates) > 1) {
            sort($dates);

            throw ValidationException::withMessages([
                'order' => 'Order '.$orderLabel.' has conflicting confirmed delivery dates ('
                    .implode(' and ', $dates)
                    .'). The introducer bonus batch was not created.',
            ]);
        }

        $businessDate = $dates[0];

        if (! $this->inMonth($businessDate, $year, $month)) {
            return null;
        }

        $currencyCode = $this->orderCurrencyCode($order, $market, $marketCurrencyCode);

        usort($consistent, fn (array $left, array $right): int => (int) $left['row']->id <=> (int) $right['row']->id);
        $chosen = $consistent[0];

        return [
            'order_id' => (int) $order->id,
            'order_number' => $order->order_number !== null ? (string) $order->order_number : null,
            'reseller_id' => (int) $order->reseller_id,
            'business_date' => $businessDate,
            'currency_code' => $currencyCode,
            'import_id' => (int) $chosen['import']->id,
            'import_row_id' => (int) $chosen['row']->id,
        ];
    }

    private function linksAreConsistent(
        CourierReceiptImportRow $row,
        Order $order,
        Shipment $shipment,
        int $importCourierServiceId,
    ): bool {
        if ((int) $row->matched_order_id !== (int) $order->id) {
            return false;
        }

        if ((int) $row->matched_shipment_id !== (int) $shipment->id) {
            return false;
        }

        if ((int) $shipment->order_id !== (int) $order->id) {
            return false;
        }

        if ($importCourierServiceId < 1 || (int) $shipment->courier_service_id !== $importCourierServiceId) {
            return false;
        }

        $tracking = trim((string) ($row->normalized_tracking_number ?? ''));

        return $tracking !== '' && trim((string) $shipment->tracking_number) === $tracking;
    }

    private function orderCurrencyCode(Order $order, Market $market, string $marketCurrencyCode): string
    {
        $orderCode = strtoupper(trim((string) $order->currency_code_snapshot));

        if ($orderCode === '') {
            if ((int) $order->currency_id === (int) $market->currency_id && $marketCurrencyCode !== '') {
                return $marketCurrencyCode;
            }

            throw ValidationException::withMessages([
                'order' => 'Order '.$this->orderLabel($order).' has no currency. The introducer bonus batch was not created.',
            ]);
        }

        if ($orderCode !== $marketCurrencyCode) {
            throw ValidationException::withMessages([
                'order' => "Order {$this->orderLabel($order)} currency {$orderCode} does not match the market currency {$marketCurrencyCode}. The introducer bonus batch was not created.",
            ]);
        }

        return $orderCode;
    }

    private function businessDate(Market $market, CarbonInterface $confirmedAt): string
    {
        try {
            return $this->businessDates->businessDateString($market, $confirmedAt);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'market' => $exception->getMessage(),
            ]);
        }
    }

    private function inMonth(string $businessDate, int $year, int $month): bool
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $businessDate);

        if ($parsed === false) {
            return false;
        }

        return (int) $parsed->year === $year && (int) $parsed->month === $month;
    }

    private function orderLabel(Order $order): string
    {
        $number = trim((string) $order->order_number);

        return $number !== '' ? $number : '#'.$order->id;
    }
}
