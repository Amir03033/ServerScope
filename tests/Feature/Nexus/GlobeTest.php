<?php

namespace Tests\Feature\Nexus;

use App\Models\AlertRule;
use App\Models\Probe;
use App\Models\ProbeCheck;
use App\Services\Nexus\GlobeData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * NEXUS globe: measuring locations, per-website reachability per
 * location, team isolation and the probe coordinate commands.
 */
class GlobeTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private Probe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow('2026-11-10 12:00:00');
        AlertRule::query()->forceDelete();
        $this->createTwoTeams();
        config(['serverscope.probe.main_location' => ['name' => 'Amsterdam', 'latitude' => '52.37', 'longitude' => '4.9']]);

        $this->probe = new Probe(['name' => 'probe-1', 'location' => 'Frankfurt']);
        $this->probe->forceFill(['key' => str_repeat('a', 32), 'secret' => str_repeat('s', 64), 'is_active' => true, 'last_seen_at' => now(), 'latitude' => 50.11, 'longitude' => 8.68])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function check(array $team, string $outcome, string $requestedAt): ProbeCheck
    {
        return ProbeCheck::query()->withoutGlobalScopes()->forceCreate([
            'team_id' => $team['team']->id, 'probe_id' => $this->probe->id, 'domain_id' => $team['domain']->id,
            'status' => 'done', 'outcome' => $outcome, 'requested_at' => $requestedAt, 'expires_at' => Carbon::parse($requestedAt)->addMinutes(2), 'checked_at' => $requestedAt,
        ]);
    }

    public function test_locations_and_reachability_per_website(): void
    {
        $this->a['domain']->forceFill(['http_status' => 'down', 'http_last_checked_at' => now()->subMinute()])->save();
        $this->check($this->a, 'not_reproduced', now()->subDays(3)->toDateTimeString());
        $this->check($this->a, 'main_only', now()->subHour()->toDateTimeString());

        $data = app(GlobeData::class)->forTeam($this->a['team']->id);

        $this->assertSame(['main', 'probe:'.$this->probe->id], array_column($data['locations'], 'key'));
        $this->assertSame([52.37, 4.9], [$data['locations'][0]['lat'], $data['locations'][0]['lon']]);
        $this->assertTrue($data['locations'][1]['healthy']);

        $site = collect($data['sites'])->firstWhere('id', $this->a['domain']->id);
        // Main: the website's current HTTP status. Probe: the latest confirmation (main_only: reachable from the probe).
        $this->assertSame('down', $site['reach']['main']['state']);
        $this->assertSame('ok', $site['reach']['probe:'.$this->probe->id]['state']);
        $this->assertSame('main_only', $site['reach']['probe:'.$this->probe->id]['outcome']);
    }

    public function test_old_confirmations_and_no_confirmation_are_not_measured(): void
    {
        $this->check($this->a, 'confirmed', now()->subDays(GlobeData::WINDOW_DAYS + 1)->toDateTimeString());

        $site = collect(app(GlobeData::class)->forTeam($this->a['team']->id)['sites'])->firstWhere('id', $this->a['domain']->id);

        $this->assertArrayNotHasKey('probe:'.$this->probe->id, $site['reach'], 'not "reachable": unknown');
        $this->assertSame('unknown', $site['reach']['main']['state'], 'never checked yet');
    }

    public function test_unplaced_probes_and_main_are_counted_not_drawn(): void
    {
        $this->probe->forceFill(['latitude' => null, 'longitude' => null])->save();
        config(['serverscope.probe.main_location' => ['name' => null, 'latitude' => null, 'longitude' => null]]);

        $data = app(GlobeData::class)->forTeam($this->a['team']->id);

        $this->assertSame([], $data['locations']);
        $this->assertSame(1, $data['meta']['unplaced_probes']);
        $this->assertFalse($data['meta']['main_placed']);
        $this->assertSame([], collect($data['sites'])->firstWhere('id', $this->a['domain']->id)['reach']);
    }

    public function test_a_team_never_sees_another_teams_websites_or_confirmations(): void
    {
        $this->check($this->b, 'confirmed', now()->subHour()->toDateTimeString());
        // An inconsistent row: team B's confirmation of team A's website.
        ProbeCheck::query()->withoutGlobalScopes()->forceCreate(['team_id' => $this->b['team']->id, 'probe_id' => $this->probe->id, 'domain_id' => $this->a['domain']->id, 'status' => 'done', 'outcome' => 'confirmed', 'requested_at' => now(), 'expires_at' => now()->addMinute()]);

        $data = app(GlobeData::class)->forTeam($this->a['team']->id);

        $this->assertStringNotContainsString('bravo', json_encode($data));
        $this->assertSame([$this->a['domain']->id], array_column($data['sites'], 'id'));
        $this->assertArrayNotHasKey('probe:'.$this->probe->id, $data['sites'][0]['reach']);

        // Through HTTP: the page and the JSON follow the signed-in team.
        $this->get(route('nexus.globe'))->assertRedirect(route('login'));
        $this->actingAs($this->a['member'])->get(route('nexus.globe'))->assertOk()->assertSee('data-globe', false)->assertDontSee('bravo');
        $json = $this->actingAs($this->b['member'])->getJson(route('nexus.globe.data'))->assertOk();
        $this->assertSame([$this->b['domain']->id], array_column($json->json('data.sites'), 'id'));
        $this->assertStringNotContainsString('alpha', $json->getContent());
        $this->assertStringNotContainsString('secret', $json->getContent());
    }

    public function test_the_page_is_linked_from_nexus_and_translated(): void
    {
        foreach (['en' => 'Measuring locations', 'nl' => 'Meetlocaties'] as $locale => $title) {
            $this->a['member']->forceFill(['locale' => $locale])->save();
            $this->actingAs($this->a['member'])->get(route('nexus.index'))->assertOk()->assertSee(route('nexus.globe'));
            $html = $this->actingAs($this->a['member'])->get(route('nexus.globe'))->assertOk()->assertSee($title)->getContent();
            $this->assertMatchesRegularExpression('#src="[^"]*/build/assets/globe-[^"]+\.js"#', $html);
        }
    }

    public function test_probe_coordinates_are_set_and_validated_by_command(): void
    {
        $this->artisan('serverscope:probe:locate', ['name' => 'probe-1', '--lat' => '40.71', '--lon' => '-74.01', '--location' => 'New York'])->assertSuccessful();
        $this->assertSame([40.71, -74.01, 'New York'], [$this->probe->fresh()->latitude, $this->probe->fresh()->longitude, $this->probe->fresh()->location]);

        $this->artisan('serverscope:probe:locate', ['name' => 'probe-1', '--lat' => '95', '--lon' => '0'])->assertFailed();
        $this->artisan('serverscope:probe:locate', ['name' => 'probe-1', '--lat' => '10'])->assertFailed();
        $this->artisan('serverscope:probe:locate', ['name' => 'nope', '--lat' => '1', '--lon' => '1'])->assertFailed();
        $this->assertSame(40.71, $this->probe->fresh()->latitude, 'unchanged after invalid input');

        $this->artisan('serverscope:probe:locate', ['name' => 'probe-1', '--clear' => true])->assertSuccessful();
        $this->assertFalse($this->probe->fresh()->isPlaced());

        $this->artisan('serverscope:probe:create', ['name' => 'probe-1', '--rotate' => true, '--lat' => '50.11', '--lon' => '8.68'])->assertSuccessful();
        $this->assertSame([50.11, 8.68], [$this->probe->fresh()->latitude, $this->probe->fresh()->longitude]);
    }
}
