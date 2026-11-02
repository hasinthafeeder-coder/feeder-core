<?php

namespace Feeder\Core\Services\IntroducerBonus;

use Carbon\CarbonImmutable;
use Feeder\Core\Models\IntroducerBonusEvent;
use Feeder\Core\Models\IntroducerBonusLine;
use Feeder\Core\Models\IntroducerBonusMonthlyBatch;
use Feeder\Core\Models\IntroducerBonusPayment;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\ReferralRelationship;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Finance\FinancialDailySummaryUpdater;
use Feeder\Core\Services\IntroducerBonusService;
use Feeder\Core\Services\UuidService;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Monthly introducer bonus settlement.
 *
 * Bonus = market default_introducer_bonus × distinct qualifying orders of the
 * introducer's direct referrals. Own orders decide the monthly target only.
 * The production target is TARGET_OWN_ORDERS (1,000). A temporary testing
 * override is feeder.introducer_bonus.monthly_own_order_target.
 * This service does not call supplier or reseller daily settlement processors
 * and does not write their tables.
 *
 * Writer lock order, when both rows are needed: market, then monthly batch.
 */
class IntroducerBonusMonthlySettlementService
{
    public const TARGET_OWN_ORDERS = 1000;

    /**
     * Active own-order target. Production stays at TARGET_OWN_ORDERS unless
     * FEEDER_INTRODUCER_BONUS_OWN_ORDER_TARGET overrides it for testing.
     */
    public function ownOrderTarget(): int
    {
        $configured = config('feeder.introducer_bonus.monthly_own_order_target');
        $target = is_numeric($configured) ? (int) $configured : 0;

        return $target > 0 ? $target : self::TARGET_OWN_ORDERS;
    }

    public function __construct(
        private readonly IntroducerBonusService $bonusRates,
        private readonly IntroducerBonusQualifyingOrderCollector $orders,
        private readonly MarketBusinessDateResolver $businessDates,
        private readonly FinancialDailySummaryUpdater $financialDailySummaries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Market|int|string $market, int $year, int $month): array
    {
        return $this->hideIntroducersAlreadyGenerated(
            $this->buildStatement($this->resolveMarket($market), $year, $month)
        );
    }

    public function generate(
        Market|int|string $market,
        int $year,
        int $month,
        User $actor,
        string $generationSource = IntroducerBonusMonthlyBatch::GENERATION_SOURCE_MANUAL,
    ): IntroducerBonusMonthlyBatch {
        $this->assertActor($actor);
        $this->assertPeriod($year, $month);
        $this->assertGenerationSource($generationSource);
        $resolved = $this->resolveMarket($market);

        try {
            return DB::transaction(function () use ($resolved, $year, $month, $actor, $generationSource): IntroducerBonusMonthlyBatch {
                $lockedMarket = Market::query()->whereKey($resolved->id)->lockForUpdate()->first();

                if ($lockedMarket === null) {
                    throw ValidationException::withMessages([
                        'market' => 'The selected market could not be found.',
                    ]);
                }

                $lockedMarket->setRelation('currency', $resolved->currency);

                $existing = IntroducerBonusMonthlyBatch::query()
                    ->where('market_id', $lockedMarket->id)
                    ->where('period_year', $year)
                    ->where('period_month', $month)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'batch' => 'An introducer bonus batch already exists for this market and month. Rebuild the unpaid batch instead of generating another one.',
                    ]);
                }

                $statement = $this->buildStatement($lockedMarket, $year, $month);
                $this->assertHasPayableLines($statement);

                try {
                    $batch = IntroducerBonusMonthlyBatch::query()->create([
                        'uuid' => UuidService::generate(),
                        'batch_number' => $this->batchNumber($lockedMarket, $year, $month),
                        'market_id' => $lockedMarket->id,
                        'market_code_snapshot' => strtoupper((string) $lockedMarket->code),
                        'timezone_snapshot' => $statement['timezone'],
                        'period_year' => $year,
                        'period_month' => $month,
                        'currency_id' => (int) $lockedMarket->currency_id,
                        'currency_code_snapshot' => $statement['currency_code'],
                        'bonus_rate_snapshot' => $statement['bonus_rate'],
                        'payable_total' => $statement['payable_total'],
                        'line_count' => $statement['line_count'],
                        'status' => IntroducerBonusMonthlyBatch::STATUS_GENERATED,
                        'generation_source' => $generationSource,
                        'generated_by' => $actor->id,
                        'generated_at' => now(),
                        'rebuild_count' => 0,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages([
                        'batch' => 'An introducer bonus batch already exists for this market and month. Rebuild the unpaid batch instead of generating another one.',
                    ]);
                }

                $this->insertLines($batch, $statement['lines']);
                $payload = $this->eventPayload($statement);
                $payload['generation_source'] = $generationSource;

                $this->writeEvent(
                    IntroducerBonusEvent::TYPE_GENERATED,
                    $actor,
                    $payload,
                    (int) $batch->id,
                );

                $fresh = $batch->fresh(['payment']);
                if ($fresh !== null) {
                    $this->financialDailySummaries->recordIntroducerBonusBatchGenerated($fresh);
                }

                return $fresh ?? $batch;
            });
        } catch (QueryException $exception) {
            $this->throwIfConcurrencyConflict($exception);
        }
    }

    public function rebuild(IntroducerBonusMonthlyBatch $batch, User $actor): IntroducerBonusMonthlyBatch
    {
        $this->assertActor($actor);

        try {
            return DB::transaction(function () use ($batch, $actor): IntroducerBonusMonthlyBatch {
                $this->lockMarketBeforeBatch($batch);

                $locked = IntroducerBonusMonthlyBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

                if ($locked === null) {
                    throw ValidationException::withMessages([
                        'batch' => 'The introducer bonus batch could not be found.',
                    ]);
                }

                $this->assertMutable($locked);

                $market = $this->resolveMarket((int) $locked->market_id);
                $statement = $this->buildStatement($market, (int) $locked->period_year, (int) $locked->period_month);
                $this->assertHasPayableLines($statement);

                if ($statement['currency_code'] !== strtoupper((string) $locked->currency_code_snapshot)) {
                    throw ValidationException::withMessages([
                        'currency' => 'The market currency no longer matches the currency snapshotted on this batch.',
                    ]);
                }

                IntroducerBonusLine::query()->where('batch_id', $locked->id)->delete();
                $this->insertLines($locked, $statement['lines']);

                $locked->forceFill([
                    'timezone_snapshot' => $statement['timezone'],
                    'bonus_rate_snapshot' => $statement['bonus_rate'],
                    'payable_total' => $statement['payable_total'],
                    'line_count' => $statement['line_count'],
                    'rebuilt_by' => $actor->id,
                    'rebuilt_at' => now(),
                    'rebuild_count' => (int) $locked->rebuild_count + 1,
                ])->save();

                $this->writeEvent(
                    IntroducerBonusEvent::TYPE_REBUILT,
                    $actor,
                    $this->eventPayload($statement),
                    (int) $locked->id,
                );

                $fresh = $locked->fresh(['payment']);
                if ($fresh !== null) {
                    $this->financialDailySummaries->recordIntroducerBonusBatchGenerated($fresh);
                }

                return $fresh ?? $locked;
            });
        } catch (QueryException $exception) {
            $this->throwIfConcurrencyConflict($exception);
        }
    }

    private function lockMarketBeforeBatch(IntroducerBonusMonthlyBatch $batch): void
    {
        $marketId = (int) $batch->market_id;

        if ($marketId < 1) {
            $marketId = (int) IntroducerBonusMonthlyBatch::query()->whereKey($batch->id)->value('market_id');
        }

        if ($marketId > 0) {
            Market::query()->whereKey($marketId)->lockForUpdate()->first();
        }
    }

    private function throwIfConcurrencyConflict(QueryException $exception): never
    {
        if (! $this->isExpectedConcurrencyConflict($exception)) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'batch' => 'Another introducer bonus update is in progress for this market and month. Try again.',
        ]);
    }

    private function isExpectedConcurrencyConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '40001'
            || $driverCode === 1213
            || $driverCode === 1205
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout');
    }

    public function recordFullPayment(
        IntroducerBonusMonthlyBatch $batch,
        User $actor,
        mixed $amount,
        string $currencyCode,
        string $paidOn,
        string $reference,
    ): IntroducerBonusPayment {
        $this->assertActor($actor);

        return DB::transaction(function () use ($batch, $actor, $amount, $currencyCode, $paidOn, $reference): IntroducerBonusPayment {
            $locked = IntroducerBonusMonthlyBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'batch' => 'The introducer bonus batch could not be found.',
                ]);
            }

            if ($locked->isPaid() || IntroducerBonusPayment::query()->where('batch_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'payment' => 'This introducer bonus batch is already paid. A second payment cannot be recorded.',
                ]);
            }

            $normalizedAmount = $this->normalizeMoney($amount);
            $payable = $this->normalizeMoney($locked->payable_total);
            $batchCurrency = strtoupper(trim((string) $locked->currency_code_snapshot));
            $submittedCurrency = strtoupper(trim($currencyCode));

            if ($submittedCurrency === '' || $submittedCurrency !== $batchCurrency) {
                throw ValidationException::withMessages([
                    'currency' => "The payment currency must match the batch currency {$batchCurrency}.",
                ]);
            }

            if ($normalizedAmount !== $payable) {
                throw ValidationException::withMessages([
                    'amount' => "The payment amount must exactly match the batch payable total of {$payable} {$batchCurrency}. Partial payment is not allowed.",
                ]);
            }

            $paidDate = $this->assertPaidOn($paidOn);
            $cleanReference = trim($reference);

            if ($cleanReference === '' || strlen($cleanReference) > 100) {
                throw ValidationException::withMessages([
                    'reference' => 'Enter a payment reference of up to 100 characters.',
                ]);
            }

            try {
                $payment = IntroducerBonusPayment::query()->create([
                    'uuid' => UuidService::generate(),
                    'batch_id' => $locked->id,
                    'amount' => $normalizedAmount,
                    'currency_id' => $locked->currency_id,
                    'currency_code_snapshot' => $batchCurrency,
                    'paid_on' => $paidDate,
                    'reference' => $cleanReference,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'payment' => 'This introducer bonus batch is already paid. A second payment cannot be recorded.',
                ]);
            }

            $locked->forceFill([
                'status' => IntroducerBonusMonthlyBatch::STATUS_PAID,
            ])->save();

            $this->writeEvent(
                IntroducerBonusEvent::TYPE_PAYMENT_RECORDED,
                $actor,
                [
                    'amount' => $normalizedAmount,
                    'currency_code' => $batchCurrency,
                    'paid_on' => $paidDate,
                    'reference' => $cleanReference,
                    'payment_id' => (int) $payment->id,
                ],
                (int) $locked->id,
            );

            $freshPayment = $payment->fresh() ?? $payment;
            $this->financialDailySummaries->recordIntroducerBonusPayment($freshPayment);

            return $freshPayment;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatement(Market $market, int $year, int $month): array
    {
        $this->assertPeriod($year, $month);
        $market->loadMissing('currency');

        $rate = $this->bonusRates->getIntroducerBonus($market);
        $currency = $market->currency;
        $currencyCode = strtoupper(trim((string) ($currency->iso_code ?? '')));

        if ($currency === null || $currencyCode === '') {
            throw ValidationException::withMessages([
                'market' => 'The selected market does not have a valid active currency.',
            ]);
        }

        try {
            $timezone = $this->businessDates->timezoneFor($market);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'market' => $exception->getMessage(),
            ]);
        }

        $qualifying = $this->orders->collect($market, $year, $month, $currencyCode);

        $ownCounts = [];
        foreach ($qualifying as $row) {
            $resellerId = (int) $row['reseller_id'];
            $ownCounts[$resellerId] = ($ownCounts[$resellerId] ?? 0) + 1;
        }

        $resellerIds = array_keys($ownCounts);
        $relationships = $resellerIds === []
            ? collect()
            : ReferralRelationship::query()
                ->whereIn('child_user_id', $resellerIds)
                ->get()
                ->keyBy(fn (ReferralRelationship $relationship): int => (int) $relationship->child_user_id);

        $teamOrdersByParent = [];

        foreach ($qualifying as $row) {
            $relationship = $relationships->get((int) $row['reseller_id']);

            if (! $relationship instanceof ReferralRelationship) {
                continue;
            }

            if ((int) $relationship->child_user_id !== (int) $row['reseller_id']
                || (int) $relationship->parent_user_id < 1
                || (int) $relationship->parent_user_id === (int) $relationship->child_user_id) {
                throw ValidationException::withMessages([
                    'referral' => 'Order '.($row['order_number'] ?: '#'.$row['order_id']).' has an inconsistent direct referral relationship. The introducer bonus batch was not created.',
                ]);
            }

            $teamOrdersByParent[(int) $relationship->parent_user_id][] = [
                'row' => $row,
                'relationship_id' => (int) $relationship->id,
            ];
        }

        $parentIds = array_keys($teamOrdersByParent);
        $parents = $parentIds === []
            ? collect()
            : User::withTrashed()
                ->with('profile')
                ->whereIn('id', $parentIds)
                ->get()
                ->keyBy('id');

        $eligible = [];
        $ineligible = [];
        $lines = [];

        foreach ($teamOrdersByParent as $parentId => $entries) {
            $parent = $parents->get($parentId);

            if (! $parent instanceof User) {
                throw ValidationException::withMessages([
                    'referral' => "Direct introducer user {$parentId} could not be found. The introducer bonus batch was not created.",
                ]);
            }

            $ownCount = (int) ($ownCounts[$parentId] ?? 0);
            $exception = (bool) $parent->introducer_bonus_target_exception;
            $target = $this->ownOrderTarget();
            $meetsTarget = $ownCount >= $target;
            $teamCount = count($entries);
            $amount = $this->multiplyMoney($rate, $teamCount);
            $summary = [
                'introducer_user_id' => (int) $parent->id,
                'introducer_name' => $this->displayName($parent),
                'own_qualifying_order_count' => $ownCount,
                'team_order_count' => $teamCount,
                'monthly_own_order_target' => $target,
                'target_exception_applied' => ! $meetsTarget && $exception,
                'amount' => $amount,
            ];

            if (! $meetsTarget && ! $exception) {
                $summary['reason'] = "Own qualifying delivered orders are {$ownCount}. The monthly target is "
                    .$target
                    .' and this reseller has no target exception.';
                $ineligible[] = $summary;

                continue;
            }

            $introducerLines = [];

            foreach ($entries as $entry) {
                $row = $entry['row'];
                $line = [
                    'introducer_user_id' => (int) $parent->id,
                    'order_id' => (int) $row['order_id'],
                    'order_number' => $row['order_number'],
                    'referral_relationship_id' => (int) $entry['relationship_id'],
                    'business_date' => $row['business_date'],
                    'bonus_rate' => $rate,
                    'amount' => $rate,
                    'currency_code' => $currencyCode,
                    'courier_receipt_import_id' => (int) $row['import_id'],
                    'courier_receipt_import_row_id' => (int) $row['import_row_id'],
                ];
                $introducerLines[] = $line;
                $lines[] = $line;
            }

            $summary['lines'] = $introducerLines;
            $eligible[] = $summary;
        }

        usort($eligible, fn (array $left, array $right): int => $left['introducer_user_id'] <=> $right['introducer_user_id']);
        usort($ineligible, fn (array $left, array $right): int => $left['introducer_user_id'] <=> $right['introducer_user_id']);
        usort($lines, fn (array $left, array $right): int => [$left['introducer_user_id'], $left['order_id']] <=> [$right['introducer_user_id'], $right['order_id']]);

        return [
            'market_id' => (int) $market->id,
            'market_code' => strtoupper((string) $market->code),
            'market_name' => (string) ($market->name ?: $market->code),
            'period_year' => $year,
            'period_month' => $month,
            'timezone' => $timezone,
            'currency_code' => $currencyCode,
            'bonus_rate' => $rate,
            'eligible_introducers' => $eligible,
            'ineligible_introducers' => $ineligible,
            'lines' => $lines,
            'line_count' => count($lines),
            'payable_total' => $this->multiplyMoney($rate, count($lines)),
        ];
    }

    /**
     * Preview display only. Generation and rebuild keep the full statement.
     *
     * An introducer is already generated when a committed batch for this market
     * and calendar month has a bonus line for them. GENERATED and PAID are the
     * only stored statuses. A rolled-back attempt leaves no line, so it does not
     * hide the introducer. Another month or market is a different batch.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function hideIntroducersAlreadyGenerated(array $statement): array
    {
        $eligible = $statement['eligible_introducers'];
        $statement['already_generated_introducer_ids'] = [];

        if ($eligible === []) {
            return $statement;
        }

        $ids = array_map(
            fn (array $row): int => (int) $row['introducer_user_id'],
            $eligible
        );

        $generatedIds = IntroducerBonusLine::query()
            ->whereIn('introducer_user_id', $ids)
            ->whereHas('batch', function ($query) use ($statement): void {
                $query->where('market_id', $statement['market_id'])
                    ->where('period_year', $statement['period_year'])
                    ->where('period_month', $statement['period_month'])
                    ->whereIn('status', [
                        IntroducerBonusMonthlyBatch::STATUS_GENERATED,
                        IntroducerBonusMonthlyBatch::STATUS_PAID,
                    ]);
            })
            ->distinct()
            ->pluck('introducer_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $generatedLookup = array_fill_keys($generatedIds, true);
        $statement['already_generated_introducer_ids'] = array_keys($generatedLookup);
        $statement['eligible_introducers'] = array_values(array_filter(
            $eligible,
            fn (array $row): bool => ! isset($generatedLookup[(int) $row['introducer_user_id']])
        ));

        return $statement;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function insertLines(IntroducerBonusMonthlyBatch $batch, array $lines): void
    {
        $now = now();
        $rows = [];
        $seenOrders = [];

        foreach ($lines as $line) {
            $orderId = (int) $line['order_id'];

            if (isset($seenOrders[$orderId])) {
                throw ValidationException::withMessages([
                    'order' => "Order {$orderId} was included more than once. The introducer bonus batch was not created.",
                ]);
            }

            $seenOrders[$orderId] = true;
            $rows[] = [
                'uuid' => UuidService::generate(),
                'batch_id' => $batch->id,
                'introducer_user_id' => (int) $line['introducer_user_id'],
                'order_id' => $orderId,
                'referral_relationship_id' => (int) $line['referral_relationship_id'],
                'business_date' => $line['business_date'],
                'courier_receipt_import_id' => (int) $line['courier_receipt_import_id'],
                'courier_receipt_import_row_id' => (int) $line['courier_receipt_import_row_id'],
                'bonus_rate_snapshot' => $line['bonus_rate'],
                'amount' => $line['amount'],
                'currency_code_snapshot' => $line['currency_code'],
                'order_number_snapshot' => $line['order_number'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            try {
                IntroducerBonusLine::query()->insert($chunk);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'order' => 'One of these orders is already included in an introducer bonus batch. The batch was not changed.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function assertHasPayableLines(array $statement): void
    {
        if (($statement['lines'] ?? []) === []) {
            throw ValidationException::withMessages([
                'period' => 'No payable introducer bonus lines were found for this market and month.',
            ]);
        }
    }

    private function assertMutable(IntroducerBonusMonthlyBatch $batch): void
    {
        if ($batch->isPaid() || IntroducerBonusPayment::query()->where('batch_id', $batch->id)->exists()) {
            throw ValidationException::withMessages([
                'batch' => 'This introducer bonus batch has a recorded payment and can no longer be rebuilt or edited.',
            ]);
        }

        if ($batch->status !== IntroducerBonusMonthlyBatch::STATUS_GENERATED) {
            throw ValidationException::withMessages([
                'batch' => 'Only an unpaid generated introducer bonus batch can be rebuilt.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function eventPayload(array $statement): array
    {
        $introducers = [];

        foreach ($statement['eligible_introducers'] as $introducer) {
            $introducers[] = [
                'introducer_user_id' => $introducer['introducer_user_id'],
                'own_qualifying_order_count' => $introducer['own_qualifying_order_count'],
                'team_order_count' => $introducer['team_order_count'],
                'monthly_own_order_target' => $introducer['monthly_own_order_target'],
                'target_exception_applied' => $introducer['target_exception_applied'],
                'amount' => $introducer['amount'],
            ];
        }

        return [
            'bonus_rate' => $statement['bonus_rate'],
            'currency_code' => $statement['currency_code'],
            'line_count' => $statement['line_count'],
            'payable_total' => $statement['payable_total'],
            'introducers' => $introducers,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeEvent(string $type, User $actor, array $payload, ?int $batchId = null, ?int $subjectUserId = null): void
    {
        IntroducerBonusEvent::query()->create([
            'uuid' => UuidService::generate(),
            'event_type' => $type,
            'batch_id' => $batchId,
            'subject_user_id' => $subjectUserId,
            'actor_user_id' => $actor->id,
            'payload' => $payload,
        ]);
    }

    private function resolveMarket(Market|int|string $market): Market
    {
        if ($market instanceof Market) {
            $resolved = Market::query()->with('currency')->whereKey($market->getKey())->first();
        } elseif (is_int($market)) {
            $resolved = Market::query()->with('currency')->whereKey($market)->first();
        } else {
            $resolved = Market::query()->with('currency')->where('uuid', $market)->first();

            if ($resolved === null && ctype_digit($market)) {
                $resolved = Market::query()->with('currency')->whereKey((int) $market)->first();
            }

            if ($resolved === null) {
                $resolved = Market::query()->with('currency')->where('code', $market)->first();
            }
        }

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'market' => 'The selected market could not be found.',
            ]);
        }

        return $resolved;
    }

    private function assertPeriod(int $year, int $month): void
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages([
                'period' => 'Select a valid calendar year and month.',
            ]);
        }
    }

    private function assertGenerationSource(string $generationSource): void
    {
        if (! in_array($generationSource, [
            IntroducerBonusMonthlyBatch::GENERATION_SOURCE_MANUAL,
            IntroducerBonusMonthlyBatch::GENERATION_SOURCE_SCHEDULED,
        ], true)) {
            throw ValidationException::withMessages([
                'generation_source' => 'Unsupported introducer bonus generation source.',
            ]);
        }
    }

    private function assertActor(User $actor): void
    {
        if ($actor->id === null) {
            throw ValidationException::withMessages([
                'actor' => 'An administrator is required to record this introducer bonus action.',
            ]);
        }
    }

    private function assertPaidOn(string $paidOn): string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $paidOn);

        if ($parsed === false || $parsed->format('Y-m-d') !== $paidOn) {
            throw ValidationException::withMessages([
                'paid_on' => 'Enter the paid date as YYYY-MM-DD.',
            ]);
        }

        return $paidOn;
    }

    private function batchNumber(Market $market, int $year, int $month): string
    {
        return sprintf('IBM-%s-%04d%02d', strtoupper((string) $market->code), $year, $month);
    }

    private function displayName(User $user): string
    {
        $profile = $user->profile;
        $name = trim(((string) ($profile->first_name ?? '')).' '.((string) ($profile->last_name ?? '')));

        return $name !== '' ? $name : 'Reseller '.$user->id;
    }

    private function multiplyMoney(string $rate, int $count): string
    {
        if ($count < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The introducer bonus order count cannot be negative.',
            ]);
        }

        return $this->centsToMoney($this->toCents($rate) * $count);
    }

    private function normalizeMoney(mixed $amount): string
    {
        $string = trim((string) $amount);

        if ($string === '' || ! preg_match('/^\d+(\.\d+)?$/', $string)) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must be a non-negative number with up to 2 decimal places.',
            ]);
        }

        if (! str_contains($string, '.')) {
            return $string.'.00';
        }

        [$whole, $fraction] = explode('.', $string, 2);

        if (strlen($fraction) > 2 && (int) substr($fraction, 2) !== 0) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must use no more than 2 decimal places.',
            ]);
        }

        return $whole.'.'.substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    private function toCents(string $money): int
    {
        $normalized = $this->normalizeMoney($money);
        [$whole, $fraction] = explode('.', $normalized);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
