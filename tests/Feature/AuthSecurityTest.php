<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_matches_rfc_6238_vectors(): void
    {
        // RFC 6238 appendix B (SHA1), truncated to 6 digits.
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code($secret, $time));
        }

        $this->assertSame(intdiv(59, 30), Totp::verify($secret, '287 082', 59));
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, 59 - 30), 59), 'one step of drift');
        $this->assertNull(Totp::verify($secret, Totp::code($secret, 59 - 90), 59));
        $this->assertNull(Totp::verify($secret, 'abcdef', 59));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
    }

    public function test_password_reset_does_not_reveal_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'beheer@example.com']);

        $known = $this->post(route('password.email'), ['email' => 'Beheer@example.com'])->assertSessionHas('status')->getSession()->get('status');
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('status')->getSession()->get('status');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class);

        $mail = (new ResetPassword('token123'))->toMail($user);
        $this->assertStringContainsString('Wachtwoord opnieuw instellen', $mail->subject);
    }

    public function test_password_can_be_reset_with_a_valid_token_only(): void
    {
        $user = User::factory()->create(['email' => 'beheer@example.com']);
        $token = Password::createToken($user);

        $this->post(route('password.update'), ['token' => 'wrong', 'email' => 'beheer@example.com', 'password' => 'NieuwWachtwoord1', 'password_confirmation' => 'NieuwWachtwoord1'])
            ->assertSessionHasErrors('email');

        $this->post(route('password.update'), ['token' => $token, 'email' => 'beheer@example.com', 'password' => 'zwak', 'password_confirmation' => 'zwak'])
            ->assertSessionHasErrors('password');

        $this->post(route('password.update'), ['token' => $token, 'email' => 'beheer@example.com', 'password' => 'NieuwWachtwoord1', 'password_confirmation' => 'NieuwWachtwoord1'])
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NieuwWachtwoord1', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset']);

        // Tokens are single use.
        $this->post(route('password.update'), ['token' => $token, 'email' => 'beheer@example.com', 'password' => 'AnderWachtwoord2', 'password_confirmation' => 'AnderWachtwoord2'])
            ->assertSessionHasErrors('email');
    }

    public function test_password_reset_requests_are_throttled(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('password.email'), ['email' => 'x@example.com'])->assertSessionHas('status');
        }

        $this->post(route('password.email'), ['email' => 'x@example.com'])->assertStatus(429);
    }

    private function enableTwoFactor(User $user): string
    {
        $this->actingAs($user)->post(route('two-factor.enable'))->assertRedirect(route('profile.edit'));
        $secret = $user->fresh()->two_factor_secret;

        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => Totp::code($secret)])->assertSessionHas('recovery_codes');

        return $secret;
    }

    public function test_two_factor_setup_stores_secrets_securely(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Wachtwoord123')]);

        $this->actingAs($user)->post(route('two-factor.enable'));
        $secret = $user->fresh()->two_factor_secret;
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled(), 'not enabled until confirmed');

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);

        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrorsIn('twoFactor', 'code');

        $response = $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => Totp::code($secret)]);
        $codes = $response->getSession()->get('recovery_codes');

        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertNotContains($codes[0], $user->fresh()->two_factor_recovery_codes, 'only hashes are stored');
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());
    }

    public function test_login_requires_the_second_factor(): void
    {
        $user = User::factory()->create(['email' => 'tfa@example.com', 'password' => Hash::make('Wachtwoord123')]);
        $secret = $this->enableTwoFactor($user);
        auth()->logout();
        $this->travel(2)->minutes(); // new TOTP step

        $this->post(route('login'), ['email' => 'tfa@example.com', 'password' => 'Wachtwoord123'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect('/login');

        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $code = Totp::code($secret);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Replaying the same code in a new login fails.
        auth()->logout();
        $this->post(route('login'), ['email' => 'tfa@example.com', 'password' => 'Wachtwoord123']);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_codes_work_once_and_failures_are_throttled(): void
    {
        $user = User::factory()->create(['email' => 'tfa@example.com', 'password' => Hash::make('Wachtwoord123')]);
        $this->actingAs($user)->post(route('two-factor.enable'));
        $secret = $user->fresh()->two_factor_secret;
        $codes = $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => Totp::code($secret)])->getSession()->get('recovery_codes');
        auth()->logout();

        $this->post(route('login'), ['email' => 'tfa@example.com', 'password' => 'Wachtwoord123']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => strtoupper($codes[0])])->assertRedirect(route('dashboard'));
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);

        auth()->logout();
        $this->post(route('login'), ['email' => 'tfa@example.com', 'password' => 'Wachtwoord123']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');

        foreach (range(1, 4) as $i) {
            $this->post(route('two-factor.challenge.store'), ['code' => '000000']);
        }
        $response = $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[1]])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Te veel pogingen', $response->getSession()->get('errors')->first('code'));
        $this->assertGuest();
    }

    public function test_disabling_requires_password_and_challenge_requires_pending_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Wachtwoord123')]);
        $this->enableTwoFactor($user);

        $this->actingAs($user)->delete(route('two-factor.show'), ['current_password' => 'fout'])->assertSessionHasErrorsIn('twoFactor', 'current_password');
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->actingAs($user)->delete(route('two-factor.show'), ['current_password' => 'Wachtwoord123'])->assertSessionHas('success');
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->two_factor_secret);

        auth()->logout();
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_login_without_two_factor_still_works(): void
    {
        User::factory()->create(['email' => 'plain@example.com', 'password' => Hash::make('Wachtwoord123')]);

        $this->post(route('login'), ['email' => 'plain@example.com', 'password' => 'Wachtwoord123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->get(route('login'))->assertRedirect();
    }
}
