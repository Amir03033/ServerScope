<?php

namespace App\Jobs;

use App\Models\MonitoredPage;
use App\Services\Websites\PageMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks one monitored page. Unique per page so checks never overlap and
 * not retried: the next scheduled run is the retry, so a failing website
 * cannot cause a storm of retried jobs.
 */
class RunPageCheck implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public function __construct(
        public int $pageId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->pageId;
    }

    public function handle(PageMonitor $monitor): void
    {
        $page = MonitoredPage::query()->with('domain')->find($this->pageId);

        if ($page === null || ! $page->isMonitored() || $page->domain === null || $page->domain->trashed() || ! $page->domain->is_active) {
            return;
        }

        $monitor->check($page);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Page check failed', ['page_id' => $this->pageId, 'error' => $exception->getMessage()]);
    }
}
