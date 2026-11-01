<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Deployment;
use App\Models\Incident;
use App\Models\IncidentLearning;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\IncidentMemory;
use App\Services\Incidents\Signal;
use App\Services\Incidents\StaleLearningException;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class IncidentMemoryTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow('2026-11-10 12:00:00');
        AlertRule::query()->forceDelete();
        $this->createTwoTeams();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A resolved HTTP incident on a domain, $daysAgo days ago. */
    private function pastIncident(array $team, int $daysAgo, string $fingerprint, array $context = ['error' => 'timeout'], string $source = 'http', ?string $title = null, ?Model $subject = null): Incident
    {
        $this->travelTo(now()->subDays($daysAgo));
        $manager = app(IncidentManager::class);
        $incident = $manager->trigger(new Signal($fingerprint, $source, 'critical', $title ?? "{$team['domain']->name} onbereikbaar", null, $subject ?? $team['domain'], $context, startedAt: now()->subMinutes(10), teamId: $team['team']->id));
        $this->travel(40)->minutes();
        $manager->recover($fingerprint, 'Hersteld');
        $this->travelBack();
        Carbon::setTestNow('2026-11-10 12:00:00');

        return $incident->fresh();
    }

    private function current(array $team, string $fingerprint, array $context = ['error' => 'timeout']): Incident
    {
        return app(IncidentManager::class)->trigger(new Signal($fingerprint, 'http', 'critical', "{$team['domain']->name} onbereikbaar", null, $team['domain'], $context, teamId: $team['team']->id));
    }

    private function learn(Incident $incident, array $extra = [], ?array $team = null)
    {
        return $this->actingAs(($team ?? $this->a)['member'])->post(route('incidents.learning', $incident), [
            'cause_category' => 'deployment', 'cause_status' => 'confirmed', 'root_cause' => 'Release 1.4 exhausted the PHP-FPM pool',
            'symptoms' => ' 502 Errors , slow login, 502 errors ', 'resolution_steps' => 'Rolled back to 1.3 and raised pm.max_children', 'outcome' => 'successful', ...$extra,
        ]);
    }

    public function test_a_member_records_the_cause_resolution_and_outcome(): void
    {
        $incident = $this->pastIncident($this->a, 3, 'domain:'.$this->a['domain']->id.':http');

        $this->learn($incident)->assertRedirect(route('incidents.show', $incident).'#memory')->assertSessionHas('success');

        $learning = IncidentLearning::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$this->a['team']->id, $incident->id, 'deployment', 'confirmed', 'successful', 1], [$learning->team_id, $learning->incident_id, $learning->cause_category, $learning->cause_status, $learning->outcome, $learning->version]);
        $this->assertSame(['502 errors', 'slow login'], $learning->symptoms, 'normalised and de-duplicated');
        $this->assertSame(50, $learning->time_to_resolve_minutes, 'from the start of the problem to resolution');
        $this->assertSame($this->a['member']->id, $learning->recorded_by);
        $this->assertDatabaseHas('audit_logs', ['team_id' => $this->a['team']->id, 'action' => 'incident.learning_saved']);

        $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk()
            ->assertSee('Release 1.4 exhausted the PHP-FPM pool')->assertSee(trans('incident_memory.cause_status.confirmed', [], 'nl'));
    }

    public function test_input_is_validated_and_deployments_must_belong_to_the_team(): void
    {
        $incident = $this->current($this->a, 'x:1');

        $this->learn($incident, ['root_cause' => ''])->assertSessionHasErrors('root_cause');
        $this->learn($incident, ['cause_category' => 'aliens'])->assertSessionHasErrors('cause_category');
        $this->learn($incident, ['cause_status' => 'certain'])->assertSessionHasErrors('cause_status');
        $this->learn($incident, ['outcome' => 'maybe'])->assertSessionHasErrors('outcome');
        $this->learn($incident, ['symptoms' => str_repeat('a', 701)])->assertSessionHasErrors('symptoms');

        $bravoDeployment = Deployment::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->b['team']->id, 'application' => 'bravo-app', 'deployed_at' => now()]);
        $this->learn($incident, ['deployment_id' => $bravoDeployment->id])->assertSessionHasErrors('deployment_id');
        $this->assertSame(0, IncidentLearning::query()->withoutGlobalScopes()->count());

        $own = Deployment::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->a['team']->id, 'application' => 'shop', 'version' => '1.4', 'deployed_at' => now()->subHour()]);
        $this->learn($incident, ['deployment_id' => $own->id])->assertSessionHasNoErrors();
        $this->assertSame($own->id, IncidentLearning::query()->withoutGlobalScopes()->sole()->deployment_id);
    }

    public function test_concurrent_edits_and_duplicate_records_are_refused(): void
    {
        $incident = $this->current($this->a, 'x:2');
        $this->learn($incident);

        // A second "new" record (no version) for the same incident is stale.
        $this->learn($incident, ['root_cause' => 'Something else'])->assertSessionHas('error', trans('incident_memory.stale', [], 'nl'));
        // An edit based on version 1 succeeds once; a second edit based on 1 is stale.
        $this->learn($incident, ['root_cause' => 'Pool too small', 'version' => 1])->assertSessionHas('success');
        $this->learn($incident, ['root_cause' => 'Lost update', 'version' => 1])->assertSessionHas('error');

        $learning = IncidentLearning::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['Pool too small', 2], [$learning->root_cause, $learning->version]);

        // The database itself refuses a second row for one incident.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('incident_learnings')->insert(['team_id' => $this->a['team']->id, 'incident_id' => $incident->id, 'cause_category' => 'unknown', 'root_cause' => 'dup', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_service_reports_a_lost_race_as_stale(): void
    {
        $incident = $this->current($this->a, 'x:3');
        $memory = app(IncidentMemory::class);
        $data = ['cause_category' => 'network', 'cause_status' => 'hypothesis', 'root_cause' => 'Packet loss', 'outcome' => 'unresolved'];

        $memory->save($incident, $data, $this->a['member'], null);

        $this->expectException(StaleLearningException::class);
        $memory->save($incident, $data, $this->a['admin'], null);
    }

    public function test_previous_incidents_are_matched_and_ranked_with_their_lessons(): void
    {
        $fp = 'domain:'.$this->a['domain']->id.':http';
        $confirmed = $this->pastIncident($this->a, 10, $fp);
        $this->learn($confirmed);
        $failedFix = $this->pastIncident($this->a, 5, $fp);
        $this->learn($failedFix, ['cause_status' => 'hypothesis', 'root_cause' => 'Maybe the CDN', 'resolution_steps' => 'Purged the CDN cache', 'outcome' => 'unsuccessful']);
        // Same resource, other problem (TLS) without a record.
        $tls = $this->pastIncident($this->a, 7, 'domain:'.$this->a['domain']->id.':ssl', ['error' => 'expired'], 'ssl');
        // Unrelated: another check type on another resource, no record.
        $this->pastIncident($this->a, 4, 'server:'.$this->a['server']->id.':cpu', [], 'alert', 'cpu hoog', $this->a['server']);

        $now = $this->current($this->a, $fp);
        $matches = app(IncidentMemory::class)->similar($now);

        $this->assertSame([$confirmed->id, $failedFix->id, $tls->id], $matches->pluck('incident.id')->all());
        $this->assertSame(['same_problem', 'same_check', 'shared_symptoms'], $matches[0]['reasons']);
        $this->assertSame(['error:timeout'], $matches[0]['symptoms']);
        $this->assertSame(['same_resource'], $matches[2]['reasons']);

        // On the page: the confirmed cause and the fix that did not work are labelled.
        $this->actingAs($this->a['member'])->get(route('incidents.show', $now))->assertOk()
            ->assertSee('Release 1.4 exhausted the PHP-FPM pool')
            ->assertSee(trans('incident_memory.cause_status.hypothesis', [], 'nl'))
            ->assertSee(trans('incident_memory.outcomes.unsuccessful', [], 'nl'))
            ->assertSee('Purged the CDN cache');
    }

    public function test_memory_never_crosses_teams(): void
    {
        // Team B has a confirmed lesson for an identical-looking problem.
        $bravoPast = $this->pastIncident($this->b, 3, 'domain:'.$this->a['domain']->id.':http-bravo');
        $this->learn($bravoPast, ['root_cause' => 'bravo secret root cause'], $this->b);
        $alpha = $this->current($this->a, 'domain:'.$this->a['domain']->id.':http-bravo');

        $this->assertSame([], app(IncidentMemory::class)->similar($alpha)->pluck('incident.id')->all());
        $this->actingAs($this->a['member'])->get(route('incidents.show', $alpha))->assertOk()->assertDontSee('bravo secret root cause');

        // Writing or reading another team's incident is a 404.
        $this->learn($bravoPast, ['root_cause' => 'overwrite'])->assertNotFound();
        $this->actingAs($this->a['admin'])->get(route('incidents.show', $bravoPast))->assertNotFound();
        $this->assertSame('bravo secret root cause', IncidentLearning::query()->withoutGlobalScopes()->sole()->root_cause);

        // Search covers the recorded cause, within the own team only.
        $this->actingAs($this->b['member'])->get(route('incidents.index', ['state' => 'all', 'q' => 'secret root']))->assertOk()->assertSee('#'.$bravoPast->id);
        $this->actingAs($this->a['member'])->get(route('incidents.index', ['state' => 'all', 'q' => 'secret root']))->assertOk()->assertDontSee('#'.$bravoPast->id);
        Tenant::run($this->a['team']->id, fn () => $this->assertSame(0, IncidentLearning::query()->count()));

        auth()->guard()->logout();
        $this->post(route('incidents.learning', $alpha), [])->assertRedirect(route('login'));
    }

    public function test_empty_states_and_editing_an_existing_record(): void
    {
        $incident = $this->current($this->a, 'x:4');

        $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk()
            ->assertSee(trans('incident_memory.empty', [], 'nl'))->assertSee(trans('incident_memory.similar_none', [], 'nl'));

        $this->learn($incident, ['cause_status' => 'hypothesis', 'outcome' => 'unresolved']);
        $this->learn($incident, ['cause_status' => 'confirmed', 'outcome' => 'successful', 'version' => 1])->assertSessionHas('success');

        $learning = IncidentLearning::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['confirmed', 'successful', 2, $this->a['member']->id], [$learning->cause_status, $learning->outcome, $learning->version, $learning->updated_by]);
        $this->assertNull($learning->time_to_resolve_minutes, 'not resolved yet');
        $this->actingAs($this->a['member'])->get(route('incidents.show', $incident))->assertOk()->assertSee('name="version" value="2"', false);
    }
}
