<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The rebuilt domain and website pages: section navigation with working
 * anchors, mobile cards, no inline scripts or styles, and both languages.
 */
class DomainPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function domain(): Domain
    {
        return Domain::query()->create(['name' => 'example.com']);
    }

    private function page(Domain $domain, string $path): MonitoredPage
    {
        $url = 'https://example.com'.$path;

        return MonitoredPage::query()->create(['domain_id' => $domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url)]);
    }

    public function test_every_section_link_on_the_domain_page_points_at_exactly_one_target(): void
    {
        $domain = $this->domain();
        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('domains.show', $domain))->assertOk()->getContent();

        $sections = ['availability', 'certificate', 'dns', 'incidents', 'checks', 'history'];

        foreach ($sections as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "id=\"{$id}\" must exist once");
        }

        preg_match('/<nav class="ss-section-nav"[^>]*>(.*?)<\/nav>/s', $html, $nav);
        preg_match_all('/href="#([a-z-]+)"/', $nav[1], $links);
        $this->assertSame($sections, $links[1]);

        // The check filter still lands on the existing #checks anchor.
        $this->assertStringContainsString(route('domains.show', ['domain' => $domain, 'type' => 'http']).'#checks', $html);
    }

    public function test_domain_and_website_screens_are_english_by_default(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'locale' => 'en']);
        $domain = $this->domain();
        $page = $this->page($domain, '/pricing');
        app()->setLocale('en');

        $this->actingAs($user)->get(route('domains.index'))->assertOk()
            ->assertSee(['Availability, certificates, DNS and registration', 'Add domain', 'Last check'])
            ->assertDontSee(['Beschikbaarheid, certificaten', 'Domein toevoegen', 'Laatste controle']);

        $this->actingAs($user)->get(route('domains.show', $domain))->assertOk()
            ->assertSee(['HTTP availability', 'SSL/TLS certificate', 'Domain registration', 'DNS records', 'Check history', 'Website &amp; pages'], false)
            ->assertDontSee(['HTTP-beschikbaarheid', 'Domeinregistratie', 'Controlegeschiedenis', 'Website &amp; pagina'], false);

        $this->actingAs($user)->get(route('domains.create'))->assertOk()->assertSee(['Domain name *', 'Thresholds', 'Maintenance window'])->assertDontSee('Faaldrempel');
        $this->actingAs($user)->get(route('domains.edit', $domain))->assertOk()->assertSee(['Edit domain', 'Delete domain'])->assertDontSee('Domein verwijderen');

        $this->actingAs($user)->get(route('websites.pages', $domain))->assertOk()
            ->assertSee(['Monitored pages', 'Bulk action for selected pages', 'Awaiting approval'])
            ->assertDontSee(['Gemonitorde pagina', 'Bulkactie', 'Wacht op goedkeuring']);
        $this->actingAs($user)->get(route('websites.discovery', $domain))->assertOk()->assertSee(['Discover now', 'No discovery run yet'])->assertDontSee('Nu ontdekken');
        $this->actingAs($user)->get(route('pages.show', $page))->assertOk()->assertSee(['Check history', 'Delete page'])->assertDontSee('Pagina verwijderen');
        $this->actingAs($user)->get(route('pages.edit', $page))->assertOk()->assertSee(['Edit page', 'Content and headers'])->assertDontSee('Inhoud en headers');
        $this->actingAs($user)->get(route('websites.pages.create', $domain))->assertOk()->assertSee('Add pages');
    }

    public function test_flash_messages_follow_the_language(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'locale' => 'en']);
        $domain = $this->domain();

        $this->actingAs($user)->post(route('domains.pause', $domain))->assertSessionHas('success', 'Monitoring paused. No checks run until you resume.');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('domains.resume', $domain))->assertSessionHas('success', 'Monitoring hervat.');
    }

    public function test_the_mobile_cards_have_labels_for_every_value_cell(): void
    {
        $domain = $this->domain();
        $this->page($domain, '/pricing');
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ([route('domains.index'), route('websites.pages', $domain)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);
            preg_match_all('/<td([^>]*)>/', $body[1], $cells);
            $this->assertNotEmpty($cells[1]);

            foreach ($cells[1] as $attributes) {
                $this->assertTrue(str_contains($attributes, 'data-label=') || str_contains($attributes, 'is-primary') || str_contains($attributes, 'is-actions'), "Cell without a label on {$url}: <td{$attributes}>");
            }
        }
    }

    public function test_the_screens_have_no_inline_scripts_or_styles(): void
    {
        $domain = $this->domain();
        $page = $this->page($domain, '/pricing');
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ([route('domains.index'), route('domains.show', $domain), route('domains.create'), route('domains.edit', $domain), route('websites.pages', $domain), route('websites.discovery', $domain), route('pages.show', $page), route('pages.edit', $page), route('websites.pages.create', $domain)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $main = substr($html, strpos($html, '<main'));

            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $main, "Inline style on {$url}");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*type="application\/json")[^>]*>/', $main, "Inline script on {$url}");
        }
    }

    public function test_bulk_delete_asks_for_confirmation_through_the_shared_dialog(): void
    {
        $domain = $this->domain();
        $this->page($domain, '/pricing');

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('websites.pages', $domain))->assertOk()
            ->assertSee('data-bulk-form', false)
            ->assertSee('data-delete-confirm="Geselecteerde pagina&#039;s en hun controlegeschiedenis definitief verwijderen?"', false);
    }
}
