<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_share_the_application_shell(): void
    {
        $user = User::factory()->create();

        foreach (['/dashboard', '/alerts'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertOk()
                ->assertSee('css/serverscope.css', false)
                ->assertSee('Hoofdnavigatie')
                ->assertSee('Ingelogd als '.$user->email)
                ->assertSee('Uitloggen');
        }
    }

    public function test_admin_navigation_is_only_shown_to_admins(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($viewer)->get('/alerts')
            ->assertDontSee('Alertregels');

        $this->actingAs($admin)->get('/alerts')
            ->assertSee('Beheer')
            ->assertSee('Alertregels');
    }

    public function test_flash_messages_are_rendered_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->withSession(['success' => 'Opgeslagen!'])
            ->get('/alert-rules')
            ->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'Opgeslagen!'));
    }

    public function test_committed_frontend_build_matches_its_manifest(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);

        foreach ($manifest as $entry) {
            $this->assertFileExists(public_path('build/'.$entry['file']));
        }
    }

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        $response->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security');
    }
}
