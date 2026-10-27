<?php

namespace App\Services\Websites;

use App\Jobs\RunPageCheck;
use App\Models\Domain;
use App\Models\MonitoredPage;

/**
 * Adds validated, normalised URLs as monitored pages (web and API).
 * A discovered page that is added by hand is approved instead of
 * duplicated; the first check is queued immediately.
 */
class PageRegistrar
{
    /**
     * @param  array<int, string>  $urls  normalised URLs
     * @return array{added: array<int, MonitoredPage>, duplicates: int, limit_reached: bool}
     */
    public function add(Domain $domain, array $urls, array $settings): array
    {
        $limit = (int) config('serverscope.websites.max_pages_per_website', 1000);
        $existing = $domain->pages()->count();
        $added = [];
        $duplicates = 0;

        foreach ($urls as $url) {
            $page = MonitoredPage::query()->firstOrCreate(
                ['domain_id' => $domain->id, 'url_hash' => MonitoredPage::hashUrl($url)],
                ['url' => $url, 'source' => 'manual', 'approval_status' => 'approved', ...$settings],
            );

            if ($page->wasRecentlyCreated) {
                if ($existing + count($added) >= $limit) {
                    $page->delete();

                    return ['added' => $added, 'duplicates' => $duplicates, 'limit_reached' => true];
                }
            } elseif ($page->approval_status !== 'approved') {
                $page->forceFill(['approval_status' => 'approved', ...$settings])->save();
            } else {
                $duplicates++;

                continue;
            }

            self::queueCheck($page);
            $added[] = $page;
        }

        return ['added' => $added, 'duplicates' => $duplicates, 'limit_reached' => false];
    }

    public static function queueCheck(MonitoredPage $page): void
    {
        if (! $page->isMonitored()) {
            return;
        }

        $page->forceFill(['next_check_at' => now()->addMinutes(max(1, $page->check_interval_minutes))])->saveQuietly();
        RunPageCheck::dispatch($page->id);
    }

    /** Delete a page and its history; incidents stay without subject. */
    public static function delete(MonitoredPage $page): void
    {
        $page->incidents()->update(['subject_id' => null, 'subject_type' => null]);
        $page->delete();
    }
}
