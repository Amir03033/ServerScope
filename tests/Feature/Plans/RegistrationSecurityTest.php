<?php

namespace Tests\Feature\Plans;

use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RegistrationAttemptNotice;
use App\Tenancy\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $email): array
    {
        return ['name' => 'N', 'team' => 'Co', 'email' => $email, 'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['serverscope.tenancy.registration_enabled' => true]);
    }

    public function test_registration_sends_verification_and_does_not_sign_in(): void
    {
        Notification::fake();
        $this->post('/register', $this->payload('a@example.test'))->assertRedirect(route('register.sent'));

        $this->assertGuest();
        $user = User::query()->where('email', 'a@example.test')->sole();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_user_cannot_use_the_app_until_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/servers')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_verification_link_needs_valid_signature_and_hash(): void
    {
        $user = User::factory()->unverified()->create();
        $this->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();

        $bad = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => 'wrong']);
        $this->get($bad)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_existing_and_new_addresses_get_the_same_answer(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'taken@example.test']);

        $a = $this->post('/register', $this->payload('taken@example.test'));
        $b = $this->post('/register', $this->payload('fresh@example.test'));

        $a->assertRedirect(route('register.sent'))->assertSessionHasNoErrors();
        $b->assertRedirect(route('register.sent'))->assertSessionHasNoErrors();
        Notification::assertSentTo($existing, RegistrationAttemptNotice::class);
        $this->assertSame(1, User::query()->where('email', 'taken@example.test')->count());
        $this->assertSame(1, Team::query()->where('name', 'Co')->count(), 'only the new address got a team');
    }

    public function test_the_same_domain_name_can_exist_in_two_teams(): void
    {
        $a = Team::query()->create(['name' => 'A']);
        $b = Team::query()->create(['name' => 'B']);
        foreach ([$a, $b] as $team) {
            Tenant::run($team, fn () => Domain::query()->create(['name' => 'shared.example.com', 'scheme' => 'https', 'path' => '/', 'check_interval_minutes' => 5]));
        }
        $this->assertSame(2, Domain::query()->withoutGlobalScopes()->where('name', 'shared.example.com')->count());
    }
}
