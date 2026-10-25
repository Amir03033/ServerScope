<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Services\Reports\AvailabilityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_and_server_availability(): void
    {
        // Whole seconds: timestamps are stored without microseconds.
        $this->freezeSecond();
        $from = now()->subDay();

        $domain = Domain::create(['name' => 'example.com', 'check_interval_minutes' => 60]);
        $domain->forceFill(['created_at' => now()->subDays(10)])->save();

        foreach (range(1, 20) as $i) {
            $domain->checks()->create(['type' => 'http', 'result' => $i <= 2 ? 'down' : 'up', 'success' => $i > 2, 'response_time_ms' => 100, 'checked_at' => now()->subHours($i)]);
        }

        $server = Server::create(['name' => 'Web 1', 'hostname' => 'web1.example.test']);
        $server->forceFill(['created_at' => now()->subDays(10)])->save();
        // 30 minutes of agent-offline incident inside the period, 10 before it.
        Incident::query()->create(['fingerprint' => 'a', 'subject_type' => 'server', 'subject_id' => $server->id, 'source' => 'agent', 'severity' => 'critical', 'status' => 'resolved', 'title' => 'x', 'opened_at' => $from->copy()->subMinutes(10), 'last_occurrence_at' => $from, 'resolved_at' => $from->copy()->addMinutes(30)]);
        foreach (range(1, 720) as $i) {
            MetricSample::query()->insert(['server_id' => $server->id, 'collected_at' => now()->subMinutes($i * 2), 'created_at' => now(), 'updated_at' => now()]);
        }

        $report = app(AvailabilityReport::class)->build($from, now(), 99.9);

        $row = $report['domains']->first();
        $this->assertSame(20, $row['checks']);
        $this->assertSame(90.0, $row['uptime']);
        $this->assertEqualsWithDelta(83.3, $row['coverage'], 0.1);
        $this->assertSame(120, $row['downtime_minutes']);
        $this->assertFalse($row['meets_target']);

        $srv = $report['servers']->first();
        $this->assertSame(30.0, $srv['downtime_minutes']);
        $this->assertEqualsWithDelta((1 - 1800 / 86400) * 100, $srv['uptime'], 0.001);
        $this->assertSame(50.0, $srv['coverage']);
        $this->assertFalse($srv['meets_target']);
    }

    public function test_resources_without_data_report_no_uptime(): void
    {
        $domain = Domain::create(['name' => 'new.example.com']);
        $server = Server::create(['name' => 'Silent', 'hostname' => 'silent.example.test']);

        $report = app(AvailabilityReport::class)->build(now()->subDay(), now(), 99.9);

        $this->assertNull($report['domains']->first()['uptime']);
        $this->assertNull($report['domains']->first()['meets_target']);
        $this->assertNull($report['servers']->first()['uptime']);
        $this->assertNull($report['average_uptime']);
    }

    public function test_report_page_periods_and_csv_export(): void
    {
        $user = User::factory()->create();
        $domain = Domain::create(['name' => 'example.com']);
        Domain::create(['name' => 'xn--bcher-kva.de']);
        $domain->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => 50, 'checked_at' => now()->subHour()]);
        Server::create(['name' => '=HYPERLINK("evil")', 'hostname' => 'x.example.test']);

        foreach (['7d', '30d', '90d', 'month'] as $period) {
            $this->actingAs($user)->get(route('reports.index', ['period' => $period]))->assertOk()->assertSee('example.com');
        }

        $this->actingAs($user)->get(route('reports.index', ['period' => 'custom', 'from' => '2026-01-01T00:00', 'to' => '2026-01-31T00:00']))->assertOk();
        $this->actingAs($user)->get(route('reports.index', ['period' => 'custom', 'from' => '2026-02-01T00:00', 'to' => '2026-01-01T00:00']))->assertSessionHasErrors('to');
        $this->actingAs($user)->get(route('reports.index', ['target' => 50]))->assertSessionHasErrors('target');

        $csv = $this->actingAs($user)->get(route('reports.export', ['period' => '7d']))->assertOk()->streamedContent();
        $this->assertStringContainsString('domein,example.com,100,', $csv);
        $this->assertStringContainsString('bücher.de', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'formula injection is neutralised');
    }
}
