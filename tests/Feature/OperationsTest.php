<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_is_disabled_by_default(): void
    {
        $server = Server::create(['name' => 'a', 'hostname' => 'a.example.test']);
        MetricSample::create(['server_id' => $server->id, 'collected_at' => now()->subYears(3)]);

        $this->artisan('serverscope:prune')->assertSuccessful();

        $this->assertSame(1, MetricSample::query()->count());
    }

    public function test_prune_deletes_only_expired_rows_and_supports_dry_run(): void
    {
        config(['serverscope.retention.metric_samples_days' => 30, 'serverscope.retention.domain_checks_days' => 90]);

        $server = Server::create(['name' => 'a', 'hostname' => 'a.example.test']);
        foreach ([10, 40, 400] as $days) {
            MetricSample::create(['server_id' => $server->id, 'collected_at' => now()->subDays($days)]);
        }
        $domain = Domain::create(['name' => 'example.com']);
        $domain->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'checked_at' => now()->subDays(60)]);
        $domain->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'checked_at' => now()->subDays(100)]);

        $this->artisan('serverscope:prune --dry-run')->expectsOutputToContain('Totaal te verwijderen: 3')->assertSuccessful();
        $this->assertSame(3, MetricSample::query()->count());

        $this->artisan('serverscope:prune')->assertSuccessful();
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, $domain->checks()->count());
    }

    public function test_audit_log_page_is_admin_only_and_filterable(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Beheerder']);
        $viewer = User::factory()->create();

        $this->actingAs($admin)->post(route('domains.store'), [
            'name' => 'example.com', 'scheme' => 'https', 'path' => '/', 'check_interval_minutes' => 5, 'timeout_seconds' => 10,
            'failure_threshold' => 3, 'recovery_threshold' => 2, 'ssl_warning_days' => '30', 'expiry_warning_days' => '30',
        ]);
        \App\Support\Audit::log('server.created', 'Server X geregistreerd');

        $this->actingAs($viewer)->get(route('audit-logs.index'))->assertForbidden();

        $this->actingAs($admin)->get(route('audit-logs.index'))->assertOk()->assertSee('Domein example.com toegevoegd')->assertSee('Server X geregistreerd');

        $logs = $this->actingAs($admin)->get(route('audit-logs.index', ['action' => 'domain']))->viewData('logs');
        $this->assertSame(['domain.created'], $logs->pluck('action')->all());

        $this->actingAs($admin)->get(route('audit-logs.index', ['action' => "x' OR 1=1"]))->assertSessionHasErrors('action');
    }

    public function test_audit_redacts_secrets(): void
    {
        $log = \App\Support\Audit::log('test', 'Test', null, ['token' => 'abc', 'nested' => ['password' => 'p', 'ok' => 1]]);

        $this->assertSame('[redacted]', $log->properties['token']);
        $this->assertSame('[redacted]', $log->properties['nested']['password']);
        $this->assertSame(1, $log->properties['nested']['ok']);
    }

    public function test_scheduler_registers_all_monitoring_tasks(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

        foreach (['serverscope:check-agents', 'serverscope:dispatch-domain-checks', 'serverscope:escalate-incidents', 'serverscope:prune', 'queue:work'] as $command) {
            $this->assertStringContainsString($command, $commands);
        }
    }
}
