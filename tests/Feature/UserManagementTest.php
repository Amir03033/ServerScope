<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_invites_users_who_set_their_own_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs(User::factory()->create())->post(route('users.store'), ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Nieuw', 'email' => 'Nieuw@Example.com', 'is_admin' => '0'])->assertSessionHas('success');

        $user = User::query()->where('email', 'nieuw@example.com')->sole();
        $this->assertFalse($user->is_admin);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invited']);

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Dubbel', 'email' => 'nieuw@example.com'])->assertSessionHasErrors('email');
    }

    public function test_role_changes_and_deletion_protect_admins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $user), ['is_admin' => '1'])->assertSessionHas('success');
        $this->assertTrue($user->fresh()->is_admin);
        $this->assertTrue($user->fresh()->notify_email, 'new admins get notifications by default');

        $this->actingAs($admin)->put(route('users.update', $admin), ['is_admin' => '0'])->assertSessionHas('error');
        $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertSessionHas('error');

        $this->actingAs($admin)->delete(route('users.destroy', $user))->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        // Last admin cannot be demoted by another (non-)admin path either.
        $other = User::factory()->create(['is_admin' => true]);
        $this->actingAs($other)->put(route('users.update', $admin), ['is_admin' => '0'])->assertSessionHas('success');
        $this->actingAs($admin->fresh())->put(route('users.update', $other), ['is_admin' => '0'])->assertForbidden();
        $this->actingAs($other)->delete(route('users.destroy', $other))->assertSessionHas('error');
    }
}
