<?php

namespace Tests\Feature;

use App\Jobs\RunDomainCheck;
use App\Jobs\RunPageCheck;
use App\Models\Domain;
use App\Models\Heartbeat;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Demo\DemoSeeder;
use App\Services\Demo\DemoTicker;
use App\Services\Incidents\IncidentInvestigator;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Support\Demo;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** The public read-only demo: one sample team per language, nothing changes, nothing leaves. */
class DemoTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private Team $team;

    private User $visitor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        // Seeding and the demo itself never talk to the outside world.
        Http::preventStrayRequests();
        Demo::flush();
        config(['serverscope.demo.enabled' => true]);
    }

    private function seedDemo(): void
    {
        $this->team = app(DemoSeeder::class)->seed('en');
        Demo::flush();
        $this->visitor = User::query()->where('email', Demo::email('en'))->sole();
    }

    public function test_the_demo_does_not_exist_while_it_is_off(): void
    {
        config(['serverscope.demo.enabled' => false]);

        $this->get('/demo')->assertNotFound();
    }

    public function test_a_visitor_enters_without_a_password_and_the_demo_user_cannot_sign_in_otherwise(): void
    {
        $this->seedDemo();

        $this->get('/demo')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->visitor);

        $this->get(route('dashboard'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee(__('demo.bar'))->assertSee('<meta name="robots" content="noindex, nofollow">', false)->assertSee('web-01');

        $this->get(route('demo.leave'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login'), ['email' => Demo::email('en'), 'password' => 'anything'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_every_kind_of_change_is_refused(): void
    {
        $this->seedDemo();
        $this->get('/demo');
        $incident = Incident::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->where('status', 'open')->firstOrFail();
        $server = Server::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->firstOrFail();
        $events = IncidentEvent::query()->count();

        $forms = [
            ['post', route('incidents.comment', $incident), ['message' => 'hello']],
            ['post', route('incidents.transition', $incident), ['status' => 'acknowledged']],
            ['put', route('profile.update'), ['name' => 'Hacker', 'email' => 'x@example.com']],
            ['put', route('profile.password'), ['current_password' => 'x', 'password' => 'secret-secret', 'password_confirmation' => 'secret-secret']],
            ['post', route('two-factor.enable'), []],
            ['post', route('api-tokens.store'), ['name' => 'x']],
            ['delete', route('servers.destroy', $server), []],
            ['post', route('heartbeats.store'), ['name' => 'x']],
            ['post', route('webhooks.store'), ['name' => 'x']],
        ];

        foreach ($forms as [$method, $url, $data]) {
            $this->from(route('dashboard'))->{$method}($url, $data)->assertRedirect()->assertSessionHas('error', __('demo.unavailable'));
        }

        // Script calls: dashboard layout, favourites, push, swipe to acknowledge, Livewire.
        $this->putJson(route('dashboard.layout'), ['order' => ['health']])->assertForbidden()->assertJson(['message' => __('demo.unavailable')]);
        $this->postJson(route('favorites.toggle'), ['type' => 'server', 'id' => $server->id])->assertForbidden();
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://push.example.com/x'])->assertForbidden();
        $this->postJson(route('incidents.transition', $incident), ['status' => 'acknowledged'])->assertForbidden();
        $this->withHeaders(['X-Livewire' => 'true'])->post(route('default-livewire.update'), ['components' => []])->assertForbidden();

        $this->assertSame($events, IncidentEvent::query()->count());
        $this->assertSame('open', $incident->fresh()->status);
        $this->assertNull($server->fresh()->deleted_at);
        $this->assertSame(['Demo visitor', null], [$this->visitor->fresh()->name, $this->visitor->fresh()->dashboard_layout]);
        $this->assertSame(0, DB::table('api_tokens')->count());

        // The language switch works, for this visitor only.
        $this->post(route('locale.update'), ['locale' => 'nl'])->assertRedirect();
        $this->assertSame('en', $this->visitor->fresh()->locale);
        $this->assertSame('nl', session('locale'));
    }

    public function test_pages_about_money_people_and_the_platform_are_not_shown(): void
    {
        $this->seedDemo();
        $this->get('/demo');

        foreach (['billing.show', 'users.index', 'system-health', 'api-tokens.index', 'servers.add', 'platform.commands'] as $route) {
            $this->get(route($route))->assertRedirect(route('dashboard'));
        }

        $this->get(route('dashboard'))->assertDontSee(route('billing.show'))->assertDontSee(route('users.index'));
    }

    public function test_nothing_leaves_the_server_for_the_demo_team(): void
    {
        Queue::fake();
        $this->seedDemo();

        // A new incident: no mail, push, database notification or webhook.
        Tenant::run($this->team, function () {
            WebhookEndpoint::query()->create(['name' => 'Example', 'type' => 'slack', 'url' => 'https://hooks.slack.com/…', 'secret' => 'https://hooks.slack.com/services/T0/B0/demo', 'events' => array_keys(WebhookEndpoint::EVENTS), 'is_active' => true]);
            app(IncidentManager::class)->trigger(new Signal('demo:test', 'manual', 'critical', 'Demo test', null, null, teamId: $this->team->id));
        });

        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
        $this->assertSame(0, WebhookDelivery::query()->count());
        $this->assertFalse(IncidentInvestigator::allowedFor($this->team->id));

        // The scheduler checks nothing of the demo for real.
        Domain::query()->withoutGlobalScopes()->update(['http_next_check_at' => now()->subHour(), 'ssl_next_check_at' => now()->subHour(), 'dns_next_check_at' => now()->subHour(), 'expiry_next_check_at' => now()->subHour()]);
        DB::table('monitored_pages')->update(['next_check_at' => now()->subHour()]);
        Server::query()->withoutGlobalScopes()->update(['last_seen_at' => now()->subDay()]);
        Heartbeat::query()->withoutGlobalScopes()->update(['next_due_at' => now()->subDay()]);

        foreach (['serverscope:dispatch-domain-checks', 'serverscope:dispatch-page-checks', 'serverscope:check-agents', 'serverscope:check-heartbeats', 'serverscope:escalate-incidents'] as $command) {
            Artisan::call($command);
        }

        Queue::assertNotPushed(RunDomainCheck::class);
        Queue::assertNotPushed(RunPageCheck::class);
        $this->assertSame(0, Server::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->where('name', 'web-01')->where('status', 'offline')->count(), 'demo servers are not judged');
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_the_seeder_is_repeatable_and_the_tick_keeps_it_moving(): void
    {
        $this->seedDemo();
        $count = fn () => [
            Server::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count(),
            Incident::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count(),
            MetricSample::query()->whereIn('server_id', Server::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->select('id'))->count(),
        ];
        $first = $count();

        $hash = $this->visitor->fresh()->password;
        $again = app(DemoSeeder::class)->seed('en');
        $this->assertSame($hash, $this->visitor->fresh()->password, 'a rebuild keeps visitors signed in');
        $this->assertSame([$this->team->id, $this->visitor->id], [$again->id, User::query()->where('email', Demo::email('en'))->value('id')]);
        $this->assertSame($first, $count());
        $this->assertSame(1, Team::query()->where('is_demo', true)->count());

        $this->travel(1)->minutes();
        $done = app(DemoTicker::class)->tick();

        $this->assertSame(4, $done['samples'], 'every online server reports; the offline one stays silent');
        $this->assertSame($first[2] + 4, $count()[2]);
        $this->assertSame(3, $done['checks']);
    }

    public function test_real_teams_see_nothing_of_the_demo(): void
    {
        $this->createTwoTeams();
        $this->seedDemo();
        $demoServer = Server::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->where('name', 'worker-01')->sole();
        $demoIncident = Incident::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->firstOrFail();

        $this->actingAs($this->a['member'])->get(route('servers.index'))->assertOk()->assertDontSee('worker-01');
        $this->get(route('servers.show', $demoServer))->assertNotFound();
        $this->get(route('incidents.show', $demoIncident))->assertNotFound();
        $this->getJson(route('peek.server', $demoServer))->assertNotFound();

        // A real account is never swapped for the demo.
        $this->get('/demo')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->a['member']);
    }
}
