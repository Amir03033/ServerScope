<?php

namespace App\Services\Websites;

use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Models\PageDiscoveryRun;
use App\Monitoring\Net\HttpResult;
use App\Monitoring\Net\SafeHttpClient;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds the pages of a website.
 *
 * Sources, in order: robots.txt (Sitemap lines), manually configured
 * sitemaps, /sitemap.xml when nothing is declared, sitemap indexes
 * (nested at most three levels) and internal links of the website's own
 * pages up to max_depth. Safety:
 * - every request goes through SafeHttpClient (SSRF checks for each URL
 *   and each redirect, IP pinning, size cap, redirect-loop detection);
 * - only URLs on the website's host (subdomains only when enabled) that
 *   pass the allowed/excluded patterns and robots.txt are kept;
 * - hard limits on pages, requests, sitemaps, depth and duration, plus a
 *   delay between requests;
 * - failures are recorded on the run and never change existing pages.
 * Discovered pages wait for approval unless auto-monitoring is enabled.
 */
class PageDiscovery
{
    private const MAX_SITEMAP_NESTING = 3;

    private int $requests = 0;

    private float $startedAt = 0.0;

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<string, array{source: string, depth: int}> */
    private array $found = [];

    private int $skipped = 0;

    /** @var array<string, int> */
    private array $sources = [];

    public function __construct(
        private SafeHttpClient $client,
    ) {}

    public function run(Domain $domain, PageDiscoveryRun $run): PageDiscoveryRun
    {
        $this->reset();
        $settings = $domain->discoverySettings();
        $scope = new DiscoveryScope($domain, $settings);
        $base = $domain->scheme.'://'.$domain->name.'/';

        $run->forceFill(['status' => 'running', 'started_at' => now(), 'settings' => $settings])->save();

        try {
            $robots = $this->robots($domain, $scope);
            $this->sitemaps($domain, $scope, $robots, $settings);

            if ($settings['max_depth'] > 0) {
                $this->crawl($scope, $robots, $base, $settings);
            }

            [$added, $seen] = $this->persist($domain, $settings);
            $limited = $this->budgetExhausted($settings);

            $status = match (true) {
                $this->found === [] && $this->errors !== [] => 'failed',
                $this->errors !== [] || $limited => 'partial',
                default => 'completed',
            };

            if ($limited) {
                $this->errors[] = 'Limiet bereikt (pagina\'s, verzoeken of tijd); niet alle pagina\'s zijn onderzocht.';
            }
        } catch (Throwable $exception) {
            report($exception);
            $status = 'failed';
            $added = $seen = 0;
            $this->errors[] = 'Onverwachte fout tijdens ontdekken; bestaande monitoring is niet gewijzigd.';
        }

        $run->forceFill([
            'status' => $status,
            'requests_made' => $this->requests,
            'urls_found' => count($this->found),
            'pages_added' => $added,
            'pages_seen' => $seen,
            'urls_skipped' => $this->skipped,
            'sources' => $this->sources ?: null,
            'errors' => array_slice(array_values(array_unique($this->errors)), 0, 50) ?: null,
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    private function reset(): void
    {
        $this->requests = 0;
        $this->startedAt = microtime(true);
        $this->errors = [];
        $this->found = [];
        $this->skipped = 0;
        $this->sources = [];
    }

    private function robots(Domain $domain, DiscoveryScope $scope): ?RobotsTxt
    {
        $url = $domain->scheme.'://'.$domain->name.'/robots.txt';
        $result = $this->fetch($url);

        if ($result === null) {
            return null;
        }

        if ($result->failed()) {
            $this->errors[] = "robots.txt: {$result->errorMessage}";

            return null;
        }

        if ($result->status !== 200 || ! $this->finalUrlInScope($scope, $result)) {
            return null;
        }

        return RobotsTxt::parse($result->body);
    }

    private function sitemaps(Domain $domain, DiscoveryScope $scope, ?RobotsTxt $robots, array $settings): void
    {
        $queue = [];

        foreach ($settings['sitemap_urls'] as $url) {
            $queue[] = [$url, 'sitemap', 0, true];
        }

        foreach ($robots?->sitemaps ?? [] as $url) {
            $queue[] = [$url, 'robots', 0, true];
        }

        if ($queue === []) {
            $queue[] = [$domain->scheme.'://'.$domain->name.'/sitemap.xml', 'sitemap', 0, false];
        }

        $seen = [];
        $max = max(1, (int) config('serverscope.websites.discovery_max_sitemaps', 25));

        while ($queue !== [] && count($seen) < $max && ! $this->budgetExhausted($settings)) {
            [$url, $source, $nesting, $explicit] = array_shift($queue);
            $normalized = UrlNormalizer::normalize($url, keepQuery: true);

            if ($normalized === null || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;

            if (! $scope->hostAllowed(UrlNormalizer::host($normalized))) {
                $this->errors[] = "Sitemap {$normalized} genegeerd: buiten het domein.";

                continue;
            }

            $result = $this->fetch($normalized);

            if ($result === null) {
                break;
            }

            if ($result->failed()) {
                $this->errors[] = "Sitemap {$normalized}: {$result->errorMessage}";

                continue;
            }

            if ($result->status !== 200) {
                // A missing default sitemap is normal, not an error.
                if ($explicit || $result->status !== 404) {
                    $this->errors[] = "Sitemap {$normalized}: HTTP {$result->status}.";
                }

                continue;
            }

            if (! $this->finalUrlInScope($scope, $result)) {
                $this->errors[] = "Sitemap {$normalized} verwijst door naar een ander domein.";

                continue;
            }

            $parsed = SitemapParser::parse($result->body, $settings['max_pages'] * 5);

            if ($parsed['error'] !== null) {
                $this->errors[] = "Sitemap {$normalized}: {$parsed['error']}".($result->truncated ? ' (ingekort tot de maximale grootte)' : '');

                continue;
            }

            if ($parsed['type'] === 'sitemapindex') {
                if ($nesting >= self::MAX_SITEMAP_NESTING) {
                    $this->errors[] = "Sitemapindex {$normalized} te diep genest; overgeslagen.";

                    continue;
                }

                foreach ($parsed['locations'] as $child) {
                    $queue[] = [$child, $source, $nesting + 1, true];
                }

                continue;
            }

            foreach ($parsed['locations'] as $location) {
                if (count($this->found) >= $settings['max_pages']) {
                    break;
                }

                $this->consider($scope, $robots, $location, null, $source, 0);
            }
        }

        if ($queue !== [] && count($seen) >= $max) {
            $this->errors[] = "Maximaal {$max} sitemaps per ontdekking; overige sitemaps overgeslagen.";
        }
    }

    private function crawl(DiscoveryScope $scope, ?RobotsTxt $robots, string $base, array $settings): void
    {
        $start = $scope->normalize($base);

        if ($start === null) {
            return;
        }

        $queue = [[$start, 0]];
        $visited = [];

        while ($queue !== [] && ! $this->budgetExhausted($settings)) {
            [$url, $depth] = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            if ($robots !== null && ! $robots->isAllowed($this->pathAndQuery($url))) {
                $this->skipped++;

                continue;
            }

            $result = $this->fetch($url);

            if ($result === null) {
                break;
            }

            if ($result->failed()) {
                $this->errors[] = "{$url}: {$result->errorMessage}";

                continue;
            }

            if ($result->status !== 200 || ! $this->finalUrlInScope($scope, $result)) {
                continue;
            }

            $final = $scope->normalize((string) $result->finalUrl) ?? $url;
            $this->add($final, 'link', $depth, $settings);

            if ($depth >= $settings['max_depth'] || ! str_contains(strtolower($result->headers['content-type'] ?? 'text/html'), 'html')) {
                continue;
            }

            foreach ($this->links($result->body) as $href) {
                if (count($this->found) >= $settings['max_pages']) {
                    return;
                }

                $link = $this->consider($scope, $robots, $href, $final, 'link', $depth + 1);

                // Only pages above the maximum depth are fetched for links;
                // links at the maximum depth are recorded, not crawled.
                if ($link !== null && $depth + 1 < $settings['max_depth'] && ! isset($visited[$link])) {
                    $queue[] = [$link, $depth + 1];
                }
            }
        }
    }

    /** Validate and record a candidate URL; returns its normalised form when kept. */
    private function consider(DiscoveryScope $scope, ?RobotsTxt $robots, string $url, ?string $base, string $source, int $depth): ?string
    {
        $normalized = $scope->normalize($url, $base);

        if ($normalized === null || $scope->rejectionReason($normalized) !== null) {
            $this->skipped++;

            return null;
        }

        if ($robots !== null && ! $robots->isAllowed($this->pathAndQuery($normalized))) {
            $this->skipped++;

            return null;
        }

        $this->add($normalized, $source, $depth, null);

        return $normalized;
    }

    private function add(string $url, string $source, int $depth, ?array $settings): void
    {
        if (isset($this->found[$url])) {
            $this->found[$url]['depth'] = min($this->found[$url]['depth'], $depth);

            return;
        }

        if ($settings !== null && count($this->found) >= $settings['max_pages']) {
            return;
        }

        $this->found[$url] = ['source' => $source, 'depth' => $depth];
        $this->sources[$source] = ($this->sources[$source] ?? 0) + 1;
    }

    /** @return array<int, string> */
    private function links(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = [];

        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $rel = strtolower($anchor->getAttribute('rel'));

            if ($href === '' || str_contains($rel, 'nofollow') || preg_match('/^(mailto|tel|javascript|data|ftp|file):/i', $href)) {
                continue;
            }

            $links[] = $href;

            if (count($links) >= 500) {
                break;
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * Store the results: new URLs become pending (or monitored) pages,
     * known URLs only get a "last seen" timestamp. Nothing is deleted.
     *
     * @return array{0: int, 1: int} added, already known
     */
    private function persist(Domain $domain, array $settings): array
    {
        $added = 0;
        $seen = 0;
        $now = now();
        $capacity = max(0, \App\Support\PlanLimits::pagesPerWebsite(\App\Models\Team::query()->find($domain->team_id)) - $domain->pages()->count());

        foreach ($this->found as $url => $info) {
            $hash = MonitoredPage::hashUrl($url);

            $updated = MonitoredPage::query()->where('domain_id', $domain->id)->where('url_hash', $hash)
                ->update(['last_seen_in_discovery_at' => $now, 'updated_at' => $now]);

            if ($updated > 0) {
                $seen++;

                continue;
            }

            if ($added >= $capacity) {
                $this->errors[] = 'Maximum aantal pagina\'s per website bereikt; niet alle gevonden pagina\'s zijn toegevoegd.';
                break;
            }

            DB::transaction(function () use ($domain, $url, $hash, $info, $settings, $now, &$added): void {
                $page = MonitoredPage::query()->firstOrCreate(
                    ['domain_id' => $domain->id, 'url_hash' => $hash],
                    [
                        'url' => $url,
                        'source' => $info['source'],
                        'depth' => $info['depth'],
                        'approval_status' => $settings['auto_monitor'] ? 'approved' : 'pending',
                        'is_active' => true,
                        'check_interval_minutes' => $settings['default_interval'],
                        'discovered_at' => $now,
                        'last_seen_in_discovery_at' => $now,
                    ],
                );

                if ($page->wasRecentlyCreated) {
                    $added++;
                }
            });
        }

        return [$added, $seen];
    }

    private function fetch(string $url): ?HttpResult
    {
        if ($this->requests > 0) {
            usleep(max(0, (int) config('serverscope.websites.discovery_request_delay_ms', 250)) * 1000);
        }

        $this->requests++;

        return $this->client->get($url, (float) config('serverscope.websites.discovery_timeout_seconds', 10));
    }

    private function finalUrlInScope(DiscoveryScope $scope, HttpResult $result): bool
    {
        return $result->finalUrl === null || $scope->hostAllowed(UrlNormalizer::host($result->finalUrl));
    }

    private function budgetExhausted(array $settings): bool
    {
        return count($this->found) >= $settings['max_pages']
            || $this->requests >= max(1, (int) config('serverscope.websites.discovery_max_requests', 300))
            || (microtime(true) - $this->startedAt) >= max(10, (int) config('serverscope.websites.discovery_max_duration_seconds', 240));
    }

    private function pathAndQuery(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return ((string) parse_url($url, PHP_URL_PATH) ?: '/').($query ? '?'.$query : '');
    }
}
