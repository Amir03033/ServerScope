<?php

namespace Tests\Feature\Tenancy;

use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\MissingTeamException;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/**
 * Roles, the current team, platform administration and the safety net
 * for records without a team.
 */
class TeamAccessTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->setUpTwoTeams();
    }

    public function test_the_team_role_is_the_only_source_of_admin_rights(): void
    {
        // users.is_admin is a mirror of the role in the current team; setting
        // it directly never grants anything, in any team.
        $member = $this->b['member'];
        $member->forceFill(['is_admin' => true])->save();

        $this->actingAs($member->fresh())->get(route('servers.create'))->assertForbidden();
        $this->actingAs($member->fresh())->get(route('users.index'))->assertForbidden();
        $this->assertFalse(Tenant::run($this->b['team'], fn () => Gate::forUser($member->fresh())->allows('admin')));

        $default = Team::query()->where('is_default', true)->firstOrFail();
        $member->teams()->attach($default->id, ['role' => 'member']);
        $this->assertFalse(Tenant::run($default, fn () => Gate::forUser($member->fresh())->allows('admin')));

        // Granting the role through user management updates the mirror (role -> is_admin).
        $this->actingAs($this->b['admin'])->put(route('users.update', $this->b['member']), ['is_admin' => '1'])->assertSessionHas('success');
        $this->assertSame('admin', $this->b['member']->fresh()->roleIn($this->b['team']));
        $this->assertTrue($this->b['member']->fresh()->is_admin);
        $this->actingAs($this->b['member']->fresh())->get(route('servers.create'))->assertOk();

        // Admin of B is not admin of A.
        $this->assertFalse($this->b['admin']->isAdminOf($this->a['team']));
    }

    public function test_current_team_is_verified_on_every_request(): void
    {
        $user = $this->a['member'];
        $this->actingAs($user)->get(route('servers.index'))->assertOk()->assertSee('alpha-server');

        // Pointing current_team_id at a team the user is not a member of.
        $user->forceFill(['current_team_id' => $this->b['team']->id])->save();
        $this->actingAs($user->fresh())->get(route('servers.index'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Removed from the team while signed in.
        $other = $this->a['admin'];
        $this->actingAs($other)->get(route('dashboard'))->assertOk();
        $other->teams()->detach($this->a['team']->id);
        $this->actingAs($other->fresh())->getJson(route('dashboard'))->assertForbidden();
    }

    public function test_a_user_without_current_team_gets_their_first_membership(): void
    {
        $user = $this->a['member'];
        $user->forceFill(['current_team_id' => null])->save();

        $this->actingAs($user->fresh())->get(route('servers.index'))->assertOk()->assertSee('alpha-server')->assertDontSee('bravo-server');
        $this->assertSame($this->a['team']->id, $user->fresh()->current_team_id);
    }

    public function test_switching_team_only_to_own_memberships(): void
    {
        $user = $this->a['admin'];
        $user->teams()->attach($this->b['team']->id, ['role' => 'member']);

        $this->actingAs($user)->post(route('teams.switch', $this->b['team']))->assertRedirect(route('dashboard'));
        $this->assertSame($this->b['team']->id, $user->fresh()->current_team_id);
        $this->assertFalse($user->fresh()->is_admin, 'mirror follows the role in the new current team');
        $this->actingAs($user->fresh())->get(route('servers.index'))->assertSee('bravo-server')->assertDontSee('alpha-server');

        $stranger = Team::query()->create(['name' => 'Charlie', 'slug' => 'charlie']);
        $this->actingAs($user->fresh())->post(route('teams.switch', $stranger))->assertNotFound();
    }

    public function test_registration_and_invitations_are_off_by_default(): void
    {
        $this->assertFalse(config('serverscope.tenancy.registration_enabled'));
        $this->assertFalse(config('serverscope.tenancy.invitations_enabled'));

        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'nieuw@example.test', 'password' => 'Wachtwoord123456', 'password_confirmation' => 'Wachtwoord123456', 'team' => 'Nieuw'])->assertNotFound();
        $this->assertFalse(User::query()->where('email', 'nieuw@example.test')->exists());

        // Without invitations an existing account of another team cannot be added.
        $this->actingAs($this->a['admin'])->post(route('users.store'), ['name' => 'x', 'email' => $this->b['member']->email])->assertSessionHasErrors('email');
        $this->assertFalse($this->b['member']->fresh()->belongsToTeam($this->a['team']));
    }

    public function test_registration_creates_an_isolated_team_when_enabled(): void
    {
        config(['serverscope.tenancy.registration_enabled' => true]);

        $this->post('/register', ['name' => 'Klant', 'email' => 'klant@example.test', 'password' => 'Wachtwoord123456', 'password_confirmation' => 'Wachtwoord123456', 'team' => 'Klant BV'])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'klant@example.test')->firstOrFail();
        $team = $user->currentTeam;
        $this->assertSame('owner', $user->roleIn($team));
        $this->assertFalse($user->is_platform_admin);
        $this->assertTrue(AlertRule::withoutGlobalScopes()->where('team_id', $team->id)->where('metric', 'agent_offline')->exists());
        $this->get(route('servers.index'))->assertOk()->assertDontSee('alpha-server')->assertDontSee('bravo-server');
    }

    public function test_invitations_add_existing_accounts_only_when_enabled(): void
    {
        config(['serverscope.tenancy.invitations_enabled' => true]);

        $this->actingAs($this->a['admin'])->post(route('users.store'), ['name' => 'x', 'email' => $this->b['member']->email])->assertSessionHas('success');
        $this->assertSame('member', $this->b['member']->fresh()->roleIn($this->a['team']));
        $this->assertSame($this->b['team']->id, $this->b['member']->fresh()->current_team_id, 'current team is unchanged');
    }

    public function test_records_without_a_team_fail_outside_tests(): void
    {
        config(['serverscope.tenancy.test_fallback' => false]);

        $this->expectException(MissingTeamException::class);
        Server::create(['name' => 'zwerver', 'hostname' => 'zwerver.test']);
    }

    public function test_audit_log_without_a_team_fails_outside_tests(): void
    {
        config(['serverscope.tenancy.test_fallback' => false]);

        // With a subject or a tenant the team is known.
        Audit::log('test.subject', 'met onderwerp', $this->b['server']);
        $this->assertSame($this->b['team']->id, AuditLog::withoutGlobalScopes()->where('action', 'test.subject')->value('team_id'));
        Tenant::run($this->a['team'], fn () => Audit::log('test.tenant', 'met tenant'));
        $this->assertSame($this->a['team']->id, AuditLog::withoutGlobalScopes()->where('action', 'test.tenant')->value('team_id'));

        $this->expectException(MissingTeamException::class);
        Audit::log('test.none', 'zonder team');
    }

    public function test_records_without_a_team_fall_back_to_the_default_team_in_tests(): void
    {
        $server = Server::create(['name' => 'test-server', 'hostname' => 'test.host']);
        $this->assertSame(Team::query()->where('is_default', true)->value('id'), $server->team_id);

        // A tenant always wins over the fallback.
        $own = Tenant::run($this->b['team'], fn () => Server::create(['name' => 'b2', 'hostname' => 'b2.host']));
        $this->assertSame($this->b['team']->id, $own->team_id);
    }

    public function test_platform_admin_is_separate_from_team_roles(): void
    {
        $this->actingAs($this->a['admin'])->get(route('system-health'))->assertOk()->assertSee('Scheduler')->assertDontSee('Wachtrij');
        $this->actingAs($this->a['member'])->get(route('system-health'))->assertForbidden();

        $platform = $this->member($this->a['team'], 'member', ['name' => 'Eigenaar']);
        $platform->forceFill(['is_platform_admin' => true])->save();

        // Platform administrators see the whole platform's health…
        $this->actingAs($platform)->get(route('system-health'))->assertOk()->assertSee('Wachtrij');
        // …but no configuration or data of other teams, nor team admin rights.
        $this->actingAs($platform)->get(route('servers.edit', $this->b['server']))->assertNotFound();
        $this->actingAs($platform)->get(route('servers.create'))->assertForbidden();
    }

    public function test_artisan_creates_teams_and_platform_admins(): void
    {
        $this->artisan('serverscope:create-team', ['name' => 'Delta BV', '--owner' => $this->a['member']->email])->assertSuccessful();

        $team = Team::query()->where('name', 'Delta BV')->firstOrFail();
        $this->assertSame('owner', $this->a['member']->fresh()->roleIn($team));
        $this->assertTrue(AlertRule::withoutGlobalScopes()->where('team_id', $team->id)->where('metric', 'agent_offline')->exists());

        $this->artisan('serverscope:create-admin', ['--platform' => true])
            ->expectsQuestion('Naam', 'Eigenaar')
            ->expectsQuestion('E-mailadres', 'eigenaar@example.test')
            ->expectsQuestion('Wachtwoord', 'Sterk-wachtwoord-123')
            ->expectsQuestion('Herhaal wachtwoord', 'Sterk-wachtwoord-123')
            ->assertSuccessful();

        $owner = User::query()->where('email', 'eigenaar@example.test')->firstOrFail();
        $this->assertTrue($owner->is_platform_admin);
        $this->assertTrue($owner->isAdminOf(Team::query()->where('is_default', true)->firstOrFail()));
    }
}
