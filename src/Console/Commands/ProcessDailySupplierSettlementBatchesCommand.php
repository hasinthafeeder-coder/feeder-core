<?php

namespace Feeder\Core\Console\Commands;

use Carbon\CarbonImmutable;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Settlement\SupplierSettlementDailyBatchProcessor;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Daily supplier settlement batch create + generate for each eligible market.
 *
 * Does not lock batches, prepare bank exports, record payments, or close batches.
 */
class ProcessDailySupplierSettlementBatchesCommand extends Command
{
    protected $signature = 'settlement:process-daily-supplier-batches
                            {--actor= : User id recorded as generated_by (defaults to feeder.settlement.scheduled_actor_user_id)}
                            {--at= : Optional UTC/ISO timestamp used to resolve each market business date}
                            {--market=* : Optional market id filter (repeatable)}';

    protected $description = 'Create and generate supplier settlement batches for each eligible market, including outstanding prior business dates';

    public function handle(SupplierSettlementDailyBatchProcessor $processor): int
    {
        try {
            $actor = $this->resolveActor();
            $at = $this->resolveAt();
            $marketIds = $this->resolveMarketIds();

            $this->info('Starting daily supplier settlement processing.');
            $this->line('Actor user id: '.$actor->id);
            if ($at !== null) {
                $this->line('Reference time: '.$at->toIso8601String());
            }
            if ($marketIds !== null) {
                $this->line('Market filter: '.implode(', ', $marketIds));
            }

            $summary = $processor->process($actor, $at, $marketIds);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($summary['markets'] as $marketResult) {
            $status = (string) ($marketResult['status'] ?? 'unknown');
            $label = strtoupper((string) ($marketResult['market_code'] ?? $marketResult['market_id'] ?? '?'));

            if ($status === 'success') {
                $totals = $marketResult['payable_totals_by_currency'] ?? [];
                $totalsText = $totals === []
                    ? 'none'
                    : collect($totals)->map(static fn ($amount, $code) => $code.'='.$amount)->implode(', ');

                $this->info(sprintf(
                    '[%s] batch_id=%s date=%s invoices=%d payables=%s skipped=%d',
                    $label,
                    $marketResult['batch_id'] ?? 'n/a',
                    $marketResult['settlement_date'] ?? 'n/a',
                    (int) ($marketResult['invoice_count'] ?? 0),
                    $totalsText,
                    (int) ($marketResult['skipped_candidate_count'] ?? 0),
                ));

                continue;
            }

            if ($status === 'skipped') {
                $this->warn(sprintf(
                    '[%s] skipped batch_id=%s status=%s — %s',
                    $label,
                    $marketResult['batch_id'] ?? 'n/a',
                    $marketResult['batch_status'] ?? 'n/a',
                    $marketResult['reason'] ?? 'skipped',
                ));

                continue;
            }

            $this->error(sprintf(
                '[%s] FAILED date=%s code=%s — %s',
                $label,
                $marketResult['settlement_date'] ?? 'n/a',
                $marketResult['error_code'] ?? 'UNKNOWN',
                $marketResult['error_message'] ?? 'unknown failure',
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            'Markets: success=%d failed=%d skipped=%d',
            (int) $summary['success_market_count'],
            (int) $summary['failed_market_count'],
            (int) $summary['skipped_market_count'],
        ));

        return ((int) $summary['failed_market_count']) > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function resolveActor(): User
    {
        $actorOption = $this->option('actor');
        $actorId = $actorOption !== null && $actorOption !== ''
            ? (int) $actorOption
            : (int) config('feeder.settlement.scheduled_actor_user_id');

        if ($actorId < 1) {
            throw new RuntimeException(
                'A settlement actor user id is required via --actor or FEEDER_SETTLEMENT_SCHEDULED_ACTOR_USER_ID.',
            );
        }

        $actor = User::query()->find($actorId);
        if ($actor === null) {
            throw new RuntimeException('Settlement actor user ['.$actorId.'] was not found.');
        }

        return $actor;
    }

    private function resolveAt(): ?CarbonImmutable
    {
        $at = $this->option('at');
        if ($at === null || trim((string) $at) === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $at)->utc();
    }

    /**
     * @return list<int>|null
     */
    private function resolveMarketIds(): ?array
    {
        $markets = $this->option('market');
        if (! is_array($markets) || $markets === []) {
            return null;
        }

        $ids = [];
        foreach ($markets as $market) {
            $id = (int) $market;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids === [] ? null : array_values(array_unique($ids));
    }
}
