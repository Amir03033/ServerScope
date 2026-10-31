<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Analytics\MetricRollups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Aggregates the closed hours of one server (see MetricRollups). Unique
 * per server so two runs never overlap; safe to retry because every
 * write is an idempotent upsert and the watermark only advances after a
 * chunk is stored.
 */
class RollupServerMetrics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(MetricRollups $rollups): void
    {
        // Soft-deleted servers keep their history (and its rollups).
        $server = Server::query()->withoutGlobalScopes()->withTrashed()->find($this->serverId);

        if ($server !== null) {
            $rollups->rollup($server);
        }
    }
}
