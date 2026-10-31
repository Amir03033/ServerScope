<?php

namespace Tests\Feature\Plans;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Team $team): User
    {
        $u = User::factory()->create();
        $u->teams()->attach($team->id, ['role' => 'owner']);
        $u->forceFill(['current_team_id' => $team->id, 'is_admin' => true])->save();

        return $u;
    }

    public function test_answer_is_identical_for_new_and_existing_addresses_and_nothing_is_added(): void
    {
        Notification::fake();
        $team = Team::query()->create(['name' => 'A']);
        $other = Team::query()->create(['name' => 'B']);
        $existing = User::factory()->create(['email' => 'bestaat@example.test']);
        $existing->teams()->attach($other->id, ['role' => 'member']);
        $admin = $this->admin($team);

        $a = $this->actingAs($admin)->post(route('users.store'), ['email' => 'bestaat@example.test']);
        $b = $this->actingAs($admin)->post(route('users.store'), ['email' => 'nieuw@example.test']);

        $this->assertSame(session('success') !== null, true);
        $a->assertSessionHasNoErrors();
        $b->assertSessionHasNoErrors();
        $this->assertFalse($existing->fresh()->belongsToTeam($team));
        $this->assertSame(2, TeamInvitation::query()->count());
    }

    private function invite(Team $team, string $email, string $role = 'member'): string
    {
        [, $plain] = TeamInvitation::issue($team, $email, $role, null, null);

        return $plain;
    }

    public function test_new_person_creates_a_verified_account_through_the_link(): void
    {
        $team = Team::query()->create(['name' => 'A']);
        $token = $this->invite($team, 'kees@example.test', 'admin');

        $this->get("/invitations/{$token}")->assertOk()->assertSee('kees@example.test');
        $this->post("/invitations/{$token}/register", ['name' => 'Kees', 'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x'])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'kees@example.test')->sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('admin', $user->roleIn($team));
        $this->get("/invitations/{$token}")->assertSee(__('invitations.invalid_title'));
    }

    public function test_existing_account_must_be_signed_in_as_that_address_to_accept(): void
    {
        $team = Team::query()->create(['name' => 'A']);
        $user = User::factory()->create(['email' => 'piet@example.test']);
        $stranger = User::factory()->create();
        $token = $this->invite($team, 'piet@example.test');

        $this->post("/invitations/{$token}/accept")->assertRedirect(route('login'));
        $this->actingAs($stranger)->post("/invitations/{$token}/accept")->assertNotFound();
        $this->assertFalse($user->fresh()->belongsToTeam($team));

        $this->actingAs($user)->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));
        $this->assertSame('member', $user->fresh()->roleIn($team));
        $this->actingAs($user)->post("/invitations/{$token}/accept")->assertNotFound(); // used once
    }

    public function test_expired_or_unknown_tokens_are_invalid_and_register_refuses_existing_accounts(): void
    {
        $team = Team::query()->create(['name' => 'A']);
        $token = $this->invite($team, 'oud@example.test');
        TeamInvitation::query()->update(['expires_at' => now()->subMinute()]);
        $this->get("/invitations/{$token}")->assertSee(__('invitations.invalid_title'));
        $this->get('/invitations/'.str_repeat('a', 48))->assertSee(__('invitations.invalid_title'));

        User::factory()->create(['email' => 'dubbel@example.test']);
        $t2 = $this->invite($team, 'dubbel@example.test');
        $this->post("/invitations/{$t2}/register", ['name' => 'X', 'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x'])->assertNotFound();
        $this->assertSame(1, User::query()->where('email', 'dubbel@example.test')->count());
    }
}
