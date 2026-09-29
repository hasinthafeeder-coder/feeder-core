<?php

/**
 * Read-only production data audit for legacy unified-status bugs.
 * Run: php artisan orders:audit-legacy-lifecycle
 * Does NOT mutate any rows.
 */

namespace Feeder\Core\Console\Commands;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\SupplierFulfillmentEventType;
use Feeder\Core\Enums\SupplierFulfillmentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditLegacyOrderLifecycleCommand extends Command
{
    protected $signature = 'orders:audit-legacy-lifecycle
                            {--json : Emit machine-readable JSON instead of tables}
                            {--limit=500 : Max rows to list per category (0 = unlimited)}';

    protected $description = 'Read-only audit of orders affected by the old CONFIRMED→PRINTED / missing DISPATCHED lifecycle bugs';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->info('Legacy Order Lifecycle Audit (READ-ONLY)');
        $this->line('Database: '.(string) config('database.connections.'.config('database.default').'.database'));
        $this->line('Generated at: '.now()->toDateTimeString());
        $this->newLine();

        $rows = $this->loadCandidateRows();

        $safe = [];
        $review = [];
        $noAction = [];

        foreach ($rows as $row) {
            $classified = $this->classify($row);
            match ($classified['bucket']) {
                'SAFE_TO_REPAIR' => $safe[] = $classified,
                'NEEDS_MANUAL_REVIEW' => $review[] = $classified,
                default => $noAction[] = $classified,
            };
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at' => now()->toIso8601String(),
                'counts' => [
                    'candidates_scanned' => $rows->count(),
                    'safe_to_repair' => count($safe),
                    'needs_manual_review' => count($review),
                    'no_action' => count($noAction),
                ],
                'safe_to_repair' => $this->limitList($safe, $limit),
                'needs_manual_review' => $this->limitList($review, $limit),
                'no_action' => $this->limitList($noAction, $limit),
                'proposed_repair_sql' => $this->proposedRepairSql($safe),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderSummary($rows->count(), $safe, $review, $noAction);
        $this->renderBucket('SAFE TO REPAIR', $safe, $limit);
        $this->renderBucket('NEEDS MANUAL REVIEW', $review, $limit);
        $this->renderBucket('NO ACTION', $noAction, $limit);
        $this->renderRepairGuidance($safe);

        return self::SUCCESS;
    }

    /**
     * Load supplier-lifecycle-relevant orders with fulfillment + event aggregates.
     *
     * @return Collection<int, object>
     */
    private function loadCandidateRows(): Collection
    {
        $printedEvent = SupplierFulfillmentEventType::PRINTED->value;
        $movedToPrint = SupplierFulfillmentEventType::MOVED_TO_PRINT->value;
        $movedToPackaging = SupplierFulfillmentEventType::MOVED_TO_PACKAGING->value;
        $packageCompleted = SupplierFulfillmentEventType::PACKAGE_COMPLETED->value;
        $dispatchedEvent = SupplierFulfillmentEventType::DISPATCHED->value;
        $stockConsumed = SupplierFulfillmentEventType::STOCK_CONSUMED->value;

        return DB::table('orders as o')
            ->leftJoin('order_fulfillments as f', 'f.order_id', '=', 'o.id')
            ->where(function ($query) {
                $query->whereIn('o.status', [
                    OrderStatus::PRINT->value,
                    OrderStatus::PRINTED->value,
                    OrderStatus::PACKING->value,
                    OrderStatus::DISPATCHED->value,
                ])
                    // Confirmed with any fulfillment (spot warehouse vs order mismatches)
                    ->orWhere(function ($inner) {
                        $inner->where('o.status', OrderStatus::CONFIRMED->value)
                            ->whereNotNull('f.id');
                    })
                    // Cancelled with fulfillment still present (active or cancelled)
                    ->orWhere(function ($inner) {
                        $inner->where('o.status', OrderStatus::CANCELLED->value)
                            ->whereNotNull('f.id');
                    });
            })
            ->orderBy('o.id')
            ->select([
                'o.id as order_id',
                'o.order_number',
                'o.status as order_status',
                'o.confirmed_at',
                'o.cancelled_at',
                'o.updated_at as order_updated_at',
                'f.id as fulfillment_id',
                'f.status as fulfillment_status',
                'f.invoice_number',
                'f.invoice_generated_at',
                'f.cancelled_at as fulfillment_cancelled_at',
                'f.updated_at as fulfillment_updated_at',
                DB::raw("(SELECT MAX(h.created_at) FROM order_status_histories h WHERE h.order_id = o.id AND h.to_status = 'PRINT') as hist_to_print_at"),
                DB::raw("(SELECT MAX(h.created_at) FROM order_status_histories h WHERE h.order_id = o.id AND h.to_status = 'PRINTED') as hist_to_printed_at"),
                DB::raw("(SELECT h.from_status FROM order_status_histories h WHERE h.order_id = o.id AND h.to_status = 'PRINTED' ORDER BY h.id DESC LIMIT 1) as hist_to_printed_from"),
                DB::raw("(SELECT h.reason FROM order_status_histories h WHERE h.order_id = o.id AND h.to_status = 'PRINTED' ORDER BY h.id DESC LIMIT 1) as hist_to_printed_reason"),
                DB::raw("(SELECT MAX(h.created_at) FROM order_status_histories h WHERE h.order_id = o.id AND h.to_status = 'PACKING') as hist_to_packing_at"),
                DB::raw("(SELECT MAX(h.created_at) FROM order_status_histories h WHERE h.order_id = o.id AND h.to_status = 'DISPATCHED') as hist_to_dispatched_at"),
                DB::raw("(SELECT COUNT(*) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$printedEvent}') as printed_event_count"),
                DB::raw("(SELECT MIN(e.created_at) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$printedEvent}') as first_printed_event_at"),
                DB::raw("(SELECT COUNT(*) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$movedToPrint}') as moved_to_print_event_count"),
                DB::raw("(SELECT MIN(e.created_at) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$movedToPrint}') as first_moved_to_print_at"),
                DB::raw("(SELECT COUNT(*) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$movedToPackaging}') as moved_to_packaging_event_count"),
                DB::raw("(SELECT COUNT(*) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$packageCompleted}') as package_completed_event_count"),
                DB::raw("(SELECT COUNT(*) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$dispatchedEvent}') as dispatched_event_count"),
                DB::raw("(SELECT COUNT(*) FROM order_fulfillment_events e WHERE e.order_id = o.id AND e.event_type = '{$stockConsumed}') as stock_consumed_event_count"),
                DB::raw("(SELECT GROUP_CONCAT(DISTINCT e.event_type ORDER BY e.id SEPARATOR ',') FROM order_fulfillment_events e WHERE e.order_id = o.id) as event_types"),
            ])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function classify(object $row): array
    {
        $orderStatus = (string) $row->order_status;
        $ffStatus = $row->fulfillment_status !== null ? (string) $row->fulfillment_status : null;
        $ffCancelled = $row->fulfillment_cancelled_at !== null;
        $printedEvents = (int) $row->printed_event_count;
        $movedToPrint = (int) $row->moved_to_print_event_count;
        $movedToPackaging = (int) $row->moved_to_packaging_event_count;
        $packageCompleted = (int) $row->package_completed_event_count;
        $dispatchedEvents = (int) $row->dispatched_event_count;
        $stockConsumed = (int) $row->stock_consumed_event_count;
        $histPrintedFrom = $row->hist_to_printed_from !== null ? (string) $row->hist_to_printed_from : null;
        $histPrintedReason = $row->hist_to_printed_reason !== null ? (string) $row->hist_to_printed_reason : null;

        $base = [
            'order_id' => (int) $row->order_id,
            'order_number' => $row->order_number,
            'order_status' => $orderStatus,
            'fulfillment_status' => $ffStatus,
            'fulfillment_cancelled' => $ffCancelled,
            'confirmed_at' => $row->confirmed_at,
            'cancelled_at' => $row->cancelled_at,
            'order_updated_at' => $row->order_updated_at,
            'invoice_generated_at' => $row->invoice_generated_at,
            'fulfillment_updated_at' => $row->fulfillment_updated_at,
            'hist_to_print_at' => $row->hist_to_print_at,
            'hist_to_printed_at' => $row->hist_to_printed_at,
            'hist_to_printed_from' => $histPrintedFrom,
            'hist_to_printed_reason' => $histPrintedReason,
            'hist_to_packing_at' => $row->hist_to_packing_at,
            'hist_to_dispatched_at' => $row->hist_to_dispatched_at,
            'first_moved_to_print_at' => $row->first_moved_to_print_at,
            'first_printed_event_at' => $row->first_printed_event_at,
            'event_types' => $row->event_types,
            'printed_event_count' => $printedEvents,
            'moved_to_print_event_count' => $movedToPrint,
            'moved_to_packaging_event_count' => $movedToPackaging,
            'package_completed_event_count' => $packageCompleted,
            'dispatched_event_count' => $dispatchedEvents,
            'stock_consumed_event_count' => $stockConsumed,
        ];

        // --- Legacy pattern on this DB: fulfillment advanced while orders.status stayed CONFIRMED ---
        // (Neither Case 1 PRINTED-jump nor Case 2 PACKING-left-behind appear; order status was never synced.)
        if ($orderStatus === OrderStatus::CONFIRMED->value && ! $ffCancelled && $ffStatus !== null) {
            if ($ffStatus === SupplierFulfillmentStatus::PRINT->value
                && $movedToPrint > 0
                && $printedEvents === 0
                && $movedToPackaging === 0
                && $packageCompleted === 0
                && $dispatchedEvents === 0
            ) {
                return $base + [
                    'bucket' => 'SAFE_TO_REPAIR',
                    'case' => 'CASE_CONFIRMED_STALE_AFTER_SEND_TO_PRINT',
                    'why' => 'orders.status still CONFIRMED after Send to Print (fulfillment PRINT, MOVED_TO_PRINT event, no PRINTED event). Order status was never advanced by legacy fulfillment code. Proposed: CONFIRMED → PRINT.',
                    'proposed_status' => OrderStatus::PRINT->value,
                ];
            }

            if ($ffStatus === SupplierFulfillmentStatus::PRINT->value
                && $printedEvents > 0
                && $movedToPackaging === 0
                && $packageCompleted === 0
                && $dispatchedEvents === 0
            ) {
                return $base + [
                    'bucket' => 'SAFE_TO_REPAIR',
                    'case' => 'CASE_CONFIRMED_STALE_AFTER_INVOICE_PRINT',
                    'why' => 'orders.status still CONFIRMED but fulfillment PRINT with PRINTED event (invoice printed, not sent to packing). Proposed: CONFIRMED → PRINTED.',
                    'proposed_status' => OrderStatus::PRINTED->value,
                ];
            }

            if ($ffStatus === SupplierFulfillmentStatus::PACKAGING->value
                && $movedToPackaging > 0
                && $packageCompleted === 0
                && $dispatchedEvents === 0
            ) {
                return $base + [
                    'bucket' => 'SAFE_TO_REPAIR',
                    'case' => 'CASE_CONFIRMED_STALE_IN_PACKAGING',
                    'why' => 'orders.status still CONFIRMED while fulfillment is PACKAGING with MOVED_TO_PACKAGING evidence. Proposed: CONFIRMED → PACKING.',
                    'proposed_status' => OrderStatus::PACKING->value,
                ];
            }

            if ($ffStatus === SupplierFulfillmentStatus::DISPATCHED->value
                && ($dispatchedEvents > 0 || $packageCompleted > 0 || $stockConsumed > 0)
                && $row->hist_to_dispatched_at === null
            ) {
                return $base + [
                    'bucket' => 'SAFE_TO_REPAIR',
                    'case' => 'CASE_CONFIRMED_STALE_AFTER_DISPATCH',
                    'why' => 'orders.status still CONFIRMED while fulfillment is DISPATCHED with package/dispatch/consume events and no order-status history to DISPATCHED. Proposed: CONFIRMED → DISPATCHED.',
                    'proposed_status' => OrderStatus::DISPATCHED->value,
                ];
            }
        }

        // --- Case 1: old Send-to-Print wrote PRINTED without invoice print evidence ---
        if ($orderStatus === OrderStatus::PRINTED->value
            && ! $ffCancelled
            && $ffStatus === SupplierFulfillmentStatus::PRINT->value
            && $printedEvents === 0
            && $movedToPackaging === 0
            && $packageCompleted === 0
            && $dispatchedEvents === 0
        ) {
            $oldSendToPrintSignal = $histPrintedFrom === OrderStatus::CONFIRMED->value
                || ($histPrintedReason !== null && str_contains(strtolower($histPrintedReason), 'sent to print'))
                || ($movedToPrint > 0 && $printedEvents === 0);

            return $base + [
                'bucket' => 'SAFE_TO_REPAIR',
                'case' => 'CASE_1_OLD_SEND_TO_PRINT',
                'why' => $oldSendToPrintSignal
                    ? 'orders.status=PRINTED with fulfillment PRINT, no PRINTED event, and history/events match old Send-to-Print (CONFIRMED→PRINTED). Invoice was never actually printed.'
                    : 'orders.status=PRINTED with fulfillment PRINT and no PRINTED event; no packaging/dispatch evidence. Matches the old Send-to-Print status jump.',
                'proposed_status' => OrderStatus::PRINT->value,
            ];
        }

        // --- Case 2: old Complete-Packing set fulfillment DISPATCHED but left orders.status=PACKING ---
        if ($orderStatus === OrderStatus::PACKING->value
            && ! $ffCancelled
            && $ffStatus === SupplierFulfillmentStatus::DISPATCHED->value
        ) {
            $hasDispatchEvidence = $dispatchedEvents > 0 || $packageCompleted > 0 || $stockConsumed > 0;

            if ($hasDispatchEvidence && $row->hist_to_dispatched_at === null) {
                return $base + [
                    'bucket' => 'SAFE_TO_REPAIR',
                    'case' => 'CASE_2_OLD_COMPLETE_PACKING',
                    'why' => 'orders.status=PACKING but fulfillment=DISPATCHED with package/dispatch/consume events and no order-status history to DISPATCHED. Matches old completePackaging which only updated fulfillment.',
                    'proposed_status' => OrderStatus::DISPATCHED->value,
                ];
            }

            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'CASE_2_VARIANT',
                'why' => 'orders.status=PACKING with fulfillment=DISPATCHED, but dispatch-event / status-history evidence is incomplete or mixed. Confirm before repairing.',
                'proposed_status' => OrderStatus::DISPATCHED->value,
            ];
        }

        // --- Expected consistent shapes (NO ACTION) ---
        if ($this->isConsistentExpectedState($orderStatus, $ffStatus, $ffCancelled, $printedEvents)) {
            return $base + [
                'bucket' => 'NO_ACTION',
                'case' => 'CONSISTENT',
                'why' => 'Order status and fulfillment operational state are consistent with the corrected lifecycle (or expected cancelled/fresh patterns).',
                'proposed_status' => null,
            ];
        }

        $why = $this->describeContradiction($orderStatus, $ffStatus, $ffCancelled, $printedEvents, $movedToPackaging, $dispatchedEvents);

        if ($orderStatus === OrderStatus::CANCELLED->value && $ffStatus !== null && ! $ffCancelled) {
            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'CANCELLED_FULFILLMENT_STILL_ACTIVE',
                'why' => $why,
                'proposed_status' => OrderStatus::CANCELLED->value,
            ];
        }

        if ($orderStatus === OrderStatus::CONFIRMED->value
            && in_array($ffStatus, [
                SupplierFulfillmentStatus::PRINT->value,
                SupplierFulfillmentStatus::PACKAGING->value,
                SupplierFulfillmentStatus::DISPATCHED->value,
            ], true)
            && ! $ffCancelled
        ) {
            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'CONFIRMED_WITH_ADVANCED_FULFILLMENT',
                'why' => $why,
                'proposed_status' => $this->proposedFromFulfillment($ffStatus, $printedEvents),
            ];
        }

        if ($orderStatus === OrderStatus::PRINT->value
            && ! $ffCancelled
            && $ffStatus === SupplierFulfillmentStatus::PRINT->value
            && $printedEvents > 0
        ) {
            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'PRINT_BUT_HAS_PRINTED_EVENT',
                'why' => 'orders.status=PRINT but a PRINTED fulfillment event exists. May need PRINT→PRINTED if invoice print completed under mixed code versions.',
                'proposed_status' => OrderStatus::PRINTED->value,
            ];
        }

        if ($orderStatus === OrderStatus::PRINTED->value
            && ! $ffCancelled
            && in_array($ffStatus, [
                SupplierFulfillmentStatus::PACKAGING->value,
                SupplierFulfillmentStatus::DISPATCHED->value,
            ], true)
        ) {
            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'PRINTED_WITH_ADVANCED_FULFILLMENT',
                'why' => $why,
                'proposed_status' => $ffStatus === SupplierFulfillmentStatus::DISPATCHED->value
                    ? OrderStatus::DISPATCHED->value
                    : OrderStatus::PACKING->value,
            ];
        }

        if ($orderStatus === OrderStatus::DISPATCHED->value
            && ! $ffCancelled
            && $ffStatus !== null
            && $ffStatus !== SupplierFulfillmentStatus::DISPATCHED->value
        ) {
            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'DISPATCHED_ORDER_FULFILLMENT_NOT_DISPATCHED',
                'why' => $why,
                'proposed_status' => OrderStatus::DISPATCHED->value,
            ];
        }

        if ($orderStatus === OrderStatus::PACKING->value
            && ! $ffCancelled
            && $ffStatus === SupplierFulfillmentStatus::PRINT->value
        ) {
            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'PACKING_WITH_PRINT_FULFILLMENT',
                'why' => $why,
                'proposed_status' => $printedEvents > 0 ? OrderStatus::PRINTED->value : OrderStatus::PRINT->value,
            ];
        }

        if (in_array($orderStatus, [
            OrderStatus::PRINT->value,
            OrderStatus::PRINTED->value,
            OrderStatus::PACKING->value,
            OrderStatus::DISPATCHED->value,
            OrderStatus::CONFIRMED->value,
            OrderStatus::CANCELLED->value,
        ], true)) {
            return $base + [
                'bucket' => 'NEEDS_MANUAL_REVIEW',
                'case' => 'OTHER_MISMATCH',
                'why' => $why,
                'proposed_status' => null,
            ];
        }

        return $base + [
            'bucket' => 'NO_ACTION',
            'case' => 'OUT_OF_SCOPE',
            'why' => 'Outside supplier lifecycle audit scope.',
            'proposed_status' => null,
        ];
    }

    private function isConsistentExpectedState(
        string $orderStatus,
        ?string $ffStatus,
        bool $ffCancelled,
        int $printedEvents,
    ): bool {
        if ($orderStatus === OrderStatus::CANCELLED->value) {
            return $ffStatus === null || $ffCancelled;
        }

        if ($orderStatus === OrderStatus::CONFIRMED->value) {
            return $ffStatus === null
                || $ffCancelled
                || in_array($ffStatus, [
                    SupplierFulfillmentStatus::FRESH->value,
                    SupplierFulfillmentStatus::OUT_OF_STOCK->value,
                ], true);
        }

        if ($orderStatus === OrderStatus::PRINT->value) {
            return ! $ffCancelled
                && $ffStatus === SupplierFulfillmentStatus::PRINT->value
                && $printedEvents === 0;
        }

        if ($orderStatus === OrderStatus::PRINTED->value) {
            return ! $ffCancelled
                && $ffStatus === SupplierFulfillmentStatus::PRINT->value
                && $printedEvents > 0;
        }

        if ($orderStatus === OrderStatus::PACKING->value) {
            return ! $ffCancelled
                && $ffStatus === SupplierFulfillmentStatus::PACKAGING->value;
        }

        if ($orderStatus === OrderStatus::DISPATCHED->value) {
            return ! $ffCancelled
                && $ffStatus === SupplierFulfillmentStatus::DISPATCHED->value;
        }

        return false;
    }

    private function describeContradiction(
        string $orderStatus,
        ?string $ffStatus,
        bool $ffCancelled,
        int $printedEvents,
        int $movedToPackaging,
        int $dispatchedEvents,
    ): string {
        $ffLabel = $ffStatus === null ? 'null' : $ffStatus.($ffCancelled ? ' (cancelled)' : '');

        return sprintf(
            'Inconsistent pair: orders.status=%s vs fulfillment=%s; printed_events=%d, moved_to_packaging=%d, dispatched_events=%d.',
            $orderStatus,
            $ffLabel,
            $printedEvents,
            $movedToPackaging,
            $dispatchedEvents
        );
    }

    private function proposedFromFulfillment(?string $ffStatus, int $printedEvents): ?string
    {
        return match ($ffStatus) {
            SupplierFulfillmentStatus::PRINT->value => $printedEvents > 0
                ? OrderStatus::PRINTED->value
                : OrderStatus::PRINT->value,
            SupplierFulfillmentStatus::PACKAGING->value => OrderStatus::PACKING->value,
            SupplierFulfillmentStatus::DISPATCHED->value => OrderStatus::DISPATCHED->value,
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $safe
     * @param  list<array<string, mixed>>  $review
     * @param  list<array<string, mixed>>  $noAction
     */
    private function renderSummary(int $scanned, array $safe, array $review, array $noAction): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Candidates scanned', $scanned],
                ['SAFE TO REPAIR', count($safe)],
                ['NEEDS MANUAL REVIEW', count($review)],
                ['NO ACTION', count($noAction)],
                ['Case 1 (old Send-to-Print PRINTED jump)', collect($safe)->where('case', 'CASE_1_OLD_SEND_TO_PRINT')->count()],
                ['Case 2 (old Complete-Packing PACKING left)', collect($safe)->where('case', 'CASE_2_OLD_COMPLETE_PACKING')->count()],
                ['CONFIRMED stale → PRINT', collect($safe)->where('case', 'CASE_CONFIRMED_STALE_AFTER_SEND_TO_PRINT')->count()],
                ['CONFIRMED stale → PRINTED', collect($safe)->where('case', 'CASE_CONFIRMED_STALE_AFTER_INVOICE_PRINT')->count()],
                ['CONFIRMED stale → PACKING', collect($safe)->where('case', 'CASE_CONFIRMED_STALE_IN_PACKAGING')->count()],
                ['CONFIRMED stale → DISPATCHED', collect($safe)->where('case', 'CASE_CONFIRMED_STALE_AFTER_DISPATCH')->count()],
            ]
        );
        $this->newLine();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function renderBucket(string $title, array $items, int $limit): void
    {
        $this->info($title.' ('.count($items).')');

        if ($items === []) {
            $this->line('  (none)');
            $this->newLine();

            return;
        }

        $shown = $this->limitList($items, $limit);
        $rows = [];
        foreach ($shown as $item) {
            $rows[] = [
                $item['order_id'],
                $item['order_number'] ?? '',
                $item['order_status'],
                $item['fulfillment_status'] ?? '—',
                $item['proposed_status'] ?? '—',
                $item['case'],
                $this->truncate((string) $item['why'], 90),
            ];
        }

        $this->table(
            ['Order ID', 'Order #', 'orders.status', 'Fulfillment', 'Proposed', 'Case', 'Why'],
            $rows
        );

        if ($limit > 0 && count($items) > $limit) {
            $this->warn('  … '.(count($items) - $limit).' more not shown (raise --limit or use --json).');
        }

        $this->newLine();
        $this->line('Detail (timestamps / events):');
        foreach ($shown as $item) {
            $this->line(sprintf(
                '  #%d | confirmed=%s | inv_gen=%s | hist_printed=%s (from=%s) | printed_evt=%s | moved_print=%s | pkg_done=%s | disp_evt=%s | events=[%s]',
                $item['order_id'],
                $item['confirmed_at'] ?? '—',
                $item['invoice_generated_at'] ?? '—',
                $item['hist_to_printed_at'] ?? '—',
                $item['hist_to_printed_from'] ?? '—',
                $item['first_printed_event_at'] ?? '—',
                $item['first_moved_to_print_at'] ?? '—',
                $item['package_completed_event_count'],
                $item['dispatched_event_count'],
                $item['event_types'] ?? ''
            ));
            if (! empty($item['hist_to_printed_reason'])) {
                $this->line('         hist_printed_reason: '.$item['hist_to_printed_reason']);
            }
        }
        $this->newLine();
    }

    /**
     * @param  list<array<string, mixed>>  $safe
     */
    private function renderRepairGuidance(array $safe): void
    {
        $this->info('PROPOSED ONE-TIME REPAIR (NOT EXECUTED)');
        $this->line('Review SAFE TO REPAIR rows, then run the SQL below in a transaction after backup.');
        $this->newLine();

        $sql = $this->proposedRepairSql($safe);
        foreach ($sql as $block) {
            $this->line('-- '.$block['label']);
            $this->line($block['sql']);
            $this->newLine();
        }

        $this->line('Re-run audit after repair:');
        $this->line('  php artisan orders:audit-legacy-lifecycle');
        $this->newLine();
        $this->warn('This audit command does not modify data.');
    }

    /**
     * @param  list<array<string, mixed>>  $safe
     * @return list<array{label: string, sql: string, order_ids: list<int>}>
     */
    private function proposedRepairSql(array $safe): array
    {
        $groups = [
            'CASE_1_OLD_SEND_TO_PRINT' => [
                'label' => 'Case 1: PRINTED → PRINT (old Send-to-Print jump)',
                'from' => 'PRINTED',
                'to' => 'PRINT',
                'reason' => 'Data repair: correct old Send-to-Print CONFIRMED→PRINTED jump to PRINT',
            ],
            'CASE_2_OLD_COMPLETE_PACKING' => [
                'label' => 'Case 2: PACKING → DISPATCHED (old Complete-Packing)',
                'from' => 'PACKING',
                'to' => 'DISPATCHED',
                'reason' => 'Data repair: sync orders.status after old completePackaging left PACKING',
            ],
            'CASE_CONFIRMED_STALE_AFTER_SEND_TO_PRINT' => [
                'label' => 'Legacy: CONFIRMED → PRINT (Send to Print never synced order status)',
                'from' => 'CONFIRMED',
                'to' => 'PRINT',
                'reason' => 'Data repair: sync orders.status after legacy Send-to-Print left CONFIRMED',
            ],
            'CASE_CONFIRMED_STALE_AFTER_INVOICE_PRINT' => [
                'label' => 'Legacy: CONFIRMED → PRINTED (invoice print never synced order status)',
                'from' => 'CONFIRMED',
                'to' => 'PRINTED',
                'reason' => 'Data repair: sync orders.status after legacy invoice print left CONFIRMED',
            ],
            'CASE_CONFIRMED_STALE_IN_PACKAGING' => [
                'label' => 'Legacy: CONFIRMED → PACKING (packaging never synced order status)',
                'from' => 'CONFIRMED',
                'to' => 'PACKING',
                'reason' => 'Data repair: sync orders.status after legacy Send-to-Packing left CONFIRMED',
            ],
            'CASE_CONFIRMED_STALE_AFTER_DISPATCH' => [
                'label' => 'Legacy: CONFIRMED → DISPATCHED (complete packing never synced order status)',
                'from' => 'CONFIRMED',
                'to' => 'DISPATCHED',
                'reason' => 'Data repair: sync orders.status after legacy completePackaging left CONFIRMED',
            ],
        ];

        $blocks = [];

        foreach ($groups as $case => $meta) {
            $ids = collect($safe)->where('case', $case)->pluck('order_id')->map(fn ($id) => (int) $id)->values()->all();

            if ($ids === []) {
                $blocks[] = [
                    'label' => $meta['label'],
                    'order_ids' => [],
                    'sql' => '-- No SAFE TO REPAIR rows for this case.',
                ];

                continue;
            }

            $idList = implode(', ', $ids);
            $from = $meta['from'];
            $to = $meta['to'];
            $reason = addslashes($meta['reason']);

            $blocks[] = [
                'label' => $meta['label'].' [ids: '.$idList.']',
                'order_ids' => $ids,
                'sql' => <<<SQL
START TRANSACTION;

-- Preview
SELECT id, order_number, status FROM orders WHERE id IN ({$idList}) AND status = '{$from}';

UPDATE orders
SET status = '{$to}', updated_at = NOW()
WHERE id IN ({$idList})
  AND status = '{$from}';

INSERT INTO order_status_histories
  (uuid, order_id, from_status, to_status, changed_by, changed_by_company_id, reason, created_at)
SELECT UUID(), o.id, '{$from}', '{$to}', NULL, NULL, '{$reason}', NOW()
FROM orders o
WHERE o.id IN ({$idList})
  AND o.status = '{$to}';

-- Verify
SELECT id, order_number, status FROM orders WHERE id IN ({$idList});

COMMIT;
-- ROLLBACK;  -- use instead of COMMIT if verification fails
SQL,
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function limitList(array $items, int $limit): array
    {
        if ($limit <= 0) {
            return $items;
        }

        return array_slice($items, 0, $limit);
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 1).'…';
    }
}
