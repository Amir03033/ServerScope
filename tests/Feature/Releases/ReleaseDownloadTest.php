<?php

namespace Tests\Feature\Releases;

use App\Models\User;
use App\Releases\ReleaseKeys;
use App\Services\AgentPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\SignedReleases;
use Tests\TestCase;

class ReleaseDownloadTest extends TestCase
{
    use RefreshDatabase;
    use SignedReleases;

    private array $key;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://monitor.example.test']);
        $this->key = $this->releaseKey();
        $this->trustReleaseKeys($this->key);
        config(['serverscope.releases.storage_path' => $this->releaseTempDir('storage')]);
        User::factory()->create(['email' => 'platform@example.test', 'is_platform_admin' => true]);
    }

    protected function tearDown(): void
    {
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    private function import(string $version, ?array $key = null): void
    {
        $this->artisan('serverscope:release:import', ['bundle' => $this->releaseBundle($version, $key ?? $this->key), '--admin' => 'platform@example.test'])->assertSuccessful();
    }

    public function test_manifest_signature_and_tarball_of_an_imported_release_are_served_unchanged(): void
    {
        $this->import('0.3.0');
        $release = \App\Models\AgentRelease::query()->sole();

        $manifest = $this->get(route('agent.release.manifest', ['version' => '0.3.0']))->assertOk();
        $this->assertSame($release->manifest, $manifest->getContent());
        $this->assertStringStartsWith('text/plain', $manifest->headers->get('Content-Type'));

        $signature = $this->get(route('agent.release.signature', ['version' => '0.3.0']))->assertOk()->getContent();
        $this->assertSame($release->signature."\n", $signature);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode(trim($signature)), $release->manifest, $this->key['public']));

        $tarball = $this->get(route('agent.release.tarball', ['version' => '0.3.0', 'file' => 'serverscope-agent-0.3.0.tar.gz']))->assertOk();
        $this->assertSame($release->sha256, hash('sha256', $tarball->getContent()));
        $this->assertSame('application/gzip', $tarball->headers->get('Content-Type'));
    }

    public function test_unknown_and_withdrawn_releases_are_not_served(): void
    {
        $this->import('0.3.0');

        foreach (['manifest', 'manifest.sig', 'serverscope-agent-0.3.1.tar.gz'] as $file) {
            $this->get("/agent/releases/0.3.1/{$file}")->assertNotFound();
        }

        $this->get('/agent/releases/0.3/manifest')->assertNotFound();
        $this->get('/agent/releases/0.3.0/install.sh')->assertNotFound();
        $this->get('/agent/releases/0.3.0/serverscope-agent-0.2.0.tar.gz')->assertNotFound();

        $this->artisan('serverscope:release:withdraw', ['version' => '0.3.0', '--admin' => 'platform@example.test', '--reason' => 'test'])->assertSuccessful();

        foreach (['manifest', 'manifest.sig', 'serverscope-agent-0.3.0.tar.gz'] as $file) {
            $this->get("/agent/releases/0.3.0/{$file}")->assertNotFound();
        }
    }

    public function test_install_script_embeds_the_latest_release_and_its_public_key(): void
    {
        $this->import('0.3.0');
        $this->import('0.3.1');

        $body = $this->get(route('agent.install-script'))->assertOk()->getContent();

        $this->assertStringContainsString("AGENT_VERSION='0.3.1'", $body);
        $this->assertStringContainsString(trim($this->key['pem']), $body);
        $this->assertStringContainsString("RELEASE_KEY_FINGERPRINT='".ReleaseKeys::fingerprint($this->key['public'])."'", $body);
        $this->assertStringNotContainsString('__RELEASE_PUBLIC_KEY__', $body);
        $this->assertStringNotContainsString('__AGENT_VERSION__', $body);
    }

    public function test_install_script_without_a_release_is_served_but_installs_nothing(): void
    {
        $body = $this->get(route('agent.install-script'))->assertOk()->getContent();

        $this->assertStringContainsString("AGENT_VERSION=''", $body);
        $this->assertStringContainsString('Er is nog geen ondertekende agentrelease beschikbaar', $body);
    }

    public function test_no_route_builds_or_serves_an_unsigned_package(): void
    {
        // Any use of the on-the-fly package builder fails this test.
        $this->app->bind(AgentPackage::class, fn () => throw new \LogicException('AgentPackage must not be used by a route.'));
        $this->import('0.3.0');

        $paths = ['/install.sh', '/agent/serverscope-agent-0.3.0.tar.gz', '/agent/serverscope-agent-0.3.0.tar.gz.sha256', '/agent/serverscope-agent-0.2.0.tar.gz',
            '/agent/releases/0.3.0/manifest', '/agent/releases/0.3.0/manifest.sig', '/agent/releases/0.3.0/serverscope-agent-0.3.0.tar.gz'];

        foreach ($paths as $path) {
            $this->assertContains($this->get($path)->getStatusCode(), [200, 404], $path);
        }

        // And no code outside the class itself refers to it.
        $users = [];

        foreach (array_merge(File::allFiles(app_path()), File::allFiles(base_path('routes'))) as $file) {
            if ($file->getRealPath() !== app_path('Services/AgentPackage.php') && str_contains($file->getContents(), 'AgentPackage')) {
                $users[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $users);
        $this->assertGreaterThan(0, count(Route::getRoutes()));
    }
}
