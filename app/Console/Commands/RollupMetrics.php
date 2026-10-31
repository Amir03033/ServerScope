<?php

namespace App\Console\Commands;

use App\Jobs\RollupServerMetrics;
use App\Models\Server;
use App\Services\Analytics\MetricRollups;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Hourly: aggregates closed hours of every server into metric_rollups.
 * Runs inline by default (one short query per server and hour); with
 * --queue (or SERVERSCOPE_ROLLUPS_QUEUE=true) it dispatches one unique
 * job per server, for installations with supervised workers.
 */
class RollupMetrics extends Command
{
    protected $signature = 'serverscope:rollup
        {--server=* : Only these server ids}
        {--from= : Recompute from this time (UTC) instead of each server\'s watermark (backfill)}
        {--queue : Dispatch one job per server instead of running inline}';

    protected $description = 'Aggregate raw metric samples into hourly and daily rollups';

    public function handle(MetricRollups $rollups): int
    {
        $from = $this->option('from') !== null ? Carbon::parse($this->option('from'), 'UTC') : null;
        $queue = $this->option('queue') || (bool) config('serverscope.rollups.queue', false);

        if ($queue && $from !== null) {
            $this->error('--from en --queue gaan niet samen: herberekenen gebeurt direct.');

            return self::INVALID;
        }

        $query = Server::query()->withoutGlobalScopes()->withTrashed()->whereNotNull('last_seen_at')
            ->when($this->option('server'), fn ($q, $ids) => $q->whereIn('id', array_map('intval', $ids)));

        $totals = ['servers' => 0, 'rows' => 0, 'errors' => 0];

        foreach ($query->lazyById(100) as $server) {
            $totals['servers']++;

            if ($queue) {
                RollupServerMetrics::dispatch($server->id);

                continue;
            }

            try {
                $totals['rows'] += $rollups->rollup($server, $from)['rows'];
            } catch (Throwable $exception) {
                // One server's failure does not stop the others; its
                // watermark stays put, so the next run retries it.
                report($exception);
                $totals['errors']++;
                $this->warn("Rollup mislukt voor server {$server->id}.");
            }
        }

        $this->info($queue
            ? "Rollup-taken ingepland: {$totals['servers']}"
            : "Servers: {$totals['servers']}, rijen geschreven: {$totals['rows']}, fouten: {$totals['errors']}");

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
