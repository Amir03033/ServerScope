<?php

namespace App\Console\Commands;

use App\Jobs\RunPageCheck;
use App\Models\MonitoredPage;
use Illuminate\Console\Command;

/**
 * Queues page checks that are due. Each check is claimed by atomically
 * moving next_check_at forward before dispatch (no duplicates when runs
 * overlap). At most `checks_per_website_per_run` pages per website and
 * `max_checks_per_run` in total are queued per run; the rest stays due
 * and is picked up a minute later, which spreads load on one website.
 */
class DispatchPageChecks extends Command
{
    protected $signature = 'serverscope:dispatch-page-checks';

    protected $description = 'Queue website page checks that are due';

    public function handle(): int
    {
        $now = now();
        $perWebsite = max(1, (int) config('serverscope.websites.checks_per_website_per_run', 20));
        $limit = max(1, (int) config('serverscope.websites.max_checks_per_run', 500));
        $perDomain = [];
        $dispatched = 0;

        $pages = MonitoredPage::query()
            ->monitored()
            ->whereHas('domain', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->orderByRaw('next_check_at IS NOT NULL')
            ->orderBy('next_check_at')
            ->limit($limit * 4)
            ->get(['id', 'domain_id', 'check_interval_minutes', 'next_check_at']);

        foreach ($pages as $page) {
            if ($dispatched >= $limit) {
                break;
            }

            if (($perDomain[$page->domain_id] ?? 0) >= $perWebsite) {
                continue;
            }

            $claimed = MonitoredPage::query()
                ->whereKey($page->id)
                ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
                ->update(['next_check_at' => $now->copy()->addMinutes(max(1, $page->check_interval_minutes))]);

            if ($claimed === 1) {
                RunPageCheck::dispatch($page->id);
                $perDomain[$page->domain_id] = ($perDomain[$page->domain_id] ?? 0) + 1;
                $dispatched++;
            }
        }

        $this->info("Paginacontroles ingepland: {$dispatched}");

        return self::SUCCESS;
    }
}
