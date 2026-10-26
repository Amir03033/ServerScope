<?php

namespace Tests\Feature\Websites;

use App\Jobs\RunPageCheck;
use App\Jobs\RunPageDiscovery;
use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Models\PageChange;
use App\Models\PageDiscoveryRun;
use App\Models\User;
use App\Monitoring\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class WebsitePagesUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private Domain $domain;

    private FakeResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->dns = new FakeResolver;
        $this->dns->host('example.com', '93.184.215.14');
        $this->dns->host('www.example.com', '93.184.215.14');
        $this->dns->host('internal.example.com', '192.168.1.10');
        $this->app->instance(Resolver::class, $this->dns);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->viewer = User::factory()->create();
        $this->domain = Domain::query()->create(['name' => 'example.com']);
    }

    private function settings(array $overrides = []): array
    {
        return [
            'check_interval_minutes' => 5, 'timeout_seconds' => 10, 'failure_threshold' => 2, 'recovery_threshold' => 2,
            'alert_severity' => 'high', 'alerts_enabled' => '1', 'follow_redirects' => '1', ...$overrides,
        ];
    }

    private function page(string $path, array $attributes = []): MonitoredPage
    {
        $url = 'https://example.com'.$path;

        return MonitoredPage::query()->create(['domain_id' => $this->domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), ...$attributes]);
    }

    public function test_admin_adds_several_pages_with_relative_paths(): void
    {
        $this->actingAs($this->admin)->get(route('websites.pages.create', $this->domain))->assertOk();

        $this->actingAs($this->admin)->post(route('websites.pages.store', $this->domain), $this->settings([
            'urls' => "https://example.com/\n/pricing\n/pricing#plans\nhttps://www.example.com/docs\n/api/health",
            'keyword_present' => 'ok',
            'header_assertions' => "Strict-Transport-Security\n!X-Powered-By\nContent-Type ~ json",
            'monitored_headers' => 'Content-Security-Policy, x-frame-options',
            'content_monitoring' => '1',
            'content_selector' => 'main',
            'content_exclusions' => ".ads\n#news",
        ]))->assertRedirect(route('websites.pages', $this->domain));

        $this->assertSame([
            'https://example.com/',
            'https://example.com/api/health',
            'https://example.com/pricing',
            'https://www.example.com/docs',
        ], MonitoredPage::query()->orderBy('url')->pluck('url')->all());

        $page = MonitoredPage::query()->where('url', 'https://example.com/api/health')->sole();
        $this->assertSame('manual', $page->source);
        $this->assertTrue($page->isMonitored());
        $this->assertSame([
            ['name' => 'Strict-Transport-Security', 'mode' => 'present', 'value' => null],
            ['name' => 'X-Powered-By', 'mode' => 'absent', 'value' => null],
            ['name' => 'Content-Type', 'mode' => 'contains', 'value' => 'json'],
        ], $page->header_assertions);
        $this->assertSame(['content-security-policy', 'x-frame-options'], $page->monitored_headers);
        $this->assertSame(['.ads', '#news'], $page->content_exclusions);
        Queue::assertPushed(RunPageCheck::class, 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'website.pages_added']);
    }

    public function test_page_urls_are_validated(): void
    {
        $cases = [
            'https://other.org/x' => 'hoort niet bij',
            'ftp://example.com/x' => 'Ongeldige URL',
            'https://internal.example.com/' => 'intern',
            'https://user:pw@example.com/' => 'Ongeldige URL',
        ];

        foreach ($cases as $url => $message) {
            $response = $this->actingAs($this->admin)->post(route('websites.pages.store', $this->domain), $this->settings(['urls' => $url]));
            $response->assertSessionHasErrors('urls');
            $this->assertStringContainsString($message, session('errors')->first('urls'), $url);
        }

        $this->actingAs($this->admin)->post(route('websites.pages.store', $this->domain), $this->settings(['urls' => '/x', 'content_selector' => 'div > p']))->assertSessionHasErrors('content_selector');
        $this->actingAs($this->admin)->post(route('websites.pages.store', $this->domain), $this->settings(['urls' => '/x', 'expected_status_codes' => '20x']))->assertSessionHasErrors('expected_status_codes');
        $this->actingAs($this->admin)->post(route('websites.pages.store', $this->domain), $this->settings(['urls' => implode("\n", array_map(fn ($i) => "/p{$i}", range(1, 51)))]))->assertSessionHasErrors('urls');
        $this->assertSame(0, MonitoredPage::query()->count());
    }

    public function test_viewers_can_view_but_not_change(): void
    {
        $page = $this->page('/pricing');

        $this->actingAs($this->viewer)->get(route('websites.pages', $this->domain))->assertOk()->assertSee('/pricing')->assertDontSee('Bulkactie');
        $this->actingAs($this->viewer)->get(route('pages.show', $page))->assertOk();
        $this->actingAs($this->viewer)->get(route('websites.discovery', $this->domain))->assertOk();

        $this->actingAs($this->viewer)->get(route('websites.pages.create', $this->domain))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('websites.pages.store', $this->domain), $this->settings(['urls' => '/x']))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('pages.edit', $page))->assertForbidden();
        $this->actingAs($this->viewer)->put(route('pages.update', $page), $this->settings(['url' => $page->url]))->assertForbidden();
        $this->actingAs($this->viewer)->delete(route('pages.destroy', $page))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('pages.pause', $page))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('websites.pages.bulk', $this->domain), ['action' => 'delete', 'ids' => [$page->id]])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('websites.discovery.run', $this->domain))->assertForbidden();
        $this->actingAs($this->viewer)->put(route('websites.discovery.update', $this->domain), ['max_pages' => 10, 'max_depth' => 1, 'default_interval' => 5])->assertForbidden();

        $this->post('/logout');
        $this->get(route('websites.pages', $this->domain))->assertRedirect(route('login'));
        $this->assertDatabaseHas('monitored_pages', ['id' => $page->id]);
    }

    public function test_overview_lists_filters_sorts_and_paginates(): void
    {
        $up = $this->page('/up');
        $up->forceFill(['status' => 'up', 'last_checked_at' => now(), 'last_response_ms' => 120])->save();
        $down = $this->page('/broken');
        $down->forceFill(['status' => 'down', 'last_failure_type' => 'status', 'last_status_code' => 404, 'last_checked_at' => now()])->save();
        $pending = $this->page('/found', ['approval_status' => 'pending', 'source' => 'sitemap']);

        foreach (range(1, 30) as $i) {
            $up->checks()->create(['domain_id' => $this->domain->id, 'result' => 'up', 'success' => true, 'response_time_ms' => $i * 10, 'checked_at' => now()->subMinutes($i)]);
        }

        $response = $this->actingAs($this->admin)->get(route('websites.pages', $this->domain));
        $response->assertOk()->assertSee('/broken')->assertSee('/up')->assertSee('Wacht op goedkeuring')->assertSee('p95');

        $this->actingAs($this->admin)->get(route('websites.pages', [$this->domain, 'status' => 'down']))->assertSee('/broken')->assertDontSee('href="'.route('pages.show', $up).'"', false);
        $this->actingAs($this->admin)->get(route('websites.pages', [$this->domain, 'status' => 'pending']))->assertSee('/found');
        $this->actingAs($this->admin)->get(route('websites.pages', [$this->domain, 'q' => 'brok']))->assertSee('/broken')->assertDontSee('/found');
        $this->actingAs($this->admin)->get(route('websites.pages', [$this->domain, 'sort' => 'response', 'direction' => 'asc']))->assertOk();
        $this->actingAs($this->admin)->get(route('websites.pages', [$this->domain, 'status' => 'bogus']))->assertSessionHasErrors('status');

        foreach (range(1, 60) as $i) {
            $this->page("/bulk-{$i}");
        }

        $this->actingAs($this->admin)->get(route('websites.pages', $this->domain))->assertSee('Volgende');
        $this->actingAs($this->admin)->get(route('pages.show', $up))->assertOk()->assertSee('Controlegeschiedenis');
        $this->actingAs($this->admin)->get(route('domains.show', $this->domain))->assertOk()->assertSee('Website &amp; pagina', false);
    }

    public function test_empty_states(): void
    {
        $this->actingAs($this->admin)->get(route('websites.pages', $this->domain))->assertOk()->assertSee('Nog geen pagina');
        $this->actingAs($this->admin)->get(route('websites.discovery', $this->domain))->assertOk()->assertSee('Nog geen ontdekking uitgevoerd');
    }

    public function test_bulk_actions_only_affect_pages_of_the_website(): void
    {
        $a = $this->page('/a', ['approval_status' => 'pending']);
        $b = $this->page('/b', ['approval_status' => 'pending']);
        $other = Domain::query()->create(['name' => 'other.example']);
        $foreign = MonitoredPage::query()->create(['domain_id' => $other->id, 'url' => 'https://other.example/', 'url_hash' => MonitoredPage::hashUrl('https://other.example/')]);

        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'approve', 'ids' => [$a->id, $foreign->id]])->assertSessionHas('success');
        $this->assertTrue($a->fresh()->isMonitored());
        Queue::assertPushed(RunPageCheck::class, 1);

        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'reject', 'ids' => [$b->id]]);
        $this->assertSame('rejected', $b->fresh()->approval_status);

        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'pause', 'ids' => [$a->id]]);
        $this->assertFalse($a->fresh()->is_active);

        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'interval', 'ids' => [$a->id]])->assertSessionHasErrors('interval');
        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'interval', 'ids' => [$a->id], 'interval' => 30]);
        $this->assertSame(30, $a->fresh()->check_interval_minutes);

        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'delete', 'ids' => [$a->id, $foreign->id]]);
        $this->assertDatabaseMissing('monitored_pages', ['id' => $a->id]);
        $this->assertDatabaseHas('monitored_pages', ['id' => $foreign->id]);

        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'approve'])->assertSessionHasErrors('ids');
        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'nuke', 'ids' => [$b->id]])->assertSessionHasErrors('action');
    }

    public function test_approval_revalidates_the_destination(): void
    {
        $url = 'https://internal.example.com/';
        $page = MonitoredPage::query()->create(['domain_id' => $this->domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), 'approval_status' => 'pending']);

        $this->actingAs($this->admin)->post(route('websites.pages.bulk', $this->domain), ['action' => 'approve', 'ids' => [$page->id]])
            ->assertSessionHas('error');

        $this->assertSame('pending', $page->fresh()->approval_status);
        Queue::assertNothingPushed();
    }

    public function test_pause_keeps_history_and_edit_resets_baseline_on_url_change(): void
    {
        $page = $this->page('/old', ['content_monitoring' => true]);
        $page->checks()->create(['domain_id' => $this->domain->id, 'result' => 'up', 'success' => true, 'checked_at' => now()]);
        $page->forceFill(['content_fingerprint' => str_repeat('a', 64), 'status' => 'up'])->save();

        $this->actingAs($this->admin)->post(route('pages.pause', $page))->assertSessionHas('success');
        $this->assertFalse($page->fresh()->is_active);
        $this->assertSame(1, $page->checks()->count());

        $this->actingAs($this->admin)->post(route('pages.resume', $page))->assertSessionHas('success');
        $this->assertTrue($page->fresh()->is_active);

        $this->actingAs($this->admin)->get(route('pages.edit', $page))->assertOk();
        $this->actingAs($this->admin)->put(route('pages.update', $page), $this->settings(['url' => '/new', 'content_monitoring' => '1', 'max_response_ms' => 800]))
            ->assertRedirect(route('pages.show', $page));

        $page->refresh();
        $this->assertSame('https://example.com/new', $page->url);
        $this->assertSame('unknown', $page->status);
        $this->assertNull($page->content_fingerprint);
        $this->assertSame(800, $page->max_response_ms);
        $this->assertSame(1, $page->checks()->count(), 'history is kept');

        $other = $this->page('/taken');
        $this->actingAs($this->admin)->put(route('pages.update', $page), $this->settings(['url' => '/taken']))->assertSessionHasErrors('url');

        $this->actingAs($this->admin)->delete(route('pages.destroy', $other))->assertRedirect(route('websites.pages', $this->domain));
        $this->assertDatabaseMissing('monitored_pages', ['id' => $other->id]);
    }

    public function test_discovery_settings_require_confirmation_for_broad_coverage(): void
    {
        $base = ['max_pages' => 20, 'max_depth' => 1, 'default_interval' => 15, 'excluded_patterns' => "/admin/*\n*.pdf", 'sitemap_urls' => 'https://example.com/s.xml'];

        $this->actingAs($this->admin)->put(route('websites.discovery.update', $this->domain), $base)->assertSessionHas('success');
        $settings = $this->domain->fresh()->discoverySettings();
        $this->assertSame(['/admin/*', '*.pdf'], $settings['excluded_patterns']);
        $this->assertSame(20, $settings['max_pages']);
        $this->assertFalse($settings['auto_monitor']);

        $this->actingAs($this->admin)->put(route('websites.discovery.update', $this->domain), [...$base, 'max_pages' => 500])->assertSessionHasErrors('broad_confirmed');
        $this->actingAs($this->admin)->put(route('websites.discovery.update', $this->domain), [...$base, 'include_subdomains' => '1'])->assertSessionHasErrors('broad_confirmed');
        $this->actingAs($this->admin)->put(route('websites.discovery.update', $this->domain), [...$base, 'max_pages' => 500, 'auto_monitor' => '1', 'broad_confirmed' => '1'])->assertSessionHas('success');
        $this->assertTrue($this->domain->fresh()->discoverySettings()['auto_monitor']);

        $this->actingAs($this->admin)->put(route('websites.discovery.update', $this->domain), [...$base, 'max_pages' => 999999, 'broad_confirmed' => '1'])->assertSessionHasErrors('max_pages');
        $this->actingAs($this->admin)->put(route('websites.discovery.update', $this->domain), [...$base, 'sitemap_urls' => 'javascript:alert(1)'])->assertSessionHasErrors('sitemap_urls');
    }

    public function test_discovery_runs_are_queued_once_at_a_time(): void
    {
        $this->actingAs($this->admin)->post(route('websites.discovery.run', $this->domain))->assertRedirect(route('websites.discovery', $this->domain));
        Queue::assertPushed(RunPageDiscovery::class, 1);
        $this->assertSame('queued', PageDiscoveryRun::query()->sole()->status);

        $this->actingAs($this->admin)->post(route('websites.discovery.run', $this->domain))->assertSessionHas('error');
        Queue::assertPushed(RunPageDiscovery::class, 1);

        // A run that never finished does not block forever.
        $this->travel(20)->minutes();
        $this->actingAs($this->admin)->post(route('websites.discovery.run', $this->domain))->assertSessionHas('success');
        $this->assertSame('failed', PageDiscoveryRun::query()->oldest('id')->first()->status);

        $this->domain->forceFill(['is_active' => false])->save();
        PageDiscoveryRun::query()->update(['status' => 'completed']);
        $this->actingAs($this->admin)->post(route('websites.discovery.run', $this->domain))->assertSessionHas('error');

        $this->actingAs($this->admin)->get(route('websites.discovery', $this->domain))->assertOk()->assertSee('Mislukt');
    }

    public function test_viewers_cannot_acknowledge_changes(): void
    {
        $page = $this->page('/');
        $change = PageChange::query()->create(['monitored_page_id' => $page->id, 'domain_id' => $this->domain->id, 'kind' => 'content', 'new_fingerprint' => str_repeat('b', 64), 'detected_at' => now(), 'summary' => 'x']);

        $this->actingAs($this->viewer)->post(route('page-changes.acknowledge', $change))->assertForbidden();
        $this->assertNull($change->fresh()->acknowledged_at);
    }
}
