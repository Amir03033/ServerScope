<?php

namespace Tests\Feature;

use App\Models\AgentRelease;
use App\Models\AgentUpdateRequest;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\Prediction;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Incidents\TimeMachine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * Freezes the complete output of TimeMachine::build() for a scenario that
 * touches every evidence source (incident events, other incidents,
 * services, domain and page checks, DNS, predictions, agent updates,
 * deployments, audit logs, metrics with a shift, gaps) in both languages.
 * The evidence collectors that Time Machine and NEXUS Intelligence share
 * must keep this output identical.
 *
 * Re-record (only when the output is meant to change):
 *   TIME_MACHINE_RECORD=1 php artisan test --filter=TimeMachineContractTest
 */
class TimeMachineContractTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private CarbonImmutable $onset;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow('2026-11-10 12:00:00');
        AlertRule::query()->forceDelete();
        $this->createTwoTeams();
        $this->onset = CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public static function scenarios(): array
    {
        return [
            'website en' => ['website', 'en'],
            'website nl' => ['website', 'nl'],
            'page en' => ['page', 'en'],
            'page nl' => ['page', 'nl'],
            'manual en' => ['manual', 'en'],
            'manual nl' => ['manual', 'nl'],
        ];
    }

    /** @dataProvider scenarios */
    #[DataProvider('scenarios')]
    public function test_build_output_is_unchanged(string $scenario, string $locale): void
    {
        $incident = $this->$scenario();
        app()->setLocale($locale);

        $actual = $this->normalize(app(TimeMachine::class)->build($incident));
        $file = base_path("tests/Fixtures/time_machine/{$scenario}.{$locale}.json");

        if (getenv('TIME_MACHINE_RECORD')) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertFileExists($file);
        // Row ids depend on the database (MySQL keeps auto-increment values
        // across rolled-back tests); compare with ids numbered by first use.
        $this->assertSame(self::stableIds((string) file_get_contents($file)), self::stableIds(json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    private function website(): Incident
    {
        $this->samples('2026-11-09 08:00:00', '2026-11-10 12:00:00', '2026-11-10 09:00:00');
        $server = $this->a['server'];
        $domain = $this->a['domain'];
        $service = $this->a['service'];

        $incident = app(IncidentManager::class)->trigger(new Signal('http:contract', 'http', 'high', 'alpha-shop.example.com onbereikbaar', null, $domain, startedAt: $this->onset->toMutable(), teamId: $this->a['team']->id));
        $incident->forceFill(['opened_at' => $this->onset->addMinutes(2), 'status' => 'resolved', 'resolved_at' => $this->onset->addMinutes(40)])->save();
        app(IncidentManager::class)->event($incident, 'comment', null, 'Gekeken naar de webserver');
        DB::table('incident_events')->where('incident_id', $incident->id)->update(['created_at' => $this->onset->addMinutes(3)]);

        // Another incident on the server (upstream), opened before the onset and resolved in the window.
        $other = app(IncidentManager::class)->trigger(new Signal('agent:contract', 'agent', 'critical', 'alpha-server offline', null, $server, teamId: $this->a['team']->id));
        $other->forceFill(['opened_at' => $this->onset->subMinutes(30), 'status' => 'resolved', 'resolved_at' => $this->onset->addMinutes(30)])->save();
        // One that was already open before the window.
        $ongoing = app(IncidentManager::class)->trigger(new Signal('alert:contract', 'alert', 'warning', 'alpha-server schijf vol', null, $server, teamId: $this->a['team']->id));
        $ongoing->forceFill(['opened_at' => $this->onset->subHours(8)])->save();

        DB::table('dns_changes')->insert(['domain_id' => $domain->id, 'record_type' => 'MX', 'previous' => '[]', 'current' => '[]', 'added' => '[]', 'removed' => '[]', 'detected_at' => $this->onset->subMinutes(90)]);
        DB::table('service_check_events')->insert([
            ['service_check_id' => $service->id, 'status' => 'stopped', 'previous_status' => 'running', 'detail' => 'inactive/dead', 'recorded_at' => $this->onset->subMinutes(20)],
            ['service_check_id' => $service->id, 'status' => 'running', 'previous_status' => 'stopped', 'detail' => 'active/running', 'recorded_at' => $this->onset->addMinutes(35)],
        ]);

        foreach ([[1, true], [5, false], [10, false], [41, false], [45, true]] as [$minute, $success]) {
            $domain->checks()->create(['type' => 'http', 'result' => $success ? 'up' : 'down', 'success' => $success, 'message' => $success ? null : 'Connection refused', 'checked_at' => $this->onset->addMinutes($minute)]);
        }

        $domain->checks()->create(['type' => 'ssl', 'result' => 'invalid', 'success' => false, 'error_code' => 'tls_error', 'checked_at' => $this->onset->addMinutes(6)]);

        Prediction::forceCreate(['team_id' => $this->a['team']->id, 'resource_type' => 'server', 'resource_id' => $server->id, 'kind' => 'anomaly', 'metric' => 'cpu_percent', 'status' => 'resolved', 'reason' => 'CPU ver boven normaal', 'detected_at' => $this->onset->subMinutes(45), 'last_evaluated_at' => $this->onset]);

        $release = AgentRelease::query()->withoutGlobalScopes()->forceCreate($this->releaseAttributes());
        AgentUpdateRequest::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->a['team']->id, 'server_id' => $server->id, 'agent_release_id' => $release->id, 'from_version' => '0.3.0', 'target_version' => '0.3.1', 'status' => 'succeeded', 'source' => 'manual', 'started_at' => $this->onset->subMinutes(70), 'finished_at' => $this->onset->subMinutes(65)]);

        $deployment = Deployment::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->a['team']->id, 'application' => 'shop', 'environment' => 'production', 'version' => '2.4.0', 'commit_sha' => str_repeat('ab', 20), 'deployed_at' => $this->onset->subMinutes(15)]);
        DB::table('deployment_resources')->insert(['deployment_id' => $deployment->id, 'resource_type' => 'server', 'resource_id' => $server->id]);

        AuditLog::forceCreate(['team_id' => $this->a['team']->id, 'action' => 'domain.updated', 'auditable_type' => 'domain', 'auditable_id' => $domain->id, 'description' => 'Website alpha-shop.example.com bijgewerkt', 'created_at' => $this->onset->subMinutes(10)]);

        return $incident->fresh();
    }

    private function page(): Incident
    {
        MetricSample::query()->delete();
        $page = $this->a['page'];
        $incident = app(IncidentManager::class)->trigger(new Signal('page:contract', 'page', 'high', 'alpha pagina faalt', null, $page, startedAt: $this->onset->toMutable(), teamId: $this->a['team']->id));

        foreach ([[-30, true], [2, false], [7, false], [50, true]] as [$minute, $success]) {
            DB::table('page_checks')->insert(['monitored_page_id' => $page->id, 'domain_id' => $page->domain_id, 'result' => $success ? 'up' : 'down', 'success' => $success, 'failure_type' => $success ? null : 'content', 'message' => $success ? null : 'Keyword missing', 'checked_at' => $this->onset->addMinutes($minute)]);
        }

        return $incident->fresh();
    }

    private function manual(): Incident
    {
        return app(IncidentManager::class)->trigger(new Signal('manual:contract', 'manual', 'warning', 'Handmatig', null, null, teamId: $this->a['team']->id))->fresh();
    }

    private function releaseAttributes(): array
    {
        return ['version' => '0.3.1', 'version_major' => 0, 'version_minor' => 3, 'version_patch' => 1, 'filename' => 'serverscope-agent-0.3.1.tar.gz', 'sha256' => str_repeat('c', 64), 'size' => 1000, 'commit' => str_repeat('d', 40), 'key_id' => str_repeat('e', 16), 'released_at' => $this->onset->subDay(), 'min_updater_version' => 1, 'manifest' => '{}', 'signature' => 'sig'];
    }

    private function samples(string $from, string $to, ?string $spikeFrom = null): void
    {
        MetricSample::query()->where('server_id', $this->a['server']->id)->delete();
        $rows = [];
        $spike = $spikeFrom ? CarbonImmutable::parse($spikeFrom, 'UTC') : null;

        for ($at = CarbonImmutable::parse($from, 'UTC'); $at->lt(CarbonImmutable::parse($to, 'UTC')); $at = $at->addMinutes(5)) {
            $rows[] = ['server_id' => $this->a['server']->id, 'collected_at' => $at, 'created_at' => $at, 'updated_at' => $at, 'cpu_percent' => $spike && $at->gte($spike) ? 95 : 10 + $at->minute % 3, 'memory_total_bytes' => 1000, 'memory_used_bytes' => 400];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSample::query()->insert($chunk);
        }
    }

    /** The JSON with every row id replaced by its order of first appearance per kind. */
    private static function stableIds(string $json): array
    {
        $seen = [];
        $id = function (string $kind, string $value) use (&$seen): string {
            $seen[$kind] ??= [];

            return $seen[$kind][$value] ??= (string) (count($seen[$kind]) + 1);
        };

        $json = preg_replace_callback('/\\b(server|domain|page|service):(\\d+)\\b/', fn ($m) => $m[1].':'.$id($m[1], $m[2]), $json);
        $json = preg_replace_callback('#/(incidents|deployments|servers|domains|pages)/(\\d+)#', fn ($m) => '/'.$m[1].'/'.$id($m[1] === 'deployments' ? 'deployment' : ($m[1] === 'incidents' ? 'incident' : rtrim($m[1], 's')), $m[2]), $json);
        $json = preg_replace_callback('/#(\\d+)/', fn ($m) => '#'.$id('incident', $m[1]), $json);

        return json_decode($json, true);
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->toIso8601String(),
            $value instanceof Model => $value->getMorphClass().':'.$value->getKey(),
            is_array($value) => array_map(fn ($v) => $this->normalize($v), $value),
            default => $value,
        };
    }
}
