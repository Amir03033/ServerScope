<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Models\Prediction;
use App\Models\User;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsAndOperationsUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->domain = Domain::query()->create(['name' => 'example.com']);
        $this->domain->forceFill(['created_at' => now()->subDays(40)])->save();
    }

    private function page(string $path, array $attributes = []): MonitoredPage
    {
        $url = 'https://example.com'.$path;
        $page = MonitoredPage::query()->create(['domain_id' => $this->domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), ...$attributes]);
        $page->forceFill(['created_at' => now()->subDays(40)])->save();

        return $page;
    }

    private function checks(MonitoredPage $page, int $ok, int $failed): void
    {
        $rows = [];

        for ($i = 0; $i < $ok + $failed; $i++) {
            $rows[] = ['monitored_page_id' => $page->id, 'domain_id' => $page->domain_id, 'result' => $i < $failed ? 'down' : 'up', 'success' => $i >= $failed, 'response_time_ms' => 100 + $i, 'checked_at' => now()->subMinutes(5 * ($i + 1))];
        }

        DB::table('page_checks')->insert($rows);
    }

    public function test_report_includes_pages_targets_mttd_coverage_and_recurrence(): void
    {
        $good = $this->page('/good', ['sla_target' => 99.0]);
        $bad = $this->page('/bad');
        $this->checks($good, 99, 1);
        $this->checks($bad, 90, 10);
        $this->page('/waiting', ['approval_status' => 'pending']);
        $this->domain->forceFill(['sla_target' => 95.0])->save();

        $manager = app(IncidentManager::class);

        foreach (range(1, 3) as $i) {
            $incident = $manager->trigger(new Signal('page:'.$bad->id, 'page', 'high', 'Pagina /bad faalt', null, $bad, [], null, now()->subMinutes(10)));
            $manager->recover('page:'.$bad->id, 'ok');
            $this->travel(2)->hours();
        }

        $response = $this->actingAs($this->admin)->get(route('reports.index', ['period' => '30d', 'target' => 99.9]));

        $response->assertOk()
            ->assertSee('Websitepagina')
            ->assertSee('https://example.com/good')
            ->assertSee('Monitoringdekking')
            ->assertSee('1 pagina\'s wachten op goedkeuring', false)
            ->assertSee('Terugkerende problemen')
            ->assertSee('Pagina /bad faalt')
            ->assertSee('MTTD');

        $report = $response->viewData('report');
        $rows = $report['pages']->keyBy(fn ($r) => $r['page']->url);
        $this->assertSame(99.0, $rows['https://example.com/good']['uptime']);
        $this->assertSame(99.0, $rows['https://example.com/good']['target'], 'the page target wins');
        $this->assertTrue($rows['https://example.com/good']['meets_target']);
        $this->assertSame(95.0, $rows['https://example.com/bad']['target'], 'then the website target');
        $this->assertFalse($rows['https://example.com/bad']['meets_target']);
        $this->assertSame(50, $rows['https://example.com/bad']['downtime_minutes']);
        $this->assertNotNull($rows['https://example.com/bad']['p95_ms']);
        $this->assertSame(600, $report['incidents']['mttd_seconds']);
        $this->assertSame(3, $report['recurring']->first()['occurrences']);
        $this->assertSame(1, $report['coverage']['pages_pending']);
    }

    public function test_csv_exports_include_pages_and_incidents_safely(): void
    {
        $page = $this->page('/=cmd');
        $this->checks($page, 10, 0);
        app(IncidentManager::class)->trigger(new Signal('x:1', 'manual', 'high', '=HYPERLINK("evil")', null, $this->domain));

        $csv = $this->actingAs($this->admin)->get(route('reports.export', ['period' => '7d']))->assertOk()->streamedContent();
        $this->assertStringContainsString('pagina,https://example.com/=cmd,100', $csv);
        $this->assertStringContainsString('doel_pct', $csv);

        $incidents = $this->actingAs($this->admin)->get(route('reports.export', ['period' => '7d', 'kind' => 'incidents']))->assertOk()->streamedContent();
        $this->assertStringContainsString('detectie_s', $incidents);
        $this->assertStringContainsString("'=HYPERLINK", $incidents, 'formula injection is neutralised');
        $this->assertStringContainsString(',high,manual,', $incidents);
    }

    public function test_printable_report(): void
    {
        $this->actingAs($this->admin)->get(route('reports.print', ['period' => '7d']))
            ->assertOk()->assertSee('Beschikbaarheidsrapport')->assertSee('Afdrukken / opslaan als PDF')->assertSee('geen contractuele SLA');
        $this->get(route('reports.print'))->assertOk();
        auth()->logout();
        $this->get(route('reports.print'))->assertRedirect(route('login'));
    }

    public function test_retention_covers_new_history_tables_and_keeps_active_findings(): void
    {
        config([
            'serverscope.retention.page_checks_days' => 30,
            'serverscope.retention.predictions_days' => 30,
        ]);
        $page = $this->page('/');
        DB::table('page_checks')->insert([
            ['monitored_page_id' => $page->id, 'domain_id' => $this->domain->id, 'result' => 'up', 'success' => true, 'checked_at' => now()->subDays(40)],
            ['monitored_page_id' => $page->id, 'domain_id' => $this->domain->id, 'result' => 'up', 'success' => true, 'checked_at' => now()->subDays(5)],
        ]);
        $base = ['resource_type' => 'domain', 'resource_id' => $this->domain->id, 'kind' => 'anomaly', 'metric' => 'error_rate', 'reason' => 'x', 'detected_at' => now()->subDays(60), 'last_evaluated_at' => now()];
        Prediction::query()->create([...$base, 'status' => 'resolved', 'resolved_at' => now()->subDays(45)]);
        Prediction::query()->create([...$base, 'status' => 'active', 'active_key' => 'k']);

        $this->artisan('serverscope:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('page_checks')->count());
        $this->assertSame(['active'], Prediction::query()->pluck('status')->all());
    }

    public function test_system_health_reports_scheduler_queue_and_overdue_checks(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->get(route('system-health'))->assertForbidden();

        $report = app(SystemHealth::class)->report();
        $this->assertSame('critical', $report['status'], 'no scheduler heartbeat yet');

        SystemHealth::beat();
        $this->page('/late')->forceFill(['next_check_at' => now()->subHour()])->save();
        $report = app(SystemHealth::class)->report();
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertSame('healthy', $checks['scheduler']['status']);
        $this->assertSame('warning', $checks['overdue']['status']);
        $this->assertStringContainsString('1 paginacontroles', $checks['overdue']['detail']);

        $this->travel(10)->minutes();
        $this->assertSame('critical', collect(app(SystemHealth::class)->report()['checks'])->firstWhere('key', 'scheduler')['status']);

        Cache::flush();
        $this->actingAs($this->admin)->get(route('system-health'))->assertOk()->assertSee('Scheduler')->assertSee('Controles op tijd');
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Monitoring verstoord');
    }
}
