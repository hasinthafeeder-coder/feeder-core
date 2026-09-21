<?php

namespace Feeder\Core\Services\Courier\Fardar;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Test-environment cleanup of Fardar Express location master data and the
 * disposable business rows that reference those locations.
 *
 * Scope:
 * - Delete Fardar-dependent test orders/shipments (and their dependents)
 * - Delete ALL courier_states / courier_cities owned by the unique FARDAR courier
 * - Never mutate other couriers, market pricing, credentials, or the FARDAR courier row
 *
 * Hierarchy (Fardar CSV mapping):
 * - District → courier_states
 * - City → courier_cities
 */
class FardarLocationCleanupService
{
    public const COURIER_CODE = 'FARDAR';

    public const BASELINE_ROYAL = 'ROYAL';

    public const BASELINE_TRANSEXPRESS = 'TRANSEXPRESS';

    /**
     * Tables/columns allowed to reference courier_cities / courier_states.
     * Anything else with live Fardar location FKs refuses cleanup.
     *
     * @var list<array{table: string, column: string, referenced: string}>
     */
    private const KNOWN_LOCATION_REFERENCES = [
        ['table' => 'orders', 'column' => 'draft_courier_city_id', 'referenced' => 'courier_cities'],
        ['table' => 'shipments', 'column' => 'courier_city_id', 'referenced' => 'courier_cities'],
        ['table' => 'courier_cities', 'column' => 'courier_state_id', 'referenced' => 'courier_states'],
    ];

    /**
     * Order child tables deleted before parent orders (schema cascade exists;
     * we delete explicitly for deterministic ordering and accurate counts).
     *
     * @var list<array{table: string, column: string}>
     */
    private const ORDER_DEPENDENT_TABLES = [
        ['table' => 'order_payment_submissions', 'column' => 'order_id'],
        ['table' => 'order_comments', 'column' => 'order_id'],
        ['table' => 'order_cca_assignments', 'column' => 'order_id'],
        ['table' => 'order_status_histories', 'column' => 'order_id'],
        ['table' => 'order_addresses', 'column' => 'order_id'],
        ['table' => 'order_items', 'column' => 'order_id'],
    ];

    /**
     * Resolve the single Fardar courier row or fail closed.
     */
    public function resolveFardarCourier(): Courier
    {
        $matches = $this->findFardarCandidates();

        if ($matches->count() === 0) {
            throw new RuntimeException(
                'Fardar courier cannot be identified: no courier with code FARDAR was found.'
            );
        }

        if ($matches->count() > 1) {
            $ids = $matches->pluck('id')->implode(', ');

            throw new RuntimeException(
                'Fardar courier cannot be uniquely identified: multiple couriers match code FARDAR (ids: '.$ids.').'
            );
        }

        /** @var Courier $courier */
        $courier = $matches->first();

        if (strcasecmp((string) $courier->code, self::COURIER_CODE) !== 0) {
            throw new RuntimeException(
                'Fardar courier resolve failed safety check: resolved code is not FARDAR.'
            );
        }

        return $courier;
    }

    /**
     * @return Collection<int, Courier>
     */
    protected function findFardarCandidates(): Collection
    {
        return Courier::query()
            ->whereRaw('UPPER(TRIM(code)) = ?', [self::COURIER_CODE])
            ->orderBy('id')
            ->get();
    }

    /**
     * Build a full audit snapshot (counts, blockers, baselines). Does not mutate data.
     *
     * @return array{
     *     courier: Courier,
     *     states: int,
     *     districts: int,
     *     cities: int,
     *     dependencies: array{
     *         order_ids: list<int>,
     *         shipment_ids: list<int>,
     *         orders: int,
     *         shipments: int,
     *         dependent_records: int,
     *         dependents_by_table: array<string, int>
     *     },
     *     shared_violations: list<string>,
     *     unexpected_blockers: list<array{table: string, column: string, count: int, sample_ids: list<int>, reason: string}>,
     *     baselines: array<string, array{courier_id: int|null, code: string, states: int, cities: int}>,
     *     safe_to_execute: bool
     * }
     */
    public function audit(): array
    {
        $courier = $this->resolveFardarCourier();
        $courierId = (int) $courier->id;

        $states = CourierState::query()->where('courier_id', $courierId)->count();
        $cities = CourierCity::query()->where('courier_id', $courierId)->count();

        $sharedViolations = $this->detectSharedViolations($courierId);
        $dependencies = $this->discoverDependencies($courierId);
        $unexpectedBlockers = $this->detectUnexpectedBlockers($courierId, $dependencies);
        $baselines = $this->captureBaselines();

        return [
            'courier' => $courier,
            'states' => $states,
            'districts' => $states,
            'cities' => $cities,
            'dependencies' => $dependencies,
            'shared_violations' => $sharedViolations,
            'unexpected_blockers' => $unexpectedBlockers,
            'baselines' => $baselines,
            'safe_to_execute' => $sharedViolations === [] && $unexpectedBlockers === [],
        ];
    }

    /**
     * Execute scoped cleanup inside a DB transaction after safety gates.
     *
     * @return array{
     *     courier: Courier,
     *     before: array{states: int, districts: int, cities: int, orders: int, shipments: int, dependent_records: int},
     *     deleted: array{orders: int, shipments: int, dependent_records: int, cities: int, states: int, dependents_by_table: array<string, int>},
     *     after: array{states: int, districts: int, cities: int, orders: int, shipments: int},
     *     baselines_before: array<string, array{courier_id: int|null, code: string, states: int, cities: int}>,
     *     baselines_after: array<string, array{courier_id: int|null, code: string, states: int, cities: int}>,
     *     other_couriers_unchanged: bool,
     *     fardar_courier_exists: bool
     * }
     */
    public function execute(): array
    {
        $audit = $this->audit();
        $courier = $audit['courier'];
        $courierId = (int) $courier->id;

        if ($audit['shared_violations'] !== []) {
            throw new RuntimeException(
                'Refusing Fardar location cleanup: shared location records detected. '
                .implode(' ', $audit['shared_violations'])
            );
        }

        if ($audit['unexpected_blockers'] !== []) {
            $details = [];
            foreach ($audit['unexpected_blockers'] as $blocker) {
                $details[] = sprintf(
                    '%s.%s=%d (%s; sample ids: %s)',
                    $blocker['table'],
                    $blocker['column'],
                    $blocker['count'],
                    $blocker['reason'],
                    $blocker['sample_ids'] === [] ? 'n/a' : implode(', ', $blocker['sample_ids'])
                );
            }

            throw new RuntimeException(
                'Refusing Fardar location cleanup: unexpected dependencies detected. '
                .'Will not blindly delete unrelated data. Resolve: '
                .implode('; ', $details)
            );
        }

        $before = [
            'states' => $audit['states'],
            'districts' => $audit['districts'],
            'cities' => $audit['cities'],
            'orders' => $audit['dependencies']['orders'],
            'shipments' => $audit['dependencies']['shipments'],
            'dependent_records' => $audit['dependencies']['dependent_records'],
        ];
        $baselinesBefore = $audit['baselines'];
        $orderIds = $audit['dependencies']['order_ids'];
        $shipmentIds = $audit['dependencies']['shipment_ids'];

        try {
            $deleted = $this->deleteScopedCleanup($courierId, $orderIds, $shipmentIds);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Fardar location cleanup failed and was rolled back: '.$e->getMessage(),
                previous: $e
            );
        }

        $afterStates = CourierState::query()->where('courier_id', $courierId)->count();
        $afterCities = CourierCity::query()->where('courier_id', $courierId)->count();
        $remainingOrders = $this->countOrdersReferencingFardarCities($courierId);
        $remainingShipments = $this->countShipmentsReferencingFardarCities($courierId);
        $baselinesAfter = $this->captureBaselines();
        $otherUnchanged = $this->baselinesUnchanged($baselinesBefore, $baselinesAfter);
        $courierStillExists = Courier::query()
            ->where('id', $courierId)
            ->whereRaw('UPPER(TRIM(code)) = ?', [self::COURIER_CODE])
            ->exists();

        if ($afterStates !== 0 || $afterCities !== 0) {
            throw new RuntimeException(
                'Post-deletion verification failed: Fardar location rows remain '
                ."(states={$afterStates}, cities={$afterCities})."
            );
        }

        if ($remainingOrders > 0 || $remainingShipments > 0) {
            throw new RuntimeException(
                'Post-deletion verification failed: Fardar location business references remain '
                ."(orders={$remainingOrders}, shipments={$remainingShipments})."
            );
        }

        if (! $courierStillExists) {
            throw new RuntimeException(
                'Post-deletion verification failed: FARDAR courier row is missing.'
            );
        }

        if (! $otherUnchanged) {
            throw new RuntimeException(
                'Post-deletion verification failed: other courier location counts changed unexpectedly.'
            );
        }

        return [
            'courier' => $courier,
            'before' => $before,
            'deleted' => $deleted,
            'after' => [
                'states' => $afterStates,
                'districts' => $afterStates,
                'cities' => $afterCities,
                'orders' => $remainingOrders,
                'shipments' => $remainingShipments,
            ],
            'baselines_before' => $baselinesBefore,
            'baselines_after' => $baselinesAfter,
            'other_couriers_unchanged' => $otherUnchanged,
            'fardar_courier_exists' => $courierStillExists,
        ];
    }

    /**
     * @param  list<int>  $orderIds
     * @param  list<int>  $shipmentIds
     * @return array{orders: int, shipments: int, dependent_records: int, cities: int, states: int, dependents_by_table: array<string, int>}
     */
    protected function deleteScopedCleanup(int $courierId, array $orderIds, array $shipmentIds): array
    {
        if ($courierId <= 0) {
            throw new RuntimeException('Deletion refused: courier_id scope is missing.');
        }

        $courier = Courier::query()->find($courierId);
        if ($courier === null || strcasecmp((string) $courier->code, self::COURIER_CODE) !== 0) {
            throw new RuntimeException(
                'Deletion refused: courier_id '.$courierId.' is not the FARDAR courier.'
            );
        }

        return DB::transaction(function () use ($courierId, $orderIds, $shipmentIds): array {
            $dependentsByTable = [];
            $dependentRecords = 0;

            if ($shipmentIds !== []) {
                $eventsDeleted = (int) DB::table('shipment_events')
                    ->whereIn('shipment_id', $shipmentIds)
                    ->delete();
                $dependentsByTable['shipment_events'] = $eventsDeleted;
                $dependentRecords += $eventsDeleted;

                $shipmentsDeleted = (int) DB::table('shipments')
                    ->whereIn('id', $shipmentIds)
                    ->delete();
            } else {
                $shipmentsDeleted = 0;
            }

            if ($orderIds !== []) {
                foreach (self::ORDER_DEPENDENT_TABLES as $dep) {
                    if (! Schema::hasTable($dep['table'])) {
                        continue;
                    }

                    $deleted = (int) DB::table($dep['table'])
                        ->whereIn($dep['column'], $orderIds)
                        ->delete();

                    if ($deleted > 0) {
                        $dependentsByTable[$dep['table']] = $deleted;
                        $dependentRecords += $deleted;
                    }
                }

                // Preserve import-row history; schema nulls the FK on order delete.
                if (Schema::hasTable('order_import_rows')) {
                    $nulled = (int) DB::table('order_import_rows')
                        ->whereIn('created_order_id', $orderIds)
                        ->update(['created_order_id' => null]);
                    if ($nulled > 0) {
                        $dependentsByTable['order_import_rows_nulled'] = $nulled;
                        $dependentRecords += $nulled;
                    }
                }

                if (Schema::hasColumn('orders', 'duplicate_reference_order_id')) {
                    $cleared = (int) DB::table('orders')
                        ->whereIn('duplicate_reference_order_id', $orderIds)
                        ->update(['duplicate_reference_order_id' => null]);
                    if ($cleared > 0) {
                        $dependentsByTable['orders_duplicate_refs_cleared'] = $cleared;
                        $dependentRecords += $cleared;
                    }
                }

                $ordersDeleted = (int) DB::table('orders')
                    ->whereIn('id', $orderIds)
                    ->delete();
            } else {
                $ordersDeleted = 0;
            }

            $citiesQuery = CourierCity::query()->where('courier_id', $courierId);
            $this->assertQueryScopedToCourier($citiesQuery->toBase()->toSql());
            $citiesDeleted = (int) $citiesQuery->delete();

            $this->maybeFailAfterCitiesDeletedForTesting();

            $statesQuery = CourierState::query()->where('courier_id', $courierId);
            $this->assertQueryScopedToCourier($statesQuery->toBase()->toSql());
            $statesDeleted = (int) $statesQuery->delete();

            return [
                'orders' => $ordersDeleted,
                'shipments' => $shipmentsDeleted,
                'dependent_records' => $dependentRecords,
                'cities' => $citiesDeleted,
                'states' => $statesDeleted,
                'dependents_by_table' => $dependentsByTable,
            ];
        });
    }

    /**
     * Test seam: when config feeder.fardar_cleanup_force_fail_after_cities is true,
     * throw after city deletion so the surrounding transaction rolls back.
     */
    protected function maybeFailAfterCitiesDeletedForTesting(): void
    {
        if (config('feeder.fardar_cleanup_force_fail_after_cities') === true) {
            throw new RuntimeException('Forced failure after city deletion (test seam).');
        }
    }

    private function assertQueryScopedToCourier(string $sql): void
    {
        $normalized = strtolower($sql);

        if (! str_contains($normalized, 'courier_id')) {
            throw new RuntimeException(
                'Deletion refused: query is not scoped to courier_id (SQL safety check).'
            );
        }
    }

    /**
     * Dynamically discover disposable Fardar-linked business records via city FKs.
     *
     * @return array{
     *     order_ids: list<int>,
     *     shipment_ids: list<int>,
     *     orders: int,
     *     shipments: int,
     *     dependent_records: int,
     *     dependents_by_table: array<string, int>
     * }
     */
    private function discoverDependencies(int $fardarCourierId): array
    {
        $cityIds = CourierCity::query()
            ->where('courier_id', $fardarCourierId)
            ->pluck('id');

        if ($cityIds->isEmpty()) {
            return [
                'order_ids' => [],
                'shipment_ids' => [],
                'orders' => 0,
                'shipments' => 0,
                'dependent_records' => 0,
                'dependents_by_table' => [],
            ];
        }

        $shipmentIdsFromCity = DB::table('shipments')
            ->whereIn('courier_city_id', $cityIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id);

        $orderIdsFromDraft = DB::table('orders')
            ->whereIn('draft_courier_city_id', $cityIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id);

        $orderIdsFromShipments = $shipmentIdsFromCity->isEmpty()
            ? collect()
            : DB::table('shipments')
                ->whereIn('id', $shipmentIdsFromCity)
                ->pluck('order_id')
                ->map(static fn ($id): int => (int) $id);

        $orderIds = $orderIdsFromDraft
            ->merge($orderIdsFromShipments)
            ->unique()
            ->sort()
            ->values();

        // Any shipment belonging to a Fardar-linked order must be included (RESTRICT on orders).
        $shipmentIds = $orderIds->isEmpty()
            ? $shipmentIdsFromCity->values()
            : DB::table('shipments')
                ->whereIn('order_id', $orderIds)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->merge($shipmentIdsFromCity)
                ->unique()
                ->sort()
                ->values();

        $dependentsByTable = [];
        $dependentRecords = 0;

        if ($shipmentIds->isNotEmpty()) {
            $events = (int) DB::table('shipment_events')
                ->whereIn('shipment_id', $shipmentIds)
                ->count();
            if ($events > 0) {
                $dependentsByTable['shipment_events'] = $events;
                $dependentRecords += $events;
            }
        }

        if ($orderIds->isNotEmpty()) {
            foreach (self::ORDER_DEPENDENT_TABLES as $dep) {
                if (! Schema::hasTable($dep['table'])) {
                    continue;
                }

                $count = (int) DB::table($dep['table'])
                    ->whereIn($dep['column'], $orderIds)
                    ->count();

                if ($count > 0) {
                    $dependentsByTable[$dep['table']] = $count;
                    $dependentRecords += $count;
                }
            }

            if (Schema::hasTable('order_import_rows')) {
                $importRows = (int) DB::table('order_import_rows')
                    ->whereIn('created_order_id', $orderIds)
                    ->count();
                if ($importRows > 0) {
                    $dependentsByTable['order_import_rows'] = $importRows;
                    $dependentRecords += $importRows;
                }
            }
        }

        return [
            'order_ids' => $orderIds->all(),
            'shipment_ids' => $shipmentIds->all(),
            'orders' => $orderIds->count(),
            'shipments' => $shipmentIds->count(),
            'dependent_records' => $dependentRecords,
            'dependents_by_table' => $dependentsByTable,
        ];
    }

    /**
     * @param  array{
     *     order_ids: list<int>,
     *     shipment_ids: list<int>,
     *     orders: int,
     *     shipments: int,
     *     dependent_records: int,
     *     dependents_by_table: array<string, int>
     * }  $dependencies
     * @return list<array{table: string, column: string, count: int, sample_ids: list<int>, reason: string}>
     */
    private function detectUnexpectedBlockers(int $fardarCourierId, array $dependencies): array
    {
        $blockers = [];
        $cityIds = CourierCity::query()
            ->where('courier_id', $fardarCourierId)
            ->pluck('id');

        if ($cityIds->isEmpty()) {
            return $this->detectUnknownSchemaReferences($fardarCourierId, $cityIds);
        }

        // Shipments referencing Fardar cities must belong to the FARDAR courier.
        $foreignShipments = DB::table('shipments')
            ->whereIn('courier_city_id', $cityIds)
            ->where('courier_id', '!=', $fardarCourierId)
            ->orderBy('id')
            ->limit(10)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $foreignShipmentCount = (int) DB::table('shipments')
            ->whereIn('courier_city_id', $cityIds)
            ->where('courier_id', '!=', $fardarCourierId)
            ->count();

        if ($foreignShipmentCount > 0) {
            $blockers[] = [
                'table' => 'shipments',
                'column' => 'courier_city_id',
                'count' => $foreignShipmentCount,
                'sample_ids' => $foreignShipments,
                'reason' => 'non-FARDAR courier_id referencing Fardar cities',
            ];
        }

        // Orders drafting a Fardar city must draft the FARDAR courier (or null draft courier).
        $foreignOrders = DB::table('orders')
            ->whereIn('draft_courier_city_id', $cityIds)
            ->whereNotNull('draft_courier_id')
            ->where('draft_courier_id', '!=', $fardarCourierId)
            ->orderBy('id')
            ->limit(10)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $foreignOrderCount = (int) DB::table('orders')
            ->whereIn('draft_courier_city_id', $cityIds)
            ->whereNotNull('draft_courier_id')
            ->where('draft_courier_id', '!=', $fardarCourierId)
            ->count();

        if ($foreignOrderCount > 0) {
            $blockers[] = [
                'table' => 'orders',
                'column' => 'draft_courier_city_id',
                'count' => $foreignOrderCount,
                'sample_ids' => $foreignOrders,
                'reason' => 'non-FARDAR draft_courier_id referencing Fardar cities',
            ];
        }

        // Shipments on Fardar-linked orders must themselves be FARDAR.
        if ($dependencies['order_ids'] !== []) {
            $mixedShipments = DB::table('shipments')
                ->whereIn('order_id', $dependencies['order_ids'])
                ->where('courier_id', '!=', $fardarCourierId)
                ->orderBy('id')
                ->limit(10)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $mixedCount = (int) DB::table('shipments')
                ->whereIn('order_id', $dependencies['order_ids'])
                ->where('courier_id', '!=', $fardarCourierId)
                ->count();

            if ($mixedCount > 0) {
                $blockers[] = [
                    'table' => 'shipments',
                    'column' => 'order_id',
                    'count' => $mixedCount,
                    'sample_ids' => $mixedShipments,
                    'reason' => 'Fardar-linked order also has non-FARDAR shipment',
                ];
            }
        }

        return array_merge($blockers, $this->detectUnknownSchemaReferences($fardarCourierId, $cityIds));
    }

    /**
     * @param  Collection<int, int|string>  $cityIds
     * @return list<array{table: string, column: string, count: int, sample_ids: list<int>, reason: string}>
     */
    private function detectUnknownSchemaReferences(int $fardarCourierId, Collection $cityIds): array
    {
        $blockers = [];
        $known = [];
        foreach (self::KNOWN_LOCATION_REFERENCES as $ref) {
            $known[$ref['table'].'.'.$ref['column']] = true;
        }

        $fks = DB::select("
            SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IN ('courier_cities', 'courier_states')
        ");

        $stateIds = CourierState::query()
            ->where('courier_id', $fardarCourierId)
            ->pluck('id');

        foreach ($fks as $fk) {
            $key = $fk->TABLE_NAME.'.'.$fk->COLUMN_NAME;
            if (isset($known[$key])) {
                continue;
            }

            $ids = $fk->REFERENCED_TABLE_NAME === 'courier_cities' ? $cityIds : $stateIds;
            if ($ids->isEmpty()) {
                continue;
            }

            if (! Schema::hasTable($fk->TABLE_NAME) || ! Schema::hasColumn($fk->TABLE_NAME, $fk->COLUMN_NAME)) {
                continue;
            }

            $count = (int) DB::table($fk->TABLE_NAME)
                ->whereIn($fk->COLUMN_NAME, $ids)
                ->count();

            if ($count === 0) {
                continue;
            }

            $samples = DB::table($fk->TABLE_NAME)
                ->whereIn($fk->COLUMN_NAME, $ids)
                ->orderBy('id')
                ->limit(10)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $blockers[] = [
                'table' => (string) $fk->TABLE_NAME,
                'column' => (string) $fk->COLUMN_NAME,
                'count' => $count,
                'sample_ids' => $samples,
                'reason' => 'unknown FK to '.$fk->REFERENCED_TABLE_NAME.' (not in cleanup allow-list)',
            ];
        }

        return $blockers;
    }

    /**
     * @return list<string>
     */
    private function detectSharedViolations(int $fardarCourierId): array
    {
        $violations = [];

        $fardarCitiesWrongState = (int) DB::table('courier_cities as cc')
            ->join('courier_states as cs', 'cs.id', '=', 'cc.courier_state_id')
            ->where('cc.courier_id', $fardarCourierId)
            ->where('cs.courier_id', '!=', $fardarCourierId)
            ->count();

        if ($fardarCitiesWrongState > 0) {
            $violations[] = "Fardar courier_cities pointing at non-Fardar courier_states: {$fardarCitiesWrongState}.";
        }

        $otherCitiesOnFardarStates = (int) DB::table('courier_cities')
            ->where('courier_id', '!=', $fardarCourierId)
            ->whereIn('courier_state_id', function ($query) use ($fardarCourierId): void {
                $query->select('id')
                    ->from('courier_states')
                    ->where('courier_id', $fardarCourierId);
            })
            ->count();

        if ($otherCitiesOnFardarStates > 0) {
            $violations[] = "Non-Fardar courier_cities pointing at Fardar courier_states: {$otherCitiesOnFardarStates}.";
        }

        return $violations;
    }

    private function countOrdersReferencingFardarCities(int $fardarCourierId): int
    {
        $cityIds = CourierCity::query()->where('courier_id', $fardarCourierId)->pluck('id');
        if ($cityIds->isEmpty()) {
            return 0;
        }

        return (int) DB::table('orders')->whereIn('draft_courier_city_id', $cityIds)->count();
    }

    private function countShipmentsReferencingFardarCities(int $fardarCourierId): int
    {
        $cityIds = CourierCity::query()->where('courier_id', $fardarCourierId)->pluck('id');
        if ($cityIds->isEmpty()) {
            // Cities already gone — also ensure no orphaned shipment FKs linger via leftover city IDs.
            return 0;
        }

        return (int) DB::table('shipments')->whereIn('courier_city_id', $cityIds)->count();
    }

    /**
     * @return array<string, array{courier_id: int|null, code: string, states: int, cities: int}>
     */
    private function captureBaselines(): array
    {
        $baselines = [];

        foreach ([self::BASELINE_ROYAL, self::BASELINE_TRANSEXPRESS] as $code) {
            $baselines[$code] = $this->countsForCourierCode($code);
        }

        $fardar = Courier::query()
            ->whereRaw('UPPER(TRIM(code)) = ?', [self::COURIER_CODE])
            ->value('id');

        $otherCourierIds = Courier::query()
            ->when($fardar !== null, static fn ($q) => $q->where('id', '!=', (int) $fardar))
            ->pluck('id');

        $baselines['OTHER'] = [
            'courier_id' => null,
            'code' => 'OTHER',
            'states' => CourierState::query()->whereIn('courier_id', $otherCourierIds)->count(),
            'cities' => CourierCity::query()->whereIn('courier_id', $otherCourierIds)->count(),
        ];

        return $baselines;
    }

    /**
     * @return array{courier_id: int|null, code: string, states: int, cities: int}
     */
    private function countsForCourierCode(string $code): array
    {
        $courier = Courier::query()
            ->whereRaw('UPPER(TRIM(code)) = ?', [strtoupper($code)])
            ->first();

        if ($courier === null) {
            return [
                'courier_id' => null,
                'code' => strtoupper($code),
                'states' => 0,
                'cities' => 0,
            ];
        }

        return [
            'courier_id' => (int) $courier->id,
            'code' => (string) $courier->code,
            'states' => CourierState::query()->where('courier_id', $courier->id)->count(),
            'cities' => CourierCity::query()->where('courier_id', $courier->id)->count(),
        ];
    }

    /**
     * @param  array<string, array{courier_id: int|null, code: string, states: int, cities: int}>  $before
     * @param  array<string, array{courier_id: int|null, code: string, states: int, cities: int}>  $after
     */
    private function baselinesUnchanged(array $before, array $after): bool
    {
        foreach ($before as $key => $snapshot) {
            if (! isset($after[$key])) {
                return false;
            }

            if ((int) $snapshot['states'] !== (int) $after[$key]['states']) {
                return false;
            }

            if ((int) $snapshot['cities'] !== (int) $after[$key]['cities']) {
                return false;
            }
        }

        return true;
    }
}
