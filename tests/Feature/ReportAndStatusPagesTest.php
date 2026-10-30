<?php

namespace Tests\Feature;

use App\Models\StatusPage;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports, the print version and status pages after the rebuild: a readable
 * text colour for every customer accent, a pausable refresh, no inline code
 * and both languages.
 */
class ReportAndStatusPagesTest extends TestCase
{
    use RefreshDatabase;

    private function statusPage(string $accent): StatusPage
    {
        return StatusPage::forceCreate(['team_id' => Tenant::id() ?? \App\Models\Team::defaultTeam()->id, 'name' => 'Demo status', 'slug' => 'demo-'.ltrim($accent, '#'), 'is_public' => true, 'show_uptime' => true, 'accent_color' => $accent]);
    }

    public function test_a_pale_accent_is_only_used_where_it_is_readable(): void
    {
        $html = $this->get(route('status.show', $this->statusPage('#38bdf8')->slug))->assertOk()->getContent();

        // Light blue: unreadable as text on white, fine on the dark surface; black text on it.
        $this->assertStringContainsString('data-accent="#38bdf8"', $html);
        $this->assertStringContainsString('data-on-accent="#000000"', $html);
        $this->assertStringContainsString('data-accent-dark="#38bdf8"', $html);
        $this->assertStringNotContainsString('data-accent-light=', $html);
    }

    public function test_a_dark_accent_gets_white_text_and_is_used_in_light_only(): void
    {
        $html = $this->get(route('status.show', $this->statusPage('#1e3a8a')->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-on-accent="#ffffff"', $html);
        $this->assertStringContainsString('data-accent-light="#1e3a8a"', $html);
        $this->assertStringNotContainsString('data-accent-dark=', $html);
    }

    public function test_the_public_page_refreshes_pausably_and_has_no_inline_code(): void
    {
        $html = $this->get(route('status.show', $this->statusPage('#2f5fd0')->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-auto-refresh="60"', $html);
        // Without JavaScript the old minute refresh still works; with JavaScript it can be paused.
        $this->assertStringContainsString('<noscript><meta http-equiv="refresh" content="60"></noscript>', $html);
        $this->assertSame(1, substr_count($html, 'http-equiv="refresh"'));
        $this->assertDoesNotMatchRegularExpression('/<style|\sstyle="|\son[a-z]+="/', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*(type="application\/json"|src=|type="module"))[^>]*>/', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
    }

    public function test_the_print_version_is_light_and_has_no_inline_code(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(route('reports.print'))->assertOk()
            ->assertSee('Beschikbaarheidsrapport')->assertSee('Afdrukken / opslaan als PDF')
            ->getContent();

        $this->assertStringContainsString('data-theme="light"', $html);
        $this->assertStringContainsString('data-print', $html);
        $this->assertStringNotContainsString('css/serverscope.css', $html);
        $this->assertDoesNotMatchRegularExpression('/<style|\sstyle="|\son[a-z]+="/', $html);
    }

    public function test_reports_and_status_page_management_are_english_by_default(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'locale' => 'en']);
        $page = $this->statusPage('#38bdf8');
        app()->setLocale('en');

        $this->actingAs($user)->get(route('reports.index'))->assertOk()
            ->assertSee(['Availability calculated from monitoring data', 'Monitoring coverage', 'Recurring problems', 'Show report'])
            ->assertDontSee(['Monitoringdekking', 'Terugkerende problemen', 'Toon rapport']);
        $this->actingAs($user)->get(route('reports.print'))->assertOk()->assertSee(['Availability report', 'Print / save as PDF'])->assertDontSee('Beschikbaarheidsrapport');
        $this->actingAs($user)->get(route('status-pages.index'))->assertOk()->assertSee(['Status pages', 'Create status page'])->assertDontSee('Statuspagina maken');
        $this->actingAs($user)->get(route('status-pages.edit', $page))->assertOk()
            ->assertSee(['Edit status page', 'Add component', 'too little contrast in light'])
            ->assertDontSee('Onderdeel toevoegen');
    }

    public function test_management_screens_have_no_inline_code(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $page = $this->statusPage('#38bdf8');

        foreach ([route('reports.index'), route('status-pages.index'), route('status-pages.create'), route('status-pages.edit', $page)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $main = substr($html, strpos($html, '<main'));

            $this->assertDoesNotMatchRegularExpression('/\sstyle="|\son[a-z]+="/', $main, "Inline style or handler on {$url}");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*type="application\/json")[^>]*>/', $main, "Inline script on {$url}");
        }
    }
}
