<?php

namespace Tests\Feature\Plans;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'N', 'team' => 'Co', 'email' => 'a@example.test', 'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x'], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['serverscope.tenancy.registration_enabled' => true]);
    }

    public function test_without_urls_nothing_changes(): void
    {
        $this->get('/register')->assertOk()->assertDontSee('data-testid="terms-checkbox"', false);
        $this->post('/register', $this->payload())->assertRedirect(route('register.sent'))->assertSessionHasNoErrors();
        $this->assertNull(User::query()->sole()->terms_accepted_at);
    }

    public function test_with_both_urls_the_box_is_required_and_acceptance_is_stored(): void
    {
        config(['serverscope.legal.terms_url' => 'https://example.test/voorwaarden', 'serverscope.legal.privacy_url' => 'https://example.test/privacy', 'serverscope.legal.version' => '2026-11']);

        $this->get('/register')->assertOk()->assertSee('data-testid="terms-checkbox"', false)->assertSee('https://example.test/voorwaarden', false);
        $this->post('/register', $this->payload())->assertSessionHasErrors('terms');
        $this->assertSame(0, User::query()->count());

        $this->post('/register', $this->payload(['terms' => '1']))->assertRedirect(route('register.sent'));
        $user = User::query()->sole();
        $this->assertSame('2026-11', $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
    }

    public function test_one_url_alone_or_an_unsafe_url_does_not_enable_the_box(): void
    {
        config(['serverscope.legal.terms_url' => 'https://example.test/v', 'serverscope.legal.privacy_url' => null]);
        $this->get('/register')->assertDontSee('data-testid="terms-checkbox"', false);

        config(['serverscope.legal.privacy_url' => 'javascript:alert(1)']);
        $this->get('/register')->assertDontSee('data-testid="terms-checkbox"', false);
    }

    public function test_invited_new_people_accept_too(): void
    {
        config(['serverscope.legal.terms_url' => 'https://example.test/v', 'serverscope.legal.privacy_url' => 'https://example.test/p']);
        $team = Team::query()->create(['name' => 'A']);
        [, $token] = TeamInvitation::issue($team, 'kees@example.test', 'member', null, null);
        $form = ['name' => 'Kees', 'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x'];

        $this->post("/invitations/{$token}/register", $form)->assertSessionHasErrors('terms');
        $this->post("/invitations/{$token}/register", [...$form, 'terms' => '1'])->assertRedirect(route('dashboard'));
        $this->assertNotNull(User::query()->where('email', 'kees@example.test')->sole()->terms_accepted_at);
    }

    public function test_the_locale_placeholder_points_to_the_page_in_the_interface_language(): void
    {
        config(['serverscope.legal.terms_url' => 'https://info.example.test/{locale}/terms', 'serverscope.legal.privacy_url' => 'https://info.example.test/{locale}/privacy']);

        app()->setLocale('nl');
        $this->assertSame('https://info.example.test/nl/terms', \App\Support\Legal::termsUrl());
        app()->setLocale('en');
        $this->assertSame('https://info.example.test/en/privacy', \App\Support\Legal::privacyUrl());
    }
}
