<?php

namespace App\Jobs;

use App\Models\PageDiscoveryRun;
use App\Services\Websites\PageDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs page discovery for one website in the background. One discovery
 * per website at a time; not retried automatically (a user starts a new
 * run), so a broken website cannot trigger repeated crawls.
 */
class RunPageDiscovery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        public int $runId,
        public int $domainId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(PageDiscovery $discovery): void
    {
        $run = PageDiscoveryRun::query()->with('domain')->find($this->runId);

        if ($run === null || $run->status !== 'queued' || $run->domain === null || $run->domain->trashed()) {
            return;
        }

        $discovery->run($run->domain, $run);
    }

    public function failed(Throwable $exception): void
    {
        PageDiscoveryRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed',
            'errors' => json_encode(['De ontdekking is afgebroken (time-out of fout in de worker).']),
            'finished_at' => now(),
        ]);
    }
}
