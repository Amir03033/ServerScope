<?php

namespace Tests\Feature\Domains;

use App\Events\DnsRecordsChanged;
use App\Events\IncidentEscalated;
use App\Events\IncidentOpened;
use App\Events\IncidentReopened;
use App\Events\IncidentResolved;
use App\Jobs\RunDomainCheck;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\User;
use App\Monitoring\Dns\Resolver;
use App\Monitoring\Dns\ResolverFactory;
use App\Monitoring\Net\TlsInspector;
use App\Monitoring\Net\TlsResult;
use App\Services\Domains\DomainCheckRunner;
use App\Services\Reports\UptimeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\FakeTlsInspector;
use Tests\TestCase;

class DomainMonitorsTest extends TestCase
{
    use RefreshDatabase;

    private FakeResolver $dns;

    private FakeTlsInspector $tls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeResolver;
        $this->dns->host('example.com', '93.184.215.14');
        $this->app->instance(Resolver::class, $this->dns);
        $this->app->instance(ResolverFactory::class, new class($this->dns) extends ResolverFactory
        {
            public function __construct(private Resolver $fake) {}

            public function forNameserver(string $ip): Resolver
            {
                return $this->fake;
            }
        });

        $this->tls = new FakeTlsInspector;
        $this->app->instance(TlsInspector::class, $this->tls);

        config(['serverscope.monitoring.requests_per_ip_per_minute' => 1000]);
        Http::preventStrayRequests();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::create(['name' => 'example.com', 'failure_threshold' => 3, 'recovery_threshold' => 2, ...$attributes]);
    }

    private function check(Domain $domain, string $type): Domain
    {
        app(DomainCheckRunner::class)->run($domain, $type);

        return $domain->fresh();
    }

    private function http(int $status = 200, string $body = 'Welkom bij Example'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($body, $status)]);
    }

    private function httpDown(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Connection refused'));
    }

    // ---------------- HTTP ----------------

    public function test_single_failure_does_not_open_an_incident(): void
    {
        $domain = $this->domain();
        $this->httpDown();

        $domain = $this->check($domain, 'http');

        $this->assertSame(1, $domain->http_consecutive_failures);
        $this->assertSame('unknown', $domain->http_status);
        $this->assertSame('warning', $domain->status);
        $this->assertSame(0, Incident::query()->count());
        $this->assertDatabaseHas('domain_checks', ['domain_id' => $domain->id, 'type' => 'http', 'success' => false, 'error_code' => 'connection_failed']);
    }

    public function test_threshold_opens_one_incident_and_repeated_failures_deduplicate(): void
    {
        Event::fake([IncidentOpened::class]);
        $domain = $this->domain();
        $this->httpDown();

        foreach (range(1, 6) as $i) {
            $domain = $this->check($domain, 'http');
        }

        $this->assertSame('down', $domain->http_status);
        $this->assertSame('critical', $domain->status);

        $incident = Incident::query()->sole();
        $this->assertSame('domain:'.$domain->id.':http', $incident->fingerprint);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('open', $incident->status);
        $this->assertSame(4, $incident->occurrence_count); // checks 3..6
        $this->assertTrue($incident->subject->is($domain));
        Event::assertDispatchedTimes(IncidentOpened::class, 1);
    }

    public function test_recovery_requires_consecutive_successes_and_resolves_the_incident(): void
    {
        Event::fake([IncidentResolved::class]);
        $domain = $this->domain(['failure_threshold' => 1, 'recovery_threshold' => 2]);
        $this->httpDown();
        $domain = $this->check($domain, 'http');
        $this->assertSame('down', $domain->http_status);

        $this->http();
        $domain = $this->check($domain, 'http');
        $this->assertSame('down', $domain->http_status, 'one success is not enough');
        $this->assertSame('open', Incident::query()->sole()->status);

        $domain = $this->check($domain, 'http');
        $this->assertSame('up', $domain->http_status);
        $this->assertSame('healthy', $domain->status);

        $incident = Incident::query()->sole();
        $this->assertSame('resolved', $incident->status);
        $this->assertNull($incident->resolved_by);
        $this->assertNull($incident->active_fingerprint);
        $this->assertSame('recovered', $incident->events->last()->type);
        Event::assertDispatchedTimes(IncidentResolved::class, 1);
    }

    public function test_recurrence_within_window_reopens_the_incident(): void
    {
        Event::fake([IncidentReopened::class]);
        config(['serverscope.incidents.reopen_window_minutes' => 30]);
        $domain = $this->domain(['failure_threshold' => 1, 'recovery_threshold' => 1]);

        $this->httpDown();
        $domain = $this->check($domain, 'http');
        $this->http();
        $domain = $this->check($domain, 'http');
        $this->travel(10)->minutes();
        $this->httpDown();
        $domain = $this->check($domain, 'http');

        $incident = Incident::query()->sole();
        $this->assertSame('open', $incident->status);
        $this->assertSame(1, $incident->reopened_count);
        Event::assertDispatched(IncidentReopened::class);

        // Outside the window a new incident is created.
        $this->http();
        $this->check($domain, 'http');
        $this->travel(31)->minutes();
        $this->httpDown();
        $this->check($domain, 'http');
        $this->assertSame(2, Incident::query()->count());
    }

    public function test_status_code_and_content_expectations(): void
    {
        $domain = $this->domain(['failure_threshold' => 1, 'expected_status_codes' => '200', 'expected_content' => 'Welkom']);

        $this->http(503);
        $this->assertSame('down', $this->check($domain, 'http')->http_status);
        $this->assertDatabaseHas('domain_checks', ['error_code' => 'unexpected_status', 'http_status_code' => 503]);

        $this->http(200, 'Onderhoud');
        $this->check($domain, 'http');
        $this->assertDatabaseHas('domain_checks', ['error_code' => 'content_mismatch']);

        $this->http(200, '<h1>Welkom</h1>');
        $this->check($domain, 'http');
        $this->assertDatabaseHas('domain_checks', ['success' => true, 'http_status_code' => 200]);
    }

    public function test_redirect_to_internal_address_counts_as_failure_and_is_never_requested(): void
    {
        $domain = $this->domain(['failure_threshold' => 1]);
        Http::swap(new Factory);
        Http::fake(['https://example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $domain = $this->check($domain, 'http');

        $this->assertSame('down', $domain->http_status);
        $this->assertDatabaseHas('domain_checks', ['error_code' => 'blocked']);
        Http::assertSentCount(1);
    }

    public function test_domain_resolving_to_private_ip_is_never_contacted(): void
    {
        $this->dns->host('example.com', '10.0.0.8');
        $domain = $this->domain(['failure_threshold' => 1]);
        Http::swap(new Factory);
        Http::fake();

        $this->check($domain, 'http');

        Http::assertNothingSent();
        $this->assertDatabaseHas('domain_checks', ['error_code' => 'blocked']);
    }

    public function test_maintenance_suppresses_incidents_but_keeps_history(): void
    {
        $domain = $this->domain(['failure_threshold' => 1, 'maintenance_starts_at' => now()->subMinute(), 'maintenance_ends_at' => now()->addHour()]);
        $this->httpDown();

        $domain = $this->check($domain, 'http');

        $this->assertSame('down', $domain->http_status);
        $this->assertSame('maintenance', $domain->displayStatus());
        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(1, $domain->checks()->count());

        // After maintenance a still-failing site opens an incident.
        $this->travel(2)->hours();
        $this->check($domain, 'http');
        $this->assertSame(1, Incident::query()->count());
    }

    // ---------------- SSL ----------------

    public function test_valid_certificate_is_recorded(): void
    {
        $domain = $this->check($this->domain(), 'ssl');

        $this->assertSame('valid', $domain->ssl_status);
        $this->assertSame("CN=R11, O=Let's Encrypt, C=US", $domain->ssl_issuer);
        $this->assertSame(90, $domain->sslDaysRemaining());
        $this->assertSame(['example.com', '93.184.215.14', 443], $this->tls->calls[0]);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_expiring_certificate_warns_and_escalates_per_threshold(): void
    {
        Event::fake([IncidentOpened::class, IncidentEscalated::class, IncidentResolved::class]);
        $domain = $this->domain(['ssl_warning_days' => [30, 14, 7]]);

        $this->tls->result = FakeTlsInspector::certificate('valid', 20);
        $domain = $this->check($domain, 'ssl');
        $this->assertSame('expiring', $domain->ssl_status);
        $this->assertSame(30, $domain->ssl_warning_threshold);
        $this->assertSame('warning', $domain->status);

        $incident = Incident::query()->sole();
        $this->assertSame('ssl_expiry', $incident->source);
        $this->assertSame('warning', $incident->severity);
        Event::assertDispatchedTimes(IncidentOpened::class, 1);

        // Same threshold: no new announcement.
        $this->tls->result = FakeTlsInspector::certificate('valid', 19);
        $this->check($domain, 'ssl');
        Event::assertNotDispatched(IncidentEscalated::class);

        // Crossing 14 days announces again on the same incident.
        $this->tls->result = FakeTlsInspector::certificate('valid', 12);
        $this->check($domain, 'ssl');
        Event::assertDispatchedTimes(IncidentEscalated::class, 1);
        $this->assertSame(1, Incident::query()->count());
        $this->assertStringContainsString('12 dagen', Incident::query()->sole()->title);

        // Renewal resolves it.
        $this->tls->result = FakeTlsInspector::certificate('valid', 89, 'cc:dd');
        $domain = $this->check($domain, 'ssl');
        $this->assertSame('valid', $domain->ssl_status);
        $this->assertNull($domain->ssl_warning_threshold);
        $this->assertSame('resolved', Incident::query()->sole()->status);
        $this->assertTrue($domain->checks()->where('type', 'ssl')->latest('id')->first()->details['certificate_changed']);
    }

    public function test_invalid_certificates_open_a_critical_incident(): void
    {
        foreach (['expired', 'hostname_mismatch', 'self_signed', 'untrusted'] as $i => $status) {
            $domain = Domain::create(['name' => "site{$i}.example.com"]);
            $this->dns->host("site{$i}.example.com", '93.184.215.14');
            $this->tls->result = FakeTlsInspector::certificate($status, $status === 'expired' ? -2 : 40, message: 'probleem');

            $domain = $this->check($domain, 'ssl');

            $this->assertSame($status, $domain->ssl_status);
            $this->assertSame('critical', $domain->status);
            $this->assertDatabaseHas('incidents', ['fingerprint' => "domain:{$domain->id}:ssl", 'severity' => 'critical', 'status' => 'open']);
        }
    }

    public function test_tls_connection_failure_does_not_open_a_certificate_incident(): void
    {
        $this->tls->result = new TlsResult('connection_failed', 'refused');

        $domain = $this->check($this->domain(), 'ssl');

        $this->assertSame('connection_failed', $domain->ssl_status);
        $this->assertSame(0, Incident::query()->count());
    }

    // ---------------- DNS ----------------

    private function dnsRecords(array $overrides = []): void
    {
        $records = [
            'A' => ['93.184.215.14'], 'AAAA' => [], 'CNAME' => [], 'MX' => ['10 mail.example.com'],
            'TXT' => ['v=spf1 -all'], 'NS' => ['ns1.example.net', 'ns2.example.net'], 'CAA' => ['0 issue "letsencrypt.org"'],
            ...$overrides,
        ];

        foreach ($records as $type => $values) {
            $this->dns->set('example.com', $type, $values);
        }
    }

    public function test_first_dns_check_is_a_baseline_and_changes_are_recorded(): void
    {
        Event::fake([DnsRecordsChanged::class]);
        $this->dnsRecords();
        $domain = $this->check($this->domain(), 'dns');

        $this->assertSame('ok', $domain->dns_status);
        $this->assertSame(7, $domain->dnsSnapshots()->count());
        $this->assertSame(0, $domain->dnsChanges()->count());
        Event::assertNotDispatched(DnsRecordsChanged::class);

        // Unchanged: no new snapshots.
        $this->check($domain, 'dns');
        $this->assertSame(7, $domain->dnsSnapshots()->count());

        $this->dnsRecords(['A' => ['93.184.215.99'], 'NS' => ['ns1.other.net', 'ns2.other.net'], 'TXT' => ['v=spf1 include:mail -all']]);
        $domain = $this->check($domain, 'dns');

        $changes = $domain->dnsChanges()->orderBy('record_type')->get()->keyBy('record_type');
        $this->assertSame(['A', 'NS', 'TXT'], $changes->keys()->all());
        $this->assertSame(['93.184.215.99'], $changes['A']->added);
        $this->assertSame(['93.184.215.14'], $changes['A']->removed);
        $this->assertNotNull($domain->dns_last_changed_at);

        // TXT is stored but not in the default alert types.
        Event::assertDispatched(DnsRecordsChanged::class, fn ($e) => collect($e->changes)->pluck('record_type')->sort()->values()->all() === ['A', 'NS']);
    }

    public function test_failed_record_queries_do_not_look_like_removed_records(): void
    {
        $this->dnsRecords();
        $domain = $this->check($this->domain(), 'dns');

        $this->dnsRecords(['MX' => FakeResolver::timeout()]);
        $domain = $this->check($domain, 'dns');

        $this->assertSame('ok', $domain->dns_status);
        $this->assertSame(0, $domain->dnsChanges()->count());
        $this->assertSame(['10 mail.example.com'], $domain->dnsSnapshots()->where('record_type', 'MX')->latest('id')->first()->records);
    }

    public function test_resolution_failure_threshold_and_recovery(): void
    {
        $domain = $this->domain(['failure_threshold' => 2]);
        $this->dnsRecords(['A' => 3]);

        $domain = $this->check($domain, 'dns');
        $this->assertSame(0, Incident::query()->count());
        $domain = $this->check($domain, 'dns');

        $this->assertSame('failed', $domain->dns_status);
        $this->assertSame('critical', $domain->status);
        $this->assertStringContainsString('NXDOMAIN', $domain->dns_message);
        $this->assertDatabaseHas('incidents', ['source' => 'dns', 'status' => 'open']);

        $this->dnsRecords();
        $domain = $this->check($domain, 'dns');
        $this->assertSame('ok', $domain->dns_status);
        $this->assertDatabaseHas('incidents', ['source' => 'dns', 'status' => 'resolved']);
    }

    // ---------------- RDAP ----------------

    private function rdap(array $responses): void
    {
        $this->dns->host('data.iana.org', '192.0.46.10')->set('data.iana.org', 'A', ['93.184.215.10']);
        $this->dns->host('rdap.verisign.com', '93.184.215.11');
        $this->dns->host('rdap.example-registry.net', '93.184.215.12');

        Http::swap(new Factory);
        Http::fake([
            'https://data.iana.org/rdap/dns.json' => Http::response(json_encode(['services' => [
                [['com', 'net'], ['https://rdap.verisign.com/com/v1/']],
                [['co.uk', 'uk'], ['https://rdap.example-registry.net/uk/']],
            ]])),
            ...$responses,
        ]);
    }

    private function rdapDomain(string $name, ?string $expires, string $registrar = 'Example Registrar, Inc.'): string
    {
        $events = [['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z']];

        if ($expires) {
            $events[] = ['eventAction' => 'expiration', 'eventDate' => $expires];
        }

        return json_encode([
            'objectClassName' => 'domain',
            'ldhName' => strtoupper($name),
            'status' => ['client transfer prohibited'],
            'events' => $events,
            'entities' => [['objectClassName' => 'entity', 'roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', $registrar]]]]],
        ]);
    }

    public function test_verified_expiry_date_from_rdap(): void
    {
        $expires = now()->addDays(200)->toIso8601String();
        $this->rdap(['https://rdap.verisign.com/com/v1/domain/example.com' => Http::response($this->rdapDomain('example.com', $expires), 200, ['Content-Type' => 'application/rdap+json'])]);

        $domain = $this->check($this->domain(), 'expiry');

        $this->assertSame('verified', $domain->expiry_status);
        $this->assertSame(now()->addDays(200)->toDateString(), $domain->registration_expires_at->toDateString());
        $this->assertSame('Example Registrar, Inc.', $domain->registrar);
        $this->assertSame('https://rdap.verisign.com/com/v1/domain/example.com', $domain->expiry_source);
        $this->assertNotNull($domain->expiry_checked_at);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_subdomain_falls_back_to_registrable_domain_and_missing_date_is_not_invented(): void
    {
        $this->dns->host('shop.example.co.uk', '93.184.215.14');
        $this->rdap([
            'https://rdap.example-registry.net/uk/domain/shop.example.co.uk' => Http::response('', 404),
            'https://rdap.example-registry.net/uk/domain/example.co.uk' => Http::response($this->rdapDomain('example.co.uk', null)),
        ]);

        $domain = $this->check(Domain::create(['name' => 'shop.example.co.uk']), 'expiry');

        $this->assertSame('unavailable', $domain->expiry_status);
        $this->assertNull($domain->registration_expires_at);
        $this->assertSame('example.co.uk', $domain->registrable_domain);
        $this->assertStringContainsString('geen vervaldatum', $domain->expiry_message);
    }

    public function test_unsupported_tld_and_rate_limiting(): void
    {
        $this->dns->host('example.zz', '93.184.215.14');
        $this->rdap([]);
        $domain = $this->check(Domain::create(['name' => 'example.zz', 'expiry_enabled' => true]), 'expiry');
        $this->assertSame('unsupported', $domain->expiry_status);

        $expires = now()->addDays(200)->toIso8601String();
        $this->rdap(['https://rdap.verisign.com/com/v1/domain/example.com' => Http::response($this->rdapDomain('example.com', $expires))]);
        $com = $this->check($this->domain(), 'expiry');
        $this->assertSame('verified', $com->expiry_status);

        $this->rdap(['https://rdap.verisign.com/com/v1/domain/example.com' => Http::response('', 429, ['Retry-After' => '120'])]);
        $com = $this->check($com, 'expiry');

        // Previous verified data is kept; next attempt follows Retry-After.
        $this->assertSame('verified', $com->expiry_status);
        $this->assertNotNull($com->registration_expires_at);
        $this->assertEqualsWithDelta(120, now()->diffInSeconds($com->expiry_next_check_at), 5);
        $this->assertDatabaseHas('domain_checks', ['type' => 'expiry', 'result' => 'rate_limited']);
    }

    public function test_approaching_and_past_expiry_open_incidents(): void
    {
        $this->rdap(['https://rdap.verisign.com/com/v1/domain/example.com' => Http::response($this->rdapDomain('example.com', now()->addDays(10)->toIso8601String()))]);
        $domain = $this->check($this->domain(), 'expiry');

        $this->assertSame(14, $domain->expiry_warning_threshold);
        $this->assertSame('warning', $domain->status);
        $this->assertDatabaseHas('incidents', ['source' => 'expiry', 'severity' => 'warning', 'status' => 'open']);

        $this->rdap(['https://rdap.verisign.com/com/v1/domain/example.com' => Http::response($this->rdapDomain('example.com', now()->subDay()->toIso8601String()))]);
        $domain = $this->check($domain, 'expiry');

        $this->assertSame('critical', $domain->status);
        $incident = Incident::query()->sole();
        $this->assertSame('critical', $incident->severity);
        $this->assertStringContainsString('verlopen', $incident->title);
    }

    // ---------------- Scheduling & reporting ----------------

    public function test_scheduler_dispatches_due_checks_once(): void
    {
        Queue::fake();
        $due = $this->domain();
        Domain::create(['name' => 'paused.example.com', 'is_active' => false]);
        Domain::create(['name' => 'plain.example.com', 'scheme' => 'http', 'ssl_enabled' => true]);

        $this->artisan('serverscope:dispatch-domain-checks')->assertSuccessful();
        $this->artisan('serverscope:dispatch-domain-checks')->assertSuccessful();

        Queue::assertPushed(RunDomainCheck::class, 7); // example.com x4, plain x3 (no ssl)
        Queue::assertNotPushed(RunDomainCheck::class, fn ($job) => $job->domainId !== $due->id && $job->type === 'ssl');
        $this->assertNotNull($due->fresh()->http_next_check_at);

        $this->travel(6)->minutes();
        Queue::fake();
        $this->artisan('serverscope:dispatch-domain-checks');
        Queue::assertPushed(RunDomainCheck::class, fn ($job) => $job->type === 'http');
        Queue::assertNotPushed(RunDomainCheck::class, fn ($job) => $job->type === 'ssl');
    }

    public function test_job_skips_paused_domains(): void
    {
        $domain = $this->domain(['is_active' => false]);
        Http::swap(new Factory);
        Http::fake();

        (new RunDomainCheck($domain->id, 'http'))->handle(app(DomainCheckRunner::class));

        Http::assertNothingSent();
        $this->assertSame(0, $domain->checks()->count());
    }

    public function test_uptime_ignores_missing_measurements(): void
    {
        $domain = $this->domain(['check_interval_minutes' => 60]);

        // 24 hours, but only 12 checks: 11 up, 1 down.
        foreach (range(1, 12) as $i) {
            $domain->checks()->create(['type' => 'http', 'result' => $i === 5 ? 'down' : 'up', 'success' => $i !== 5, 'response_time_ms' => 100 * $i, 'checked_at' => now()->subHours($i)]);
        }

        $stats = app(UptimeCalculator::class)->domain($domain, now()->subDay(), now());

        $this->assertSame(12, $stats['checks']);
        $this->assertEqualsWithDelta(91.667, $stats['uptime'], 0.001);
        $this->assertSame(50.0, $stats['coverage']);
        $this->assertSame(60, $stats['downtime_minutes']);

        $empty = app(UptimeCalculator::class)->domain($domain, now()->subDays(30), now()->subDays(29));
        $this->assertNull($empty['uptime']);
    }

    public function test_detail_page_renders_all_sections(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain();
        $this->dnsRecords();
        $this->http();
        $this->check($domain, 'http');
        $this->check($domain, 'dns');
        $this->check($domain, 'ssl');

        $this->actingAs($user)->get(route('domains.show', $domain))
            ->assertOk()
            ->assertSee(['HTTP-beschikbaarheid', 'SSL/TLS-certificaat', 'Domeinregistratie', 'DNS-records', 'Controlegeschiedenis'])
            ->assertSee('10 mail.example.com')
            ->assertSee('Onbekend — er wordt geen datum geschat');
    }
}
