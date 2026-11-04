<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LogIngestController;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\LogEntry;
use App\Models\MetricSample;
use App\Services\AlertDetectionService;
use App\Services\Logs\LogFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Logs: lines the agent sends, the Logs page, the incident view and spike alerts. */
class LogsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private string $agentToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTwoTeams();

        $this->agentToken = bin2hex(random_bytes(32));
        $this->a['server']->agentCredentials()->create(['name' => 'logs test', 'token_hash' => hash('sha256', $this->agentToken)]);
    }

    private function send(array $entries, int $dropped = 0)
    {
        return $this->withToken($this->agentToken)->postJson(route('agent.logs.store'), ['entries' => $entries, 'dropped' => $dropped]);
    }

    private function line(string $message, string $level = 'error', string $source = 'laravel', ?string $context = null, int $minutesAgo = 0): array
    {
        return array_filter(['source' => $source, 'level' => $level, 'message' => $message, 'context' => $context, 'occurred_at' => now()->subMinutes($minutesAgo)->toIso8601String()]);
    }

    public function test_the_agent_sends_lines_that_are_redacted_and_grouped(): void
    {
        $this->send([
            $this->line('Order 1234 failed for amir@example.com password=hunter2', context: "#0 Bearer abc.def.ghi\n#1 {main}"),
            $this->line('Order 99 failed for someone@example.org password=other'),
            $this->line('Slow query took 812 ms', 'warning'),
        ], 3)->assertCreated()->assertJson(['accepted' => 3]);

        $entries = LogEntry::query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame([$this->a['team']->id, $this->a['server']->id], [$entries[0]->team_id, $entries[0]->server_id]);
        $this->assertStringNotContainsString('hunter2', $entries[0]->message);
        $this->assertStringNotContainsString('amir@', $entries[0]->message);
        $this->assertStringNotContainsString('abc.def', $entries[0]->context);
        $this->assertSame($entries[0]->fingerprint, $entries[1]->fingerprint, 'same problem, other numbers');
        $this->assertNotSame($entries[0]->fingerprint, $entries[2]->fingerprint);
    }

    public function test_bad_or_unauthenticated_lines_are_refused(): void
    {
        $this->postJson(route('agent.logs.store'), ['entries' => [$this->line('x')]])->assertUnauthorized();
        $this->send([['source' => 'bad source!', 'level' => 'debug', 'message' => '', 'occurred_at' => now()->subDay()->toIso8601String()]])
            ->assertUnprocessable()->assertJsonValidationErrors(['entries.0.source', 'entries.0.level', 'entries.0.message', 'entries.0.occurred_at']);
        $this->assertSame(0, LogEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_a_flooding_server_is_capped_per_hour(): void
    {
        $rows = array_fill(0, LogIngestController::MAX_PER_HOUR - 1, ['team_id' => $this->a['team']->id, 'server_id' => $this->a['server']->id, 'source' => 'app', 'level' => 'error', 'message' => 'x', 'fingerprint' => sha1('x'), 'occurred_at' => now(), 'created_at' => now()]);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('log_entries')->insert($chunk);
        }

        $this->send([$this->line('one'), $this->line('two')])->assertCreated()->assertJson(['accepted' => 1, 'skipped' => 1]);
    }

    public function test_the_logs_page_shows_issues_and_the_stream_of_the_own_team_only(): void
    {
        $this->send([$this->line('Order 1 failed', context: "Stack trace:\n#0 alpha-trace"), $this->line('Order 2 failed'), $this->line('Disk almost full', 'warning', 'syslog')])->assertCreated();
        DB::table('log_entries')->insert(['team_id' => $this->b['team']->id, 'server_id' => $this->b['server']->id, 'source' => 'bravo-app', 'level' => 'error', 'message' => 'bravo geheim', 'fingerprint' => sha1('b'), 'occurred_at' => now(), 'created_at' => now()]);

        $page = $this->actingAs($this->a['member'])->get(route('logs.index'))->assertOk()->assertSee('Order 2 failed')->assertSee('Disk almost full')->assertDontSee('bravo');
        $page->assertSee('2×', false);

        $fingerprint = LogFingerprint::of('laravel', 'Order 1 failed');
        $this->get(route('logs.index', ['view' => 'stream', 'fingerprint' => $fingerprint]))->assertOk()
            ->assertSee('alpha-trace')->assertDontSee('Disk almost full');

        $this->get(route('logs.index', ['level' => 'warning', 'view' => 'stream']))->assertOk()->assertSee('Disk almost full')->assertDontSee('Order 1 failed');
        $this->get(route('logs.index', ['period' => '7d', 'q' => 'disk']))->assertOk()->assertSee('Disk almost full');
    }

    public function test_without_lines_the_page_explains_how_to_start(): void
    {
        $this->actingAs($this->a['member'])->get(route('logs.index'))->assertOk()->assertSee(__('logs.onboarding.title'))->assertSee('SERVERSCOPE_LOG_FILES');
    }

    public function test_the_incident_shows_the_errors_on_its_server_around_it(): void
    {
        $this->send([$this->line('Database connection refused')])->assertCreated();

        $this->actingAs($this->a['member'])->get(route('incidents.show', $this->a['incident']))->assertOk()
            ->assertSee(__('logs.incident.title'))->assertSee('Database connection refused');
        $this->get(route('logs.index', ['incident' => $this->a['incident']->id]))->assertOk()->assertSee('Database connection refused');
        $this->get(route('logs.index', ['incident' => $this->b['incident']->id]))->assertNotFound();
    }

    public function test_a_spike_in_log_errors_raises_an_alert(): void
    {
        $rule = AlertRule::forceCreate(['team_id' => $this->a['team']->id, 'name' => 'Error spike', 'metric' => AlertRule::METRIC_LOG_ERRORS, 'threshold' => 3, 'operator' => '>=', 'duration_minutes' => 1, 'is_enabled' => true]);

        $sample = fn (int $secondsAgo) => MetricSample::create(['server_id' => $this->a['server']->id, 'cpu_percent' => 5, 'collected_at' => now()->subSeconds($secondsAgo)]);
        $evaluate = fn ($s) => app(AlertDetectionService::class)->evaluate($s);

        $evaluate($sample(110));
        $this->assertFalse(Alert::query()->withoutGlobalScopes()->where('alert_rule_id', $rule->id)->exists());

        // Three errors (and a warning, not counted) two minutes ago, still within the 5-minute window of both samples.
        $this->send([$this->line('a', minutesAgo: 2), $this->line('b', minutesAgo: 2), $this->line('c', minutesAgo: 2), $this->line('only a warning', 'warning', minutesAgo: 2)])->assertCreated();
        $evaluate($sample(50));
        $evaluate($sample(0));

        $alert = Alert::query()->withoutGlobalScopes()->where('alert_rule_id', $rule->id)->sole();
        $this->assertSame(3.0, (float) $alert->observed_value);

        $this->actingAs($this->a['admin'])->get(route('alert-rules.create', ['metric' => 'log_errors']))->assertOk()->assertSee(__('logs.spike_rule_name'));
    }
}
