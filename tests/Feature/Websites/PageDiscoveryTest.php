<?php

namespace Tests\Feature\Websites;

use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Models\PageDiscoveryRun;
use App\Monitoring\Dns\Resolver;
use App\Services\Websites\PageDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class PageDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeResolver $dns;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeResolver;
        $this->dns->host('example.com', '93.184.215.14');
        $this->dns->host('docs.example.com', '93.184.215.16');
        $this->dns->host('evil.test', '93.184.215.99');
        $this->app->instance(Resolver::class, $this->dns);
        config([
            'serverscope.monitoring.requests_per_ip_per_minute' => 10000,
            'serverscope.websites.discovery_request_delay_ms' => 0,
        ]);

        $this->domain = Domain::query()->create(['name' => 'example.com']);
    }

    private function settings(array $settings): void
    {
        $this->domain->forceFill(['discovery_settings' => [...$this->domain->discoverySettings(), ...$settings]])->save();
    }

    private function discover(array $routes): PageDiscoveryRun
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([...$routes, '*' => Http::response('Not found', 404)]);

        $run = PageDiscoveryRun::query()->create(['domain_id' => $this->domain->id, 'status' => 'queued']);

        return app(PageDiscovery::class)->run($this->domain->fresh(), $run)->fresh();
    }

    private function urlset(array $urls): string
    {
        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', array_map(fn ($u) => "<url><loc>{$u}</loc></url>", $urls)).'</urlset>';
    }

    private function urls(): array
    {
        return MonitoredPage::query()->orderBy('url')->pluck('url')->all();
    }

    public function test_robots_sitemap_index_and_nested_sitemaps(): void
    {
        $this->settings(['max_depth' => 0]);

        $run = $this->discover([
            'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /private/\nSitemap: https://example.com/sitemap_index.xml\n"),
            'https://example.com/sitemap_index.xml' => Http::response('<sitemapindex><sitemap><loc>https://example.com/pages.xml</loc></sitemap><sitemap><loc>https://example.com/blog.xml.gz</loc></sitemap></sitemapindex>'),
            'https://example.com/pages.xml' => Http::response($this->urlset([
                'https://example.com/', 'https://EXAMPLE.com/pricing#plans', 'https://example.com/pricing?utm_source=x',
                'https://example.com/private/admin', 'https://evil.test/phish', 'https://docs.example.com/start',
            ])),
            'https://example.com/blog.xml.gz' => Http::response(gzencode($this->urlset(['https://example.com/blog/hello']))),
        ]);

        $this->assertSame('completed', $run->status, json_encode($run->errors));
        $this->assertSame(['https://example.com/', 'https://example.com/blog/hello', 'https://example.com/pricing'], $this->urls());
        $this->assertSame(3, $run->pages_added);
        $this->assertSame(3, $run->urls_skipped, 'robots disallow, other domain and subdomain without opt-in');
        $this->assertSame(['robots' => 3], $run->sources);

        // Discovered pages wait for approval and are not monitored yet.
        $this->assertSame(0, MonitoredPage::query()->monitored()->count());
        $this->assertSame(3, MonitoredPage::query()->where('approval_status', 'pending')->count());
    }

    public function test_default_sitemap_is_used_when_nothing_is_declared(): void
    {
        $this->settings(['max_depth' => 0]);

        $run = $this->discover([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(['https://example.com/a', 'https://example.com/b'])),
        ]);

        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $this->urls());
        $this->assertSame('completed', $run->status);
    }

    public function test_internal_links_are_crawled_within_depth_and_scope(): void
    {
        $this->settings(['max_depth' => 2, 'excluded_patterns' => ['/logout*']]);

        $run = $this->discover([
            'https://example.com/' => Http::response('<a href="/about">About</a> <a href="docs/">Docs</a> <a href="https://evil.test/">x</a> <a href="mailto:a@b.c">m</a> <a href="/logout">out</a> <a rel="nofollow" href="/hidden">h</a> <a href="/about#team">again</a>', 200, ['Content-Type' => 'text/html']),
            'https://example.com/about' => Http::response('<a href="/team">Team</a>', 200, ['Content-Type' => 'text/html']),
            'https://example.com/docs/' => Http::response('<a href="/docs/install">Install</a>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame([
            'https://example.com/',
            'https://example.com/about',
            'https://example.com/docs/',
            'https://example.com/docs/install',
            'https://example.com/team',
        ], $this->urls());

        // Depth-2 pages are recorded but not fetched.
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://example.com/team');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.test'));
        $this->assertSame(0, MonitoredPage::query()->where('depth', '>', 2)->count());
        $this->assertSame('completed', $run->status);
    }

    public function test_limits_are_enforced(): void
    {
        $this->settings(['max_depth' => 0, 'max_pages' => 5]);

        $run = $this->discover([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(array_map(fn ($i) => "https://example.com/p{$i}", range(1, 40)))),
        ]);

        $this->assertCount(5, $this->urls());
        $this->assertSame('partial', $run->status);

        config(['serverscope.websites.discovery_max_requests' => 2]);
        MonitoredPage::query()->delete();
        $this->settings(['max_depth' => 3, 'max_pages' => 50]);
        $run = $this->discover([
            'https://example.com/' => Http::response('<a href="/a">a</a><a href="/b">b</a><a href="/c">c</a>', 200, ['Content-Type' => 'text/html']),
            'https://example.com/a' => Http::response('<a href="/a2">a</a>', 200, ['Content-Type' => 'text/html']),
        ]);
        $this->assertSame(2, $run->requests_made);
        $this->assertSame('partial', $run->status);
    }

    public function test_allowed_patterns_and_query_parameters(): void
    {
        $this->settings(['max_depth' => 0, 'allowed_patterns' => ['/docs/*'], 'keep_query' => true]);

        $this->discover([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(['https://example.com/docs/a?v=2&amp;utm_campaign=x', 'https://example.com/blog/b', 'https://example.com/docs/a?v=2'])),
        ]);

        $this->assertSame(['https://example.com/docs/a?v=2'], $this->urls());
    }

    public function test_subdomains_only_with_explicit_opt_in(): void
    {
        $this->settings(['max_depth' => 0, 'include_subdomains' => true]);

        $this->discover([
            'https://example.com/sitemap.xml' => Http::response($this->urlset(['https://docs.example.com/start', 'https://notexample.com/x', 'https://evil.test/x'])),
        ]);

        $this->assertSame(['https://docs.example.com/start'], $this->urls());
    }

    public function test_ssrf_targets_in_sitemaps_and_redirects_are_never_fetched(): void
    {
        $this->dns->host('internal.example.com', '10.0.0.8');
        $this->settings(['max_depth' => 1, 'include_subdomains' => true, 'sitemap_urls' => ['https://internal.example.com/sitemap.xml', 'http://169.254.169.254/sitemap.xml']]);

        $run = $this->discover([
            'https://example.com/' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
        ]);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'internal.example.com') || str_contains($request->url(), '169.254') || str_contains($request->url(), '127.0.0.1'));
        $this->assertSame([], $this->urls());
        $this->assertSame('failed', $run->status);
        $this->assertNotEmpty($run->errors);
    }

    public function test_sitemaps_on_other_hosts_and_cross_domain_redirects_are_ignored(): void
    {
        $this->settings(['max_depth' => 0, 'sitemap_urls' => ['https://evil.test/sitemap.xml', 'https://example.com/moved.xml']]);

        $run = $this->discover([
            'https://example.com/moved.xml' => Http::response('', 301, ['Location' => 'https://evil.test/sitemap.xml']),
            'https://evil.test/sitemap.xml' => Http::response($this->urlset(['https://example.com/injected'])),
        ]);

        $this->assertSame([], $this->urls());
        $this->assertStringContainsString('buiten het domein', implode(' ', $run->errors));
        $this->assertStringContainsString('ander domein', implode(' ', $run->errors));
    }

    public function test_redirect_loops_and_malformed_sitemaps_are_recorded_without_touching_existing_pages(): void
    {
        $existing = MonitoredPage::query()->create(['domain_id' => $this->domain->id, 'url' => 'https://example.com/keep', 'url_hash' => MonitoredPage::hashUrl('https://example.com/keep')]);
        $existing->forceFill(['status' => 'up'])->save();
        $this->settings(['max_depth' => 1, 'sitemap_urls' => ['https://example.com/broken.xml', 'https://example.com/evil.xml']]);

        $run = $this->discover([
            'https://example.com/broken.xml' => Http::response('<urlset><url><loc>https://example.com/x'),
            'https://example.com/evil.xml' => Http::response('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>'),
            'https://example.com/' => Http::response('', 302, ['Location' => '/a']),
            'https://example.com/a' => Http::response('', 302, ['Location' => '/']),
        ]);

        $errors = implode(' | ', $run->errors);
        $this->assertStringContainsString('Ongeldige XML', $errors);
        $this->assertStringContainsString('DOCTYPE', $errors);
        $this->assertStringContainsString('Redirect-lus', $errors);
        $this->assertSame('failed', $run->status);

        $existing->refresh();
        $this->assertSame('up', $existing->status);
        $this->assertSame('approved', $existing->approval_status);
        $this->assertSame(['https://example.com/keep'], $this->urls());
    }

    public function test_rediscovery_does_not_duplicate_and_auto_monitor_approves(): void
    {
        $this->settings(['max_depth' => 0]);
        $routes = ['https://example.com/sitemap.xml' => Http::response($this->urlset(['https://example.com/a', 'https://example.com/b']))];

        $this->discover($routes);
        MonitoredPage::query()->where('url', 'https://example.com/a')->update(['approval_status' => 'rejected']);
        $second = $this->discover($routes);

        $this->assertSame(0, $second->pages_added);
        $this->assertSame(2, $second->pages_seen);
        $this->assertSame('rejected', MonitoredPage::query()->where('url', 'https://example.com/a')->value('approval_status'), 'a rejected page stays rejected');

        $this->settings(['auto_monitor' => true, 'default_interval' => 30]);
        $third = $this->discover(['https://example.com/sitemap.xml' => Http::response($this->urlset(['https://example.com/c']))]);
        $this->assertSame(1, $third->pages_added);
        $page = MonitoredPage::query()->where('url', 'https://example.com/c')->sole();
        $this->assertTrue($page->isMonitored());
        $this->assertSame(30, $page->check_interval_minutes);
    }
}
