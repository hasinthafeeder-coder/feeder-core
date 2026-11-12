<?php

namespace Feeder\Core\Console\Commands;

use Carbon\CarbonImmutable;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Meeting\MeetingReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Publishes 3-day and 1-day reseller meeting reminders (in-app only).
 *
 * Idempotent via notifications.source_event_key. Does not mutate attendance.
 */
class ProcessMeetingRemindersCommand extends Command
{
    protected $signature = 'meetings:process-reminders
                            {--actor= : User id recorded as created_by (defaults to feeder.settlement.scheduled_actor_user_id)}
                            {--date= : As-of date Y-m-d (defaults to today in feeder.settlement.schedule_timezone)}';

    protected $description = 'Publish 3-day and 1-day in-app reminders for scheduled reseller meetings';

    public function handle(MeetingReminderService $reminders): int
    {
        try {
            $actor = $this->resolveActor();
            $asOf = $this->resolveAsOfDate();

            $this->info('Processing reseller meeting reminders.');
            $this->line('Actor user id: '.$actor->id);
            $this->line('As of: '.$asOf->toDateString());

            $summary = $reminders->processDueReminders($actor, $asOf);

            $this->info(sprintf(
                'Published three-day=%d one-day=%d',
                $summary['three_day'],
                $summary['one_day'],
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
                'A meeting reminder actor user id is required via --actor or FEEDER_SETTLEMENT_SCHEDULED_ACTOR_USER_ID.'
            );
        }

        $actor = User::query()->find($actorId);

        if ($actor === null) {
            throw new RuntimeException('Scheduled actor user '.$actorId.' was not found.');
        }

        return $actor;
    }

    private function resolveAsOfDate(): Carbon
    {
        $raw = $this->option('date');

        if (is_string($raw) && trim($raw) !== '') {
            return Carbon::parse(trim($raw))->startOfDay();
        }

        $timezone = (string) config('feeder.settlement.schedule_timezone', 'Asia/Colombo');

        return CarbonImmutable::now($timezone)->startOfDay()->toMutable();
    }
}
