<?php

namespace Feeder\Core\Console\Commands;

use Carbon\CarbonImmutable;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Notification\FinancialNotificationService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Publishes daily financial summary notifications from persisted earnings.
 *
 * Does not recalculate settlement eligibility or mutate accounting tables.
 * Requires FEEDER_SETTLEMENT_SCHEDULED_ACTOR_USER_ID (or --actor) like other
 * settlement schedulers. In-app only; MAIL_MAILER=log is not used here.
 */
class ProcessDailyFinancialNotificationSummariesCommand extends Command
{
    protected $signature = 'notifications:process-daily-financial-summaries
                            {--actor= : User id recorded as created_by (defaults to feeder.settlement.scheduled_actor_user_id)}
                            {--date= : Business date Y-m-d (defaults to yesterday in feeder.settlement.schedule_timezone)}';

    protected $description = 'Publish daily reseller/supplier financial summary notifications from persisted earnings';

    public function handle(FinancialNotificationService $notifications): int
    {
        try {
            $actor = $this->resolveActor();
            $date = $this->resolveBusinessDate();

            $this->info('Publishing daily financial summary notifications.');
            $this->line('Actor user id: '.$actor->id);
            $this->line('Business date: '.$date);

            $summary = $notifications->publishDailySummaries($actor, $date);

            $this->info(sprintf(
                'Published reseller summaries=%d supplier summaries=%d',
                $summary['reseller_count'],
                $summary['supplier_count'],
            ));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveActor(): User
    {
        $actorId = $this->option('actor') !== null && $this->option('actor') !== ''
            ? (int) $this->option('actor')
            : (int) config('feeder.settlement.scheduled_actor_user_id');

        if ($actorId <= 0) {
            throw new RuntimeException(
                'A notification actor user id is required via --actor or FEEDER_SETTLEMENT_SCHEDULED_ACTOR_USER_ID.'
            );
        }

        $actor = User::query()->find($actorId);

        if ($actor === null) {
            throw new RuntimeException('Scheduled actor user '.$actorId.' was not found.');
        }

        return $actor;
    }

    private function resolveBusinessDate(): string
    {
        $raw = $this->option('date');

        if (is_string($raw) && trim($raw) !== '') {
            return CarbonImmutable::parse(trim($raw))->toDateString();
        }

        $timezone = (string) config('feeder.settlement.schedule_timezone', 'Asia/Colombo');

        return CarbonImmutable::now($timezone)->subDay()->toDateString();
    }
}
