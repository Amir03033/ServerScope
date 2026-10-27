<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\SslCertificate;
use App\Models\User;
use App\Monitoring\Dns\Resolver;
use App\Monitoring\Net\TlsInspector;
use App\Monitoring\Net\TlsResult;
use App\Services\Domains\DomainCheckRunner;
use App\Services\ServerDiagnosis;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeResolver;
use Tests\Support\FakeTlsInspector;
use Tests\TestCase;

class ServerHealthTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        AlertRule::query()->forceDelete();
        $this->server = Server::query()->create(['name' => 'web-01', 'hostname' => 'web-01.example.test']);
        $this->token = bin2hex(random_bytes(32));
        $this->server->agentCredentials()->create(['name' => 'agent', 'token_hash' => hash('sha256', $this->token)]);
    }

    private function submit(array $payload = [], int $secondsAgo = 0)
    {
        return $this->withToken($this->token)->postJson('/api/agent/metrics', [
            'collected_at' => now()->subSeconds($secondsAgo)->toIso8601String(),
            'cpu_percent' => 10,
            'load_1' => 0.2,
            'memory_total_bytes' => 8_000,
            'memory_used_bytes' => 4_000,
            'network_rx_bytes' => 100,
            'network_tx_bytes' => 100,
            'disks' => [['path' => '/', 'total_bytes' => 100, 'used_bytes' => 50, 'available_bytes' => 50, 'percent' => 50]],
            ...$payload,
        ]);
    }

    private function services(array $states): void
    {
        $this->submit(['services' => collect($states)->map(fn ($state, $name) => ['name' => $name, 'state' => $state])->values()->all()])->assertCreated();
        $this->travel(1)->minutes();
    }

    public function test_extended_metrics_and_checksum_are_stored(): void
    {
        $checksum = hash('sha256', 'agent');

        $this->submit([
            'disk_read_bytes' => 1000, 'disk_write_bytes' => 2000, 'network_errors_in' => 3, 'network_errors_out' => 4,
            'network_drops_in' => 5, 'network_drops_out' => 6, 'process_count' => 120, 'agent_version' => '0.2.0', 'agent_checksum' => $checksum,
        ])->assertCreated();

        $sample = MetricSample::query()->sole();
        $this->assertSame(1000, (int) $sample->disk_read_bytes);
        $this->assertSame(120, (int) $sample->process_count);
        $this->assertSame($checksum, $this->server->fresh()->agent_checksum);

        $this->submit(['agent_checksum' => 'not-a-checksum'], 5)->assertUnprocessable();
        $this->submit(['services' => [['name' => 'nginx; rm -rf /', 'state' => 'running']]], 6)->assertUnprocessable();
        $this->submit(['services' => [['name' => 'nginx', 'state' => 'exploded']]], 7)->assertUnprocessable();
    }

    public function test_service_failures_open_and_resolve_incidents_after_the_threshold(): void
    {
        $this->services(['nginx' => 'running', 'mysql' => 'running']);
        $nginx = ServiceCheck::query()->where('name', 'nginx')->sole();
        $this->assertSame('nginx', $nginx->kind);
        $this->assertSame('mysql', ServiceCheck::query()->where('name', 'mysql')->value('kind'));

        $this->services(['nginx' => 'failed', 'mysql' => 'running']);
        $this->assertSame('failed', $nginx->fresh()->status);
        $this->assertSame(0, Incident::query()->count(), 'below the threshold of 2');

        $this->services(['nginx' => 'failed', 'mysql' => 'running']);
        $incident = Incident::query()->sole();
        $this->assertSame('service', $incident->source);
        $this->assertSame('high', $incident->severity);
        $this->assertTrue($incident->subject->is($nginx));
        $this->assertStringContainsString('geen onbereikbare host', $incident->description);

        $this->services(['nginx' => 'running', 'mysql' => 'running']);
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertSame(['running', 'failed', 'running'], $nginx->events()->orderBy('id')->pluck('status')->all());
    }

    public function test_services_missing_from_a_report_become_stale_not_failed(): void
    {
        $this->services(['nginx' => 'running', 'redis' => 'running']);
        $this->services(['nginx' => 'running']);

        $this->assertSame('stale', ServiceCheck::query()->where('name', 'redis')->value('status'));
        $this->assertSame(0, Incident::query()->count());

        // Agents without service configuration do not touch services.
        $this->submit()->assertCreated();
        $this->assertSame('running', ServiceCheck::query()->where('name', 'nginx')->value('status'));
    }

    public function test_disabled_services_and_maintenance_do_not_alert(): void
    {
        $this->services(['nginx' => 'running']);
        $nginx = ServiceCheck::query()->sole();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('services.update', $nginx), ['is_enabled' => 0, 'alerts_enabled' => 1, 'severity' => 'high', 'failure_threshold' => 1])->assertSessionHas('success');
        $this->services(['nginx' => 'failed']);
        $this->services(['nginx' => 'failed']);
        $this->assertSame(0, Incident::query()->count());

        $nginx->forceFill(['is_enabled' => true])->save();
        $this->server->forceFill(['maintenance_starts_at' => now()->subMinute()])->save();
        $this->services(['nginx' => 'failed']);
        $this->services(['nginx' => 'failed']);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_service_management_is_admin_only(): void
    {
        $this->services(['nginx' => 'running']);
        $nginx = ServiceCheck::query()->sole();
        $viewer = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($viewer)->put(route('services.update', $nginx), ['is_enabled' => 0, 'alerts_enabled' => 0, 'severity' => 'high', 'failure_threshold' => 2])->assertForbidden();
        $this->actingAs($viewer)->delete(route('services.destroy', $nginx))->assertForbidden();
        $this->actingAs($admin)->put(route('services.update', $nginx), ['is_enabled' => 1, 'alerts_enabled' => 1, 'severity' => 'bogus', 'failure_threshold' => 2])->assertSessionHasErrors('severity');
        $this->actingAs($admin)->delete(route('services.destroy', $nginx))->assertSessionHas('success');
        $this->assertDatabaseCount('service_checks', 0);
    }

    public function test_diagnosis_distinguishes_agent_host_and_service_failures(): void
    {
        $diagnosis = app(ServerDiagnosis::class);
        $this->assertSame('pending', $diagnosis->diagnose($this->server)['state']);

        $this->services(['nginx' => 'running']);
        $this->assertSame('healthy', $diagnosis->diagnose($this->server->fresh())['state']);

        $this->services(['nginx' => 'failed']);
        $this->assertSame('service_failed', $diagnosis->diagnose($this->server->fresh())['state']);

        // Agent silent, nothing else known: cause unknown, not "host down".
        $this->travel(10)->minutes();
        $this->assertSame('agent_offline_unknown', $diagnosis->diagnose($this->server->fresh())['state']);

        $domain = Domain::query()->create(['name' => 'example.com']);
        ResourceDependency::query()->create(['dependent_type' => 'domain', 'dependent_id' => $domain->id, 'dependency_type' => 'server', 'dependency_id' => $this->server->id]);

        $domain->forceFill(['http_status' => 'up', 'http_last_status_code' => 200, 'http_last_checked_at' => now()])->save();
        $result = $diagnosis->diagnose($this->server->fresh());
        $this->assertSame('agent_unhealthy', $result['state']);
        $this->assertStringContainsString('example.com reageert wel', $result['evidence'][0]);

        $domain->forceFill(['http_status' => 'down', 'http_consecutive_failures' => 3, 'http_last_error' => 'Verbinding mislukt'])->save();
        $this->assertSame('host_unreachable', $diagnosis->diagnose($this->server->fresh())['state']);

        // Old website results are not evidence.
        $domain->forceFill(['http_last_checked_at' => now()->subHour()])->save();
        $this->assertSame('agent_offline_unknown', $diagnosis->diagnose($this->server->fresh())['state']);
    }

    public function test_data_quality_flags_missing_metrics_outdated_agents_and_unknown_builds(): void
    {
        config(['serverscope.agent.latest_version' => '0.2.0', 'serverscope.agent.known_checksums' => [hash('sha256', 'release')]]);

        foreach (range(1, 7) as $i) {
            $this->submit(['agent_version' => '0.1.0', 'agent_checksum' => hash('sha256', 'modified'), 'memory_total_bytes' => null, 'memory_used_bytes' => null])->assertCreated();
            $this->travel(10)->minutes();
        }

        $this->travelBack();
        $this->travel(60)->minutes();

        $quality = app(ServerDiagnosis::class)->dataQuality($this->server->fresh());

        $this->assertTrue($quality['outdated']);
        $this->assertSame('unknown_build', $quality['integrity']);
        $this->assertSame(600, $quality['interval_seconds']);
        $this->assertContains('geheugen', $quality['missing_fields']);
        $this->assertSame('agent_degraded', app(ServerDiagnosis::class)->diagnose($this->server->fresh())['state']);

        $this->server->forceFill(['agent_checksum' => hash('sha256', 'release')])->save();
        $this->assertSame('verified', app(ServerDiagnosis::class)->dataQuality($this->server->fresh())['integrity']);
    }

    public function test_server_page_shows_diagnosis_services_and_history(): void
    {
        $this->services(['nginx' => 'running', 'mysql' => 'failed']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertSee('Diagnose')
            ->assertSee('nginx')
            ->assertSee('Mislukt')
            ->assertSee('Uitgebreide historie');

        $this->actingAs($admin)->get(route('servers.edit', $this->server))->assertOk()->assertSee('Gevoeligheid afwijkingsdetectie');
    }

    public function test_certificate_history_records_each_distinct_certificate(): void
    {
        $dns = new FakeResolver;
        $dns->host('example.com', '93.184.215.14');
        $this->app->instance(Resolver::class, $dns);
        $tls = new FakeTlsInspector;
        $this->app->instance(TlsInspector::class, $tls);
        $domain = Domain::query()->create(['name' => 'example.com']);
        $now = CarbonImmutable::now();

        $certificate = fn (string $fingerprint) => new TlsResult('valid', 'Geldig', 'CN=example.com', "CN=Issuer {$fingerprint}", ['example.com'], $now->subDays(10), $now->addDays(80), $fingerprint, '01');

        foreach (['AA:BB', 'AA:BB', 'CC:DD'] as $fingerprint) {
            $tls->result = $certificate($fingerprint);
            app(DomainCheckRunner::class)->run($domain->fresh(), 'ssl');
            $this->travel(1)->hours();
        }

        $this->assertSame(['AA:BB', 'CC:DD'], SslCertificate::query()->orderBy('id')->pluck('fingerprint')->all());
        $first = SslCertificate::query()->where('fingerprint', 'AA:BB')->sole();
        $this->assertTrue($first->last_seen_at->gt($first->first_seen_at));

        $this->actingAs(User::factory()->create())->get(route('domains.show', $domain))->assertOk()->assertSee('Certificaatgeschiedenis')->assertSee('Issuer CC:DD');
    }
}
