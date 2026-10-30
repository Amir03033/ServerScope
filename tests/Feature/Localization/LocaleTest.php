<?php

namespace Tests\Feature\Localization;

use App\Http\Middleware\SetLocale;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\Server;
use App\Models\StatusPage;
use App\Models\User;
use App\Support\Format;
use App\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * English is the default; Dutch is a choice per user (users.locale) or per
 * visitor (session, and the browser language on public status pages).
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected string $locale = 'en';

    public function test_new_users_and_guests_get_english_by_default(): void
    {
        $user = User::factory()->create();
        $this->assertSame('en', $user->fresh()->locale);

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Main navigation')
            ->assertSee('Signed in as '.$user->email)
            ->assertSee('Log out')
            ->assertDontSee('Uitloggen');

        auth()->logout();
        $this->get('/login')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Welcome back')
            ->assertDontSee('Welkom terug');
    }

    public function test_the_migration_gives_existing_accounts_english(): void
    {
        $column = collect(\Illuminate\Support\Facades\Schema::getColumns('users'))->firstWhere('name', 'locale');

        $this->assertNotNull($column);
        $this->assertFalse($column['nullable']);
        $this->assertSame('en', trim((string) $column['default'], "'\""));

        $id = \Illuminate\Support\Facades\DB::table('users')->insertGetId(['name' => 'Bestaand', 'email' => 'oud@example.test', 'password' => 'x']);
        $this->assertSame('en', \Illuminate\Support\Facades\DB::table('users')->where('id', $id)->value('locale'));
    }

    public function test_a_user_chooses_dutch_in_the_profile_and_keeps_it_after_logging_in_again(): void
    {
        $user = User::factory()->create(['password' => bcrypt('Geheim-wachtwoord-123')]);

        $this->actingAs($user)->put(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'nl'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Profiel opgeslagen.');
        $this->assertSame('nl', $user->fresh()->locale);

        $this->post(route('logout'));
        $this->flushSession();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Geheim-wachtwoord-123']);

        $this->get('/dashboard')->assertOk()->assertSee('<html lang="nl">', false)->assertSee('Uitloggen');
    }

    public function test_the_profile_rejects_unsupported_languages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'locale' => 'de'])
            ->assertSessionHasErrors('locale');
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_the_quick_switch_stores_the_choice_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/servers?status=online')->post(route('locale.update'), ['locale' => 'nl'])
            ->assertRedirect('/servers?status=online')
            ->assertSessionHas('locale', 'nl');
        $this->assertSame('nl', $user->fresh()->locale);

        $this->actingAs($user->fresh())->get('/dashboard')->assertSee('<html lang="nl">', false);
    }

    public function test_the_quick_switch_stores_the_choice_in_the_session_for_guests(): void
    {
        $this->from('/login')->post(route('locale.update'), ['locale' => 'nl'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'nl');

        $this->get('/login')->assertSee('Welkom terug')->assertSee('<html lang="nl">', false);
    }

    public function test_the_quick_switch_only_accepts_english_and_dutch(): void
    {
        foreach (['de', '', 'EN', '../nl', ['nl']] as $invalid) {
            $this->post(route('locale.update'), ['locale' => $invalid])->assertSessionHasErrors('locale');
        }

        $this->assertNull(session('locale'));
    }

    public function test_the_quick_switch_never_redirects_to_another_site(): void
    {
        $user = User::factory()->create();

        foreach ([
            'https://evil.example/phish',
            'http://localhost.evil.example/x',
            '//evil.example/x',
            'javascript:alert(1)',
            url('/').':1/x',
            str_replace('http://', 'https://', url('/x')),
        ] as $referer) {
            $this->actingAs($user)->withHeader('referer', $referer)->post(route('locale.update'), ['locale' => 'nl'])
                ->assertRedirect(route('dashboard', absolute: false));
        }

        // A path on this site that looks like a host stays a path on this site.
        $this->actingAs($user)->withHeader('referer', url('/').'//evil.example/x')->post(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect('/evil.example/x');

        auth()->logout();
        $this->withHeader('referer', 'https://evil.example/')->post(route('locale.update'), ['locale' => 'nl'])
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_the_quick_switch_is_csrf_protected(): void
    {
        $route = Route::getRoutes()->getByName('locale.update');

        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, app('router')->getMiddlewareGroups()['web']);
        $this->assertSame([], array_filter((new \ReflectionProperty(PreventRequestForgery::class, 'neverVerify'))->getValue(), fn ($uri) => str_contains($uri, 'locale')));
        $this->assertContains(SetLocale::class, app('router')->getMiddlewareGroups()['web']);
    }

    public function test_an_unsupported_session_value_falls_back_to_english(): void
    {
        $this->withSession(['locale' => 'de'])->get('/login')->assertSee('<html lang="en">', false);
    }

    public function test_validation_messages_follow_the_language(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), ['name' => '', 'email' => $user->email])
            ->assertSessionHasErrors(['name' => 'The name field is required.']);

        $user->forceFill(['locale' => 'nl'])->save();
        $this->actingAs($user->fresh())->put(route('profile.update'), ['name' => '', 'email' => $user->email])
            ->assertSessionHasErrors(['name' => 'Het veld naam is verplicht.']);
    }

    public function test_public_status_pages_follow_the_browser_language_and_cache_per_language(): void
    {
        $page = $this->statusPage();

        $this->withHeader('Accept-Language', 'nl-NL,nl;q=0.9,en;q=0.8')->get(route('status.show', $page->slug))
            ->assertOk()->assertSee('<html lang="nl">', false)->assertSee('Status onbekend')->assertSee('Onderdelen');

        // Same page, served from the cache right after: English must not get the Dutch copy.
        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get(route('status.show', $page->slug))
            ->assertOk()->assertSee('<html lang="en">', false)->assertSee('Status unknown')->assertSee('Components')
            ->assertDontSee('Status onbekend');

        $this->withHeader('Accept-Language', 'de-DE')->get(route('status.show', $page->slug))->assertSee('Status unknown');
    }

    public function test_the_status_page_cache_key_contains_the_language(): void
    {
        $page = $this->statusPage();
        \Illuminate\Support\Facades\Cache::flush();

        $this->withHeader('Accept-Language', 'nl')->get(route('status.show', $page->slug))->assertOk();
        $this->withHeader('Accept-Language', 'en')->get(route('status.show', $page->slug))->assertOk();

        $base = "status-page:{$page->id}:{$page->updated_at?->timestamp}";
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has($base.':nl'));
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has($base.':en'));
    }

    public function test_dashboard_and_system_health_caches_are_per_language(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        foreach (['nl', 'en'] as $locale) {
            app()->setLocale($locale);
            app(\App\Services\Reports\DashboardOverview::class)->data();
            app(\App\Services\SystemHealth::class)->summary();

            $this->assertTrue(\Illuminate\Support\Facades\Cache::has("dashboard:trends:all:{$locale}"));
            $this->assertTrue(\Illuminate\Support\Facades\Cache::has("serverscope:system-health:summary:platform:{$locale}"));
        }
    }

    public function test_a_visitors_choice_beats_the_browser_on_status_pages(): void
    {
        $page = $this->statusPage();

        $this->withSession(['locale' => 'nl'])->withHeader('Accept-Language', 'en')->get(route('status.show', $page->slug))
            ->assertSee('Status onbekend');
    }

    public function test_api_responses_do_not_depend_on_the_language(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        Server::create(['name' => 'web-01', 'hostname' => 'web-01.example.test', 'last_seen_at' => now()->subMinutes(5)]);
        Domain::create(['name' => 'example.com']);
        [, $plain] = ApiToken::issue($user, 'test', ['read']);

        $bodies = [];
        foreach (['en', 'nl'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            foreach (['/api/v1/servers', '/api/v1/domains', '/api/v1/servers/999999'] as $uri) {
                $bodies[$locale][$uri] = $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json', 'Accept-Language' => $locale])
                    ->get($uri)->getContent();
            }
        }

        $this->assertSame($bodies['en'], $bodies['nl']);
    }

    public function test_numbers_and_dates_follow_the_language(): void
    {
        $moment = CarbonImmutable::parse('2026-10-30 14:51:00', 'UTC');
        config(['serverscope.display_timezone' => 'UTC']);

        app()->setLocale('nl');
        $this->assertSame('1.234,5', Format::number(1234.5, 1));
        $this->assertSame('98,96%', Format::percent(98.96, 2));
        $this->assertSame('30-10-2026 14:51', Time::dateTime($moment));
        $this->assertSame('nooit', Time::relative(null));
        $this->assertStringContainsString('geleden', Time::relative(now()->subMinutes(3)));

        app()->setLocale('en');
        $this->assertSame('1,234.5', Format::number(1234.5, 1));
        $this->assertSame('30 Oct 2026, 14:51', Time::dateTime($moment));
        $this->assertSame('30 Oct 2026', Time::date($moment));
        $this->assertSame('never', Time::relative(null));
        $this->assertStringContainsString('ago', Time::relative(now()->subMinutes(3)));
        $this->assertSame('—', Format::number(null));
    }

    private function statusPage(): StatusPage
    {
        $team = \App\Models\Team::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $page = StatusPage::forceCreate(['team_id' => $team->id, 'name' => 'Demo status', 'slug' => 'demo-status', 'is_public' => true, 'accent_color' => '#2f5fd0']);

        return $page;
    }
}
