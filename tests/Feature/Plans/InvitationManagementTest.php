<?php

namespace Tests\Feature\Plans;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function member(Team $team, string $role): User
    {
        $u = User::factory()->create();
        $u->teams()->attach($team->id, ['role' => $role]);
        $u->forceFill(['current_team_id' => $team->id, 'is_admin' => $role !== 'member'])->save();

        return $u;
    }

    public function test_admin_sees_only_own_pending_invitations_and_can_revoke_them(): void
    {
        $a = Team::query()->create(['name' => 'A']);
        $b = Team::query()->create(['name' => 'B']);
        [, $tokenA] = TeamInvitation::issue($a, 'kees@example.test', 'member', null, null);
        TeamInvitation::issue($b, 'anders@example.test', 'member', null, null);
        [$expired] = TeamInvitation::issue($a, 'oud@example.test', 'member', null, null);
        $expired->update(['expires_at' => now()->subDay()]);
        $admin = $this->member($a, 'owner');

        $this->actingAs($admin)->get(route('users.index'))->assertOk()
            ->assertSee('kees@example.test')->assertDontSee('anders@example.test')->assertDontSee('oud@example.test');

        $mine = TeamInvitation::query()->where('email', 'kees@example.test')->sole();
        $this->actingAs($admin)->delete(route('users.invitations.revoke', $mine))->assertSessionHas('success');
        $this->assertDatabaseMissing('team_invitations', ['email' => 'kees@example.test']);
        $this->get("/invitations/{$tokenA}")->assertSee(__('invitations.invalid_title'));
    }

    public function test_resend_replaces_the_link_and_other_teams_get_404(): void
    {
        Notification::fake();
        $a = Team::query()->create(['name' => 'A']);
        $b = Team::query()->create(['name' => 'B']);
        [$inv, $old] = TeamInvitation::issue($a, 'kees@example.test', 'admin', 'Kees', null);
        [$foreign] = TeamInvitation::issue($b, 'x@example.test', 'member', null, null);
        $admin = $this->member($a, 'admin');

        $this->actingAs($admin)->post(route('users.invitations.resend', $inv))->assertSessionHas('success');
        Notification::assertSentOnDemand(TeamInvitationNotice::class);
        $new = TeamInvitation::query()->where('team_id', $a->id)->where('email', 'kees@example.test')->sole();
        $this->assertSame('admin', $new->role);
        $this->get("/invitations/{$old}")->assertSee(__('invitations.invalid_title'));

        $this->actingAs($admin)->post(route('users.invitations.resend', $foreign))->assertNotFound();
        $this->actingAs($admin)->delete(route('users.invitations.revoke', $foreign))->assertNotFound();
        $this->assertDatabaseHas('team_invitations', ['email' => 'x@example.test']);
    }

    public function test_plain_members_cannot_manage_invitations(): void
    {
        $a = Team::query()->create(['name' => 'A']);
        [$inv] = TeamInvitation::issue($a, 'kees@example.test', 'member', null, null);
        $member = $this->member($a, 'member');

        $this->actingAs($member)->post(route('users.invitations.resend', $inv))->assertForbidden();
        $this->actingAs($member)->delete(route('users.invitations.revoke', $inv))->assertForbidden();
    }
}
