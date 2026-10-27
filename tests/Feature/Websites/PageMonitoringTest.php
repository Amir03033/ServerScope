<?php

namespace Tests\Feature\Websites;

use App\Jobs\RunPageCheck;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Models\MonitoredPage;
use App\Models\PageChange;
use App\Models\User;
use App\Monitoring\Dns\Resolver;
use App\Notifications\IncidentNotification;
use App\Notifications\PageChangeNotification;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Websites\PageMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class PageMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private FakeResolver $dns;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeResolver;
        $this->dns->host('example.com', '93.184.215.14');
        $this->dns->host('shop.example.com', '93.184.215.15');
        $this->app->instance(Resolver::class, $this->dns);
        config(['serverscope.monitoring.requests_per_ip_per_minute' => 10000]);
        Notification::fake();
        Http::preventStrayRequests();

        $this->domain = Domain::query()->create(['name' => 'example.com']);
    }

    private function page(string $path = '/', array $attributes = []): MonitoredPage
    {
        $url = 'https://example.com'.$path;

        return MonitoredPage::query()->create(['domain_id' => $this->domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), 'failure_threshold' => 1, 'recovery_threshold' => 1, ...$attributes]);
    }

    /** @param  array<string, mixed>  $routes  url pattern => response or callable */
    private function fake(array $routes): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($routes);
    }

    private function check(MonitoredPage $page): MonitoredPage
    {
        app(PageMonitor::class)->check($page->fresh('domain'));

        return $page->fresh();
    }

    public function test_successful_check_records_history_and_title(): void
    {
        $page = $this->page('/pricing', ['keyword_present' => 'Starter']);
        $this->fake(['*' => Http::response('<html><head><title> Prijzen | Example </title></head><body>Starter plan</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $page = $this->check($page);

        $this->assertSame('up', $page->status);
        $this->assertSame('Prijzen | Example', $page->title);
        $this->assertSame(200, $page->last_status_code);
        $this->assertTrue($page->tls_valid);
        $this->assertNotNull($page->last_success_at);
        $this->assertNotNull($page->next_check_at);
        $this->assertDatabaseHas('page_checks', ['monitored_page_id' => $page->id, 'domain_id' => $this->domain->id, 'result' => 'up', 'success' => true]);
    }

    public function test_failure_types_are_distinguished(): void
    {
        $cases = [
            'network' => [fn () => throw new ConnectionException('cURL error 7: Connection refused'), []],
            'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'), []],
            'tls' => [fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: certificate has expired'), []],
            'status' => [Http::response('Not found', 404), []],
            'content' => [Http::response('Fatal error in app', 200), ['keyword_absent' => 'Fatal error']],
            'header' => [Http::response('ok', 200, ['X-Frame-Options' => 'DENY']), ['header_assertions' => [['name' => 'Strict-Transport-Security', 'mode' => 'present', 'value' => null]]]],
            'redirect' => [Http::response('ok', 200), ['expected_final_url' => 'https://example.com/elders']],
        ];

        foreach ($cases as $expected => [$response, $attributes]) {
            $page = $this->page("/{$expected}", $attributes);
            $this->fake(['*' => $response]);

            $page = $this->check($page);

            $this->assertSame($expected, $page->last_failure_type, $expected);
            $this->assertSame('down', $page->status, $expected);
            $this->assertDatabaseHas('page_checks', ['monitored_page_id' => $page->id, 'success' => false, 'failure_type' => $expected]);

            if ($expected === 'tls') {
                $this->assertFalse($page->tls_valid);
            }
        }
    }

    public function test_redirect_chain_is_recorded_and_loops_fail(): void
    {
        $page = $this->page('/old');
        $this->fake([
            'https://example.com/old' => Http::response('', 301, ['Location' => '/new']),
            'https://example.com/new' => Http::response('ok', 200),
        ]);

        $page = $this->check($page);
        $this->assertSame('up', $page->status);
        $this->assertSame('https://example.com/new', $page->last_final_url);
        $this->assertSame(301, $page->checks()->first()->details['redirects'][0]['status']);

        $loop = $this->page('/loop');
        $this->fake([
            'https://example.com/loop' => Http::response('', 302, ['Location' => '/loop2']),
            'https://example.com/loop2' => Http::response('', 302, ['Location' => '/loop']),
        ]);

        $loop = $this->check($loop);
        $this->assertSame('redirect', $loop->last_failure_type);
    }

    public function test_redirects_to_internal_addresses_are_blocked_without_a_request(): void
    {
        $page = $this->page('/go');
        $this->fake([
            'https://example.com/go' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $page = $this->check($page);

        $this->assertSame('blocked', $page->last_failure_type);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_pages_resolving_to_private_addresses_are_never_requested(): void
    {
        $this->dns->host('intern.example.com', '10.0.0.5');
        $url = 'https://intern.example.com/';
        $page = MonitoredPage::query()->create(['domain_id' => $this->domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), 'failure_threshold' => 1]);
        $this->fake([]);

        $page = $this->check($page);

        $this->assertSame('blocked', $page->last_failure_type);
        Http::assertNothingSent();
    }

    public function test_slow_responses_degrade_without_failing(): void
    {
        $page = $this->page('/', ['max_response_ms' => 50]);
        $this->fake(['*' => function () {
            usleep(80_000);

            return Http::response('ok', 200);
        }]);

        $page = $this->check($page);

        $this->assertSame('degraded', $page->status);
        $this->assertSame('slow', $page->last_failure_type);
        $this->assertSame(0, Incident::query()->count());
        $this->assertDatabaseHas('page_checks', ['monitored_page_id' => $page->id, 'result' => 'degraded', 'success' => true]);
    }

    public function test_thresholds_and_recovery_confirmation(): void
    {
        $page = $this->page('/', ['failure_threshold' => 3, 'recovery_threshold' => 2]);
        $this->fake(['*' => Http::response('down', 500)]);

        $page = $this->check($page);
        $this->assertSame('degraded', $page->status, 'one failure is not an outage');
        $page = $this->check($page);
        $this->assertSame(0, Incident::query()->count());
        $page = $this->check($page);
        $this->assertSame('down', $page->status);
        $incident = Incident::query()->sole();
        $this->assertSame('page:'.$page->id, $incident->fingerprint);
        $this->assertNotNull($incident->started_at);

        $this->fake(['*' => Http::response('ok', 200)]);
        $page = $this->check($page);
        $this->assertSame('down', $page->status, 'recovery needs two successes');
        $this->assertSame('open', $incident->fresh()->status);

        $page = $this->check($page);
        $this->assertSame('up', $page->status);
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNull($page->failing_since);
    }

    public function test_a_single_failing_page_does_not_affect_other_pages(): void
    {
        $home = $this->page('/');
        $missing = $this->page('/old-page');
        $this->fake([
            'https://example.com/old-page' => Http::response('Not found', 404),
            '*' => Http::response('ok', 200),
        ]);

        $home = $this->check($home);
        $missing = $this->check($missing);

        $this->assertSame('up', $home->status);
        $this->assertSame('down', $missing->status);

        $incident = Incident::query()->sole();
        $this->assertSame('page', $incident->subject_type);
        $this->assertSame($missing->id, $incident->subject_id);
        $this->assertStringContainsString('/old-page', $incident->title);
    }

    public function test_shared_failures_are_grouped_into_one_website_incident(): void
    {
        $pages = collect(['/', '/pricing', '/docs'])->map(fn ($p) => $this->page($p));
        $this->fake(['*' => fn () => throw new ConnectionException('cURL error 7: Connection refused')]);

        foreach ($pages as $page) {
            $this->check($page);
        }

        $group = Incident::query()->where('fingerprint', 'like', 'website:%')->sole();
        $this->assertSame('domain', $group->subject_type);
        $this->assertStringContainsString('3 pagina', $group->title);
        $this->assertSame(3, IncidentImpact::query()->where('incident_id', $group->id)->where('status', 'failing')->count());

        // The first page opened its own incident before others failed;
        // it now sits under the website incident, details preserved.
        $first = Incident::query()->where('fingerprint', 'page:'.$pages[0]->id)->sole();
        $this->assertSame($group->id, $first->parent_id);
        // Structural relation page -> website, recorded with its path.
        $this->assertSame(['page:'.$pages[0]->id, 'domain:'.$this->domain->id], $first->correlation['path']);
        $this->assertSame(2, Incident::query()->active()->count());

        // One notification for the shared problem plus the first page's own
        // (sent before the cause was known); the grouped page incident is
        // not notified again.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->check($pages[1]);
        Notification::assertNotSentTo($admin, IncidentNotification::class);

        // Recovery: the group resolves only when the last page recovered.
        $this->fake(['*' => Http::response('ok', 200)]);
        $this->check($pages[0]);
        $this->check($pages[1]);
        $this->assertTrue($group->fresh()->isActive());
        $this->assertSame(1, IncidentImpact::query()->where('incident_id', $group->id)->where('status', 'failing')->count());

        $this->check($pages[2]);
        $this->assertSame('resolved', $group->fresh()->status);
        $this->assertTrue($group->events()->where('type', 'resource_recovered')->exists());
    }

    public function test_tls_failures_group_under_the_domain_certificate_incident(): void
    {
        $ssl = app(IncidentManager::class)->trigger(new Signal("domain:{$this->domain->id}:ssl", 'ssl', 'critical', 'Certificaat verlopen', null, $this->domain, ['ssl_status' => 'expired']));
        $pages = collect(['/', '/login'])->map(fn ($p) => $this->page($p));
        $this->fake(['*' => fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: certificate has expired')]);

        foreach ($pages as $page) {
            $this->check($page);
        }

        $group = Incident::query()->where('fingerprint', 'like', 'website:%:tls')->sole();
        $this->assertSame($ssl->id, $group->parent_id);
        $this->assertSame('same_resource', $group->correlation['reason_code']);
    }

    public function test_server_errors_on_several_pages_are_grouped_but_404s_are_not(): void
    {
        $a = $this->page('/a');
        $b = $this->page('/b');
        $this->fake(['*' => Http::response('Gateway', 502)]);
        $this->check($a);
        $this->check($b);
        $this->assertSame(1, Incident::query()->where('fingerprint', 'like', 'website:%:server_error')->count());

        $c = $this->page('/c');
        $d = $this->page('/d');
        $this->fake(['*' => Http::response('Not found', 404)]);
        $this->check($c);
        $this->check($d);
        $this->assertSame(2, Incident::query()->whereIn('fingerprint', ['page:'.$c->id, 'page:'.$d->id])->count());
    }

    public function test_page_alert_settings_control_notifications_and_severity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $muted = $this->page('/muted', ['alerts_enabled' => false]);
        $low = $this->page('/low', ['alert_severity' => 'warning']);
        $this->fake(['*' => Http::response('Not found', 404)]);

        $this->check($muted);
        Notification::assertNothingSent();
        $this->assertSame(1, Incident::query()->count(), 'the incident is still recorded');

        $this->check($low);
        $this->assertSame('warning', Incident::query()->where('fingerprint', 'page:'.$low->id)->value('severity'));
        Notification::assertSentTo($admin, IncidentNotification::class);
    }

    public function test_no_incidents_during_domain_maintenance(): void
    {
        $this->domain->forceFill(['maintenance_starts_at' => now()->subHour()])->save();
        $page = $this->page('/');
        $this->fake(['*' => Http::response('', 500)]);

        $page = $this->check($page);

        $this->assertSame('down', $page->status);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_content_changes_are_recorded_but_dynamic_content_is_ignored(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $page = $this->page('/pricing', ['content_monitoring' => true, 'content_selector' => 'main', 'notify_content_changes' => true]);
        $html = fn (string $price, string $time) => "<html><body><nav>Menu {$time}</nav><main><h1>Prijzen</h1><p>Starter {$price}</p><p>Bijgewerkt {$time}</p></main></body></html>";

        $this->fake(['*' => Http::response($html('€10', '2026-10-26 10:00'), 200, ['Content-Type' => 'text/html'])]);
        $page = $this->check($page);
        $this->assertNotNull($page->content_fingerprint);
        $this->assertSame(0, PageChange::query()->count(), 'the first fetch is the baseline');

        $this->fake(['*' => Http::response($html('€10', '2026-10-26 11:30'), 200, ['Content-Type' => 'text/html'])]);
        $this->check($page);
        $this->assertSame(0, PageChange::query()->count(), 'a changed timestamp is not a change');

        $this->fake(['*' => Http::response($html('€15', '2026-10-26 12:00'), 200, ['Content-Type' => 'text/html'])]);
        $page = $this->check($page);

        $change = PageChange::query()->sole();
        $this->assertSame('content', $change->kind);
        $this->assertSame('significant', $change->significance);
        $this->assertSame(['Starter €15'], $change->diff['added']);
        $this->assertSame(['Starter €10'], $change->diff['removed']);
        $this->assertNotNull($page->content_changed_at);
        $this->assertSame('up', $page->status, 'a content change is not a failure');
        $this->assertSame(0, Incident::query()->count());
        $this->assertTrue($page->checks()->latest('id')->first()->content_changed);
        Notification::assertSentTo($admin, PageChangeNotification::class);

        $this->actingAs($admin)->post(route('page-changes.acknowledge', $change))->assertSessionHas('success');
        $this->assertNotNull($change->fresh()->acknowledged_at);
    }

    public function test_monitored_header_drift_is_recorded(): void
    {
        $page = $this->page('/', ['monitored_headers' => ['content-security-policy']]);

        $this->fake(['*' => Http::response('ok', 200, ['Content-Security-Policy' => "default-src 'self'"])]);
        $this->check($page);
        $this->fake(['*' => Http::response('ok', 200, ['Content-Security-Policy' => '*'])]);
        $this->check($page);

        $change = PageChange::query()->sole();
        $this->assertSame('headers', $change->kind);
        $this->assertSame("default-src 'self'", $change->diff['headers']['content-security-policy']['from']);
        $this->assertSame('*', $change->diff['headers']['content-security-policy']['to']);
    }

    public function test_own_rate_limit_skips_without_failing(): void
    {
        config(['serverscope.monitoring.requests_per_ip_per_minute' => 1]);
        $page = $this->page('/');
        $this->fake(['*' => Http::response('ok', 200)]);

        $this->check($page);
        $page = $this->check($page);

        $this->assertSame('up', $page->status);
        $this->assertSame(1, $page->checks()->count());
    }

    public function test_dispatcher_claims_due_pages_and_caps_per_website(): void
    {
        Queue::fake();
        config(['serverscope.websites.checks_per_website_per_run' => 3]);

        foreach (range(1, 5) as $i) {
            $this->page("/p{$i}");
        }

        $this->page('/paused', ['is_active' => false]);
        $this->page('/pending', ['approval_status' => 'pending']);

        $this->artisan('serverscope:dispatch-page-checks')->assertSuccessful();
        Queue::assertPushed(RunPageCheck::class, 3);

        // Claimed pages are not queued twice; the rest follow next run.
        $this->artisan('serverscope:dispatch-page-checks')->assertSuccessful();
        Queue::assertPushed(RunPageCheck::class, 5);

        $this->artisan('serverscope:dispatch-page-checks')->assertSuccessful();
        Queue::assertPushed(RunPageCheck::class, 5);

        $this->domain->forceFill(['is_active' => false])->save();
        MonitoredPage::query()->update(['next_check_at' => null]);
        $this->artisan('serverscope:dispatch-page-checks')->assertSuccessful();
        Queue::assertPushed(RunPageCheck::class, 5);
    }

    public function test_job_skips_pages_that_are_no_longer_monitored(): void
    {
        $page = $this->page('/', ['is_active' => false]);
        $this->fake([]);

        (new RunPageCheck($page->id))->handle(app(PageMonitor::class));
        (new RunPageCheck(999))->handle(app(PageMonitor::class));

        Http::assertNothingSent();
        $this->assertSame(0, $page->checks()->count());
    }
}
