<?php

namespace Feeder\Core\Console\Commands;

use Feeder\Core\Services\Courier\Fardar\FardarLocationCleanupService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Test-environment cleanup of Fardar Express locations and disposable Fardar-linked
 * orders/shipments. Default mode is dry-run. --execute requires interactive confirmation.
 */
class CleanupFardarLocationsCommand extends Command
{
    protected $signature = 'courier:fardar-cleanup
                            {--execute : Permanently delete Fardar test data + locations (requires confirmation)}';

    protected $description = 'Audit/delete Fardar Express locations and disposable Fardar test orders/shipments (dry-run by default)';

    public function handle(FardarLocationCleanupService $cleanup): int
    {
        $execute = (bool) $this->option('execute');

        try {
            if (! $execute) {
                return $this->runDryRun($cleanup);
            }

            return $this->runExecute($cleanup);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage().' | exception='.$e::class);

            return self::FAILURE;
        }
    }

    private function runDryRun(FardarLocationCleanupService $cleanup): int
    {
        $audit = $cleanup->audit();
        $courier = $audit['courier'];
        $deps = $audit['dependencies'];

        $this->info('FARDAR TEST DATA CLEANUP — DRY RUN');
        $this->newLine();
        $this->line(sprintf(
            'Courier: %s (ID: %d)',
            $courier->code,
            $courier->id
        ));
        $this->newLine();

        $this->line('Locations:');
        $this->line('  * States/Districts: '.$audit['states']);
        $this->line('  * Cities: '.$audit['cities']);
        $this->newLine();

        $this->line('Dependent test data:');
        $this->line('  * Orders: '.$deps['orders']);
        $this->line('  * Shipments: '.$deps['shipments']);
        $this->line('  * Other dependent records: '.$deps['dependent_records']);
        if ($deps['dependents_by_table'] !== []) {
            foreach ($deps['dependents_by_table'] as $table => $count) {
                $this->line("      - {$table}: {$count}");
            }
        }
        $this->newLine();

        $this->line('Records to be deleted:');
        $this->line('  * Orders: '.$deps['orders']);
        $this->line('  * Shipments: '.$deps['shipments']);
        $this->line('  * Dependent records: '.$deps['dependent_records']);
        $this->line('  * Fardar cities: '.$audit['cities']);
        $this->line('  * Fardar states: '.$audit['states']);
        $this->newLine();

        $this->line('Baseline (other couriers — must remain unchanged):');
        foreach ($audit['baselines'] as $code => $snapshot) {
            $this->line(sprintf(
                '  %s: states=%d cities=%d',
                $code,
                $snapshot['states'],
                $snapshot['cities']
            ));
        }
        $this->newLine();

        if ($audit['shared_violations'] !== []) {
            $this->warn('Shared-record violations (execution will be refused):');
            foreach ($audit['shared_violations'] as $violation) {
                $this->line('  - '.$violation);
            }
            $this->newLine();
        }

        if ($audit['unexpected_blockers'] !== []) {
            $this->warn('Unexpected dependencies (execution will be refused):');
            foreach ($audit['unexpected_blockers'] as $blocker) {
                $this->line(sprintf(
                    '  - %s.%s: %d row(s); %s; sample ids: %s',
                    $blocker['table'],
                    $blocker['column'],
                    $blocker['count'],
                    $blocker['reason'],
                    $blocker['sample_ids'] === [] ? 'n/a' : implode(', ', $blocker['sample_ids'])
                ));
            }
            $this->newLine();
        }

        if ($audit['safe_to_execute']) {
            $this->comment('Safety checks passed. Re-run with --execute to delete after confirmation.');
        } else {
            $this->warn('Safety checks FAILED. --execute will be refused until blockers are resolved.');
        }

        $this->newLine();
        $this->info('No records have been deleted.');

        return self::SUCCESS;
    }

    private function runExecute(FardarLocationCleanupService $cleanup): int
    {
        $audit = $cleanup->audit();

        if (! $audit['safe_to_execute']) {
            // Surface blockers via execute() exception path for consistent messaging.
            $cleanup->execute();
        }

        $this->warn('WARNING:');
        $this->warn('This will permanently delete FARDAR test orders,');
        $this->warn('shipments, dependent test records, and all FARDAR');
        $this->warn('courier cities/states.');
        $this->newLine();
        $this->warn('This operation cannot be undone.');
        $this->newLine();

        if (! $this->confirm('Continue?', false)) {
            $this->info('Aborted. No records have been deleted.');

            return self::FAILURE;
        }

        $result = $cleanup->execute();

        $this->info('FARDAR TEST DATA CLEANUP — COMPLETED');
        $this->newLine();
        $this->line('Deleted:');
        $this->line('  * Orders: '.$result['deleted']['orders']);
        $this->line('  * Shipments: '.$result['deleted']['shipments']);
        $this->line('  * Dependent records: '.$result['deleted']['dependent_records']);
        $this->line('  * Fardar cities: '.$result['deleted']['cities']);
        $this->line('  * Fardar states: '.$result['deleted']['states']);
        $this->newLine();

        $this->line('Verification:');
        $this->line('  * Fardar states: '.$result['after']['states']);
        $this->line('  * Fardar cities: '.$result['after']['cities']);
        $this->line('  * Fardar courier: '.($result['fardar_courier_exists'] ? 'EXISTS' : 'MISSING'));
        $this->line('  * Remaining Fardar-linked orders: '.$result['after']['orders']);
        $this->line('  * Remaining Fardar-linked shipments: '.$result['after']['shipments']);
        $this->newLine();

        $this->line('Other courier verification:');
        $this->line('  * Royal/Curfox: '.$this->baselineLabel($result, 'ROYAL'));
        $this->line('  * TransExpress: '.$this->baselineLabel($result, 'TRANSEXPRESS'));
        $this->line('  * Other couriers: '.$this->baselineLabel($result, 'OTHER'));

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     baselines_before: array<string, array{states: int, cities: int}>,
     *     baselines_after: array<string, array{states: int, cities: int}>,
     *     other_couriers_unchanged: bool
     * }  $result
     */
    private function baselineLabel(array $result, string $key): string
    {
        $before = $result['baselines_before'][$key] ?? null;
        $after = $result['baselines_after'][$key] ?? null;

        if ($before === null || $after === null) {
            return 'n/a';
        }

        $unchanged = (int) $before['states'] === (int) $after['states']
            && (int) $before['cities'] === (int) $after['cities'];

        return $unchanged
            ? sprintf('unchanged (states=%d cities=%d)', $after['states'], $after['cities'])
            : sprintf(
                'CHANGED (before states=%d cities=%d → after states=%d cities=%d)',
                $before['states'],
                $before['cities'],
                $after['states'],
                $after['cities']
            );
    }
}
