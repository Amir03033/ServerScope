<?php

namespace Tests\Feature;

use App\Models\Heartbeat;
use App\Models\Incident;
use App\Services\Heartbeats\HeartbeatMonitor;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Cron monitoring: jobs check in on their ping URL; silence or a failure opens an incident. */
class HeartbeatsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->createTwoTeams();
    }

    private function job(array $attributes = []): Heartbeat
    {
        return Tenant::run($this->a['team'], function () use ($attributes) {
            $heartbeat = new Heartbeat(['name' => 'Nightly backup', 'schedule_type' => 'interval', 'period_minutes' => 60, 'grace_minutes' => 5, 'severity' => 'high', 'timezone' => 'UTC', 'server_id' => $this->a['server']->id, ...$attributes]);
            $heartbeat->forceFill(['token' => Heartbeat::newToken(), 'status' => 'new'])->save();

            return $heartbeat;
        });
    }

    private function incident(Heartbeat $heartbeat): ?Incident
    {
        return Incident::query()->withoutGlobalScopes()->where('subject_type', 'heartbeat')->where('subject_id', $heartbeat->id)->latest('id')->first();
    }

    public function test_an_admin_creates_a_job_and_members_see_it(): void
    {
        $this->actingAs($this->a['admin'])->post(route('heartbeats.store'), [
            'name' => 'Invoice run', 'server_id' => $this->a['server']->id, 'schedule_type' => 'cron', 'cron_expression' => ' 0  3 * * * ',
            'timezone' => 'Europe/Amsterdam', 'grace_minutes' => 10, 'severity' => 'critical',
        ])->assertRedirect();

        $heartbeat = Heartbeat::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$this->a['team']->id, '0 3 * * *', null, 'new'], [$heartbeat->team_id, $heartbeat->cron_expression, $heartbeat->period_minutes, $heartbeat->status]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $heartbeat->token);

        $this->actingAs($this->a['member'])->get(route('heartbeats.index'))->assertOk()->assertSee('Invoice run');
        $this->get(route('heartbeats.show', $heartbeat))->assertOk()->assertSee($heartbeat->pingUrl())->assertSee('Cron 0 3 * * * (Europe/Amsterdam)');
        $this->post(route('heartbeats.store'), ['name' => 'x'])->assertForbidden();

        // Other team: not visible, not linkable to their server.
        $this->actingAs($this->b['admin'])->get(route('heartbeats.show', $heartbeat))->assertNotFound();
        $this->get(route('heartbeats.index'))->assertDontSee('Invoice run');
        $this->post(route('heartbeats.store'), ['name' => 'Spy', 'server_id' => $this->a['server']->id, 'schedule_type' => 'interval', 'period_minutes' => 60, 'timezone' => 'UTC', 'grace_minutes' => 5, 'severity' => 'high'])
            ->assertSessionHasErrors('server_id');
        $this->post(route('heartbeats.store'), ['name' => 'Bad', 'schedule_type' => 'cron', 'cron_expression' => 'every night', 'timezone' => 'UTC', 'grace_minutes' => 5, 'severity' => 'high'])
            ->assertSessionHasErrors('cron_expression');
    }

    public function test_the_add_form_offers_templates_that_are_valid_jobs(): void
    {
        $page = $this->actingAs($this->a['admin'])->get(route('heartbeats.create'))->assertOk();

        foreach (Heartbeat::TEMPLATES as $key => $template) {
            $page->assertSee(__("heartbeats.templates.items.{$key}.name"));
            // Every template, posted as is, is accepted.
            $this->post(route('heartbeats.store'), ['name' => $key, 'timezone' => 'Europe/Amsterdam', ...array_diff_key($template, ['icon' => true])])->assertSessionHasNoErrors();
        }

        $this->assertSame(count(Heartbeat::TEMPLATES), Heartbeat::query()->withoutGlobalScopes()->where('team_id', $this->a['team']->id)->count());
        $this->get(route('heartbeats.edit', Heartbeat::query()->withoutGlobalScopes()->where('team_id', $this->a['team']->id)->first()))->assertDontSee(__('heartbeats.templates.title'));
    }

    public function test_a_ping_records_the_run_and_sets_the_next_expected_time(): void
    {
        $heartbeat = $this->job();

        $this->get('/api/ping/'.$heartbeat->token.'/start')->assertOk()->assertSeeText('OK');
        $this->travel(90)->seconds();
        $this->post('/api/ping/'.$heartbeat->token, [], ['CONTENT_TYPE' => 'text/plain'])->assertOk();

        $heartbeat->refresh();
        $this->assertSame('up', $heartbeat->status);
        $this->assertEqualsWithDelta(90000, $heartbeat->last_duration_ms, 2000);
        $this->assertTrue($heartbeat->next_due_at->equalTo($heartbeat->last_ping_at->copy()->addHour()));
        $this->assertSame(['start', 'success'], $heartbeat->pings()->orderBy('id')->pluck('kind')->all());

        $this->get('/api/ping/'.str_repeat('0', 32))->assertNotFound();
        $this->get('/api/ping/not-a-token')->assertNotFound();
    }

    public function test_a_late_job_opens_an_incident_and_the_next_ping_resolves_it(): void
    {
        $heartbeat = $this->job();
        $this->get($heartbeat->pingUrl())->assertOk();

        // Expected after an hour, with five minutes grace.
        $this->travel(64)->minutes();
        $this->assertSame(0, app(HeartbeatMonitor::class)->check());
        $this->travel(2)->minutes();
        $this->assertSame(1, app(HeartbeatMonitor::class)->check());

        $incident = $this->incident($heartbeat);
        $this->assertSame([$this->a['team']->id, 'heartbeat', 'high', 'open'], [$incident->team_id, $incident->source, $incident->severity, $incident->status]);
        $this->assertStringContainsString('Nightly backup', $incident->title);
        $this->assertSame('down', $heartbeat->fresh()->status);

        // Still late a minute later: the same incident, not a new one.
        $this->travel(1)->minutes();
        app(HeartbeatMonitor::class)->check();
        $this->assertSame(1, Incident::query()->withoutGlobalScopes()->where('source', 'heartbeat')->count());

        $this->get($heartbeat->pingUrl())->assertOk();
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertSame('up', $heartbeat->fresh()->status);

        // The incident page links back to the job, and the job page to the incident.
        $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk()->assertSee(route('heartbeats.show', $heartbeat));
        $this->get(route('heartbeats.show', $heartbeat))->assertOk()->assertSee(route('incidents.show', $incident));
    }

    public function test_a_failure_or_exit_code_opens_an_incident_at_once_with_redacted_output(): void
    {
        $heartbeat = $this->job(['severity' => 'critical']);

        $this->call('POST', '/api/ping/'.$heartbeat->token.'/3', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "pg_dump: error\npassword=hunter2 failed")->assertOk();

        $ping = $heartbeat->pings()->sole();
        $this->assertSame(['fail', 3], [$ping->kind, $ping->exit_code]);
        $this->assertStringNotContainsString('hunter2', $ping->body);

        $incident = $this->incident($heartbeat);
        $this->assertSame('critical', $incident->severity);
        $this->assertStringContainsString('exitcode 3', $incident->description);

        $this->get('/api/ping/'.$heartbeat->token.'/0')->assertOk();
        $this->assertSame('resolved', $incident->fresh()->status);

        $this->get('/api/ping/'.$heartbeat->token.'/fail')->assertOk();
        $this->assertSame('down', $heartbeat->fresh()->status);
    }

    public function test_new_and_paused_jobs_never_alert(): void
    {
        $new = $this->job(['name' => 'Never pinged']);
        $paused = $this->job(['name' => 'Paused job']);
        $this->get($paused->pingUrl())->assertOk();

        $this->actingAs($this->a['admin'])->post(route('heartbeats.pause', $paused))->assertRedirect();
        $this->assertSame('paused', $paused->fresh()->status);

        $this->travel(3)->days();
        $this->assertSame(0, app(HeartbeatMonitor::class)->check());
        $this->get($paused->pingUrl('fail'))->assertOk();
        $this->assertNull($this->incident($paused));
        $this->assertNull($this->incident($new));
        $this->assertSame('paused', $paused->fresh()->status);

        // Resuming waits for the next ping instead of alerting about the pause.
        $this->post(route('heartbeats.pause', $paused))->assertRedirect();
        $this->assertSame(['new', null], [$paused->fresh()->status, $paused->fresh()->next_due_at]);
    }

    public function test_jobs_appear_in_nexus_next_to_their_server_and_carry_their_incident(): void
    {
        $heartbeat = $this->job();
        $this->job(['name' => 'Without server', 'server_id' => null]);
        $this->get($heartbeat->pingUrl('fail'))->assertOk();
        \App\Services\Nexus\TopologyBuilder::forget($this->a['team']->id);

        $nodes = collect(app(\App\Services\Nexus\TopologyBuilder::class)->build($this->a['team']->id)['nodes'])->keyBy('key');
        $node = $nodes["heartbeat:{$heartbeat->id}"];

        $this->assertSame(['service', 'server:'.$this->a['server']->id, 'job_down', 'critical', route('heartbeats.show', $heartbeat), 'scheduled_job'], [$node['type'], $node['parent'], $node['status'], $node['health'], $node['url'], $node['details']['kind']]);
        $this->assertSame(1, $node['incidents']['active']);
        $this->assertCount(1, $nodes->filter(fn ($n) => str_starts_with($n['key'], 'heartbeat:')), 'a job without a server is not on the map');
        $this->assertSame([], collect(app(\App\Services\Nexus\TopologyBuilder::class)->build($this->b['team']->id)['nodes'])->filter(fn ($n) => str_starts_with($n['key'], 'heartbeat:'))->all());
    }

    public function test_hover_cards_show_the_job_and_what_matters_on_its_server(): void
    {
        $heartbeat = $this->job();
        $this->get($heartbeat->pingUrl())->assertOk();
        $this->get($heartbeat->pingUrl('2'))->assertOk();

        $job = $this->actingAs($this->a['member'])->getJson(route('peek.heartbeat', $heartbeat))->assertOk()->json('data');
        $this->assertSame(['heartbeat', 'Nightly backup', ['success', 'fail']], [$job['type'], $job['title'], $job['runs']['kinds']]);
        $this->assertStringContainsString('2', $job['highlights'][0]['text']);
        $this->assertStringContainsString('Nightly backup', $job['sections'][0]['items'][0]['label'], 'its open incident');

        $server = $this->getJson(route('peek.server', $this->a['server']))->assertOk()->json('data');
        $this->assertContains('Nightly backup', array_column(collect($server['sections'])->firstWhere('title', __('peek.jobs'))['items'], 'label'));
        $this->assertNotEmpty(collect($server['highlights'])->filter(fn ($h) => $h['tone'] === 'bad')->all());

        $this->actingAs($this->b['member'])->getJson(route('peek.heartbeat', $heartbeat))->assertNotFound();
    }

    public function test_a_cron_schedule_is_expected_at_its_next_run_in_its_time_zone(): void
    {
        $heartbeat = $this->job(['schedule_type' => 'cron', 'cron_expression' => '0 3 * * *', 'period_minutes' => null, 'timezone' => 'Europe/Amsterdam']);

        $this->travelTo(now('Europe/Amsterdam')->setDate(2026, 11, 10)->setTime(3, 2)->utc());
        $this->get($heartbeat->pingUrl())->assertOk();

        $this->assertSame('2026-11-11 02:00', $heartbeat->fresh()->next_due_at->utc()->format('Y-m-d H:i'));
    }

    public function test_a_new_url_stops_the_old_one(): void
    {
        $heartbeat = $this->job();
        $old = $heartbeat->token;

        $this->actingAs($this->a['admin'])->post(route('heartbeats.rotate', $heartbeat))->assertRedirect();

        $this->get('/api/ping/'.$old)->assertNotFound();
        $this->get('/api/ping/'.$heartbeat->fresh()->token)->assertOk();
    }
}
