<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Support\PlanLimits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes monitoring history older than the configured retention.
 * Every category is disabled (kept forever) unless a number of days is
 * configured. Deletes in batches to keep locks short.
 */
class PruneMonitoringData extends Command
{
    protected $signature = 'serverscope:prune {--dry-run : Only report what would be deleted}';

    protected $description = 'Delete monitoring history older than the configured retention';

    /** table => [config key, timestamp column, extra constraint] */
    private const TARGETS = [
        'metric_samples' => ['metric_samples_days', 'collected_at', null],
        'domain_checks' => ['domain_checks_days', 'checked_at', null],
        'notification_logs' => ['notification_logs_days', 'created_at', null],
        'notifications' => ['notifications_days', 'created_at', 'read'],
        'audit_logs' => ['audit_logs_days', 'created_at', null],
        'page_checks' => ['page_checks_days', 'checked_at', null],
        'page_changes' => ['page_changes_days', 'detected_at', null],
        'page_discovery_runs' => ['discovery_runs_days', 'created_at', null],
        'service_check_events' => ['service_events_days', 'recorded_at', null],
        'predictions' => ['predictions_days', 'resolved_at', 'resolved'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach (self::TARGETS as $table => [$key, $column, $constraint]) {
            $days = config("serverscope.retention.{$key}");

            if ($days === null || (int) $days <= 0) {
                $this->line("{$table}: bewaard (geen retentie ingesteld)");

                continue;
            }

            $cutoff = now()->subDays((int) $days);
            $query = fn () => DB::table($table)->where($column, '<', $cutoff)
                ->when($constraint === 'read', fn ($q) => $q->whereNotNull('read_at'))
                // Only resolved findings; active ones are current state.
                ->when($constraint === 'resolved', fn ($q) => $q->where('status', 'resolved'));

            if ($dryRun) {
                $count = $query()->count();
                $this->line("{$table}: {$count} rijen ouder dan {$days} dagen zouden worden verwijderd");
                $total += $count;

                continue;
            }

            $deleted = 0;

            do {
                $ids = $query()->orderBy('id')->limit(5000)->pluck('id');
                $batch = $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
                $deleted += $batch;
            } while ($batch > 0);

            $this->line("{$table}: {$deleted} rijen verwijderd (ouder dan {$days} dagen)");
            $total += $deleted;
        }

        $total += $this->prunePlanRetention($dryRun);

        $this->info(($dryRun ? 'Totaal te verwijderen: ' : 'Totaal verwijderd: ').$total);

        return self::SUCCESS;
    }

    /**
     * Plan retention (config serverscope.plans): the history of a team
     * is kept no longer than its effective plan allows, on top of any
     * global retention above. Only the largest tables are covered:
     * metric samples and domain checks.
     */
    private function prunePlanRetention(bool $dryRun): int
    {
        $total = 0;
        $targets = [
            'metric_samples' => ['servers', 'server_id', 'collected_at'],
            'domain_checks' => ['domains', 'domain_id', 'checked_at'],
        ];

        foreach (Team::query()->orderBy('id')->get() as $team) {
            $days = PlanLimits::limit('retention_days', $team);

            if ($days === null || $days <= 0) {
                continue;
            }

            $cutoff = now()->subDays((int) $days);

            foreach ($targets as $table => [$parent, $fk, $column]) {
                $query = fn () => DB::table($table)
                    ->whereIn($fk, fn ($q) => $q->select('id')->from($parent)->where('team_id', $team->id))
                    ->where($column, '<', $cutoff);

                if ($dryRun) {
                    $count = $query()->count();
                    $total += $count;
                    $this->line("{$table} (team {$team->id}, plan {$team->effectivePlan()}): {$count} rijen ouder dan {$days} dagen zouden worden verwijderd");

                    continue;
                }

                $deleted = 0;

                do {
                    $ids = $query()->orderBy('id')->limit(5000)->pluck('id');
                    $batch = $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
                    $deleted += $batch;
                } while ($batch > 0);

                $total += $deleted;
                $this->line("{$table} (team {$team->id}, plan {$team->effectivePlan()}): {$deleted} rijen verwijderd (ouder dan {$days} dagen)");
            }
        }

        return $total;
    }
}
