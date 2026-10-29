<?php

namespace Tests\Feature\Releases;

use App\Models\AgentRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PharData;
use Tests\Support\SignedReleases;
use Tests\TestCase;

class ReleaseImportTest extends TestCase
{
    use RefreshDatabase;
    use SignedReleases;

    private array $key;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = $this->releaseKey();
        $this->trustReleaseKeys($this->key);
        config(['serverscope.releases.storage_path' => $this->releaseTempDir('storage')]);
        $this->platform = User::factory()->create(['email' => 'platform@example.test', 'is_platform_admin' => true]);
    }

    protected function tearDown(): void
    {
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    private function import(string $bundle, string $admin = 'platform@example.test'): int
    {
        return $this->artisan('serverscope:release:import', ['bundle' => $bundle, '--admin' => $admin])->run();
    }

    private function storedFiles(): array
    {
        return collect(File::allFiles(config('serverscope.releases.storage_path')))->map->getRelativePathname()->sort()->values()->all();
    }

    public function test_a_valid_bundle_is_imported(): void
    {
        $this->artisan('serverscope:release:import', ['bundle' => $this->releaseBundle('0.3.0', $this->key), '--admin' => 'platform@example.test'])
            ->expectsOutputToContain('0.3.0')
            ->expectsOutputToContain($this->key['key_id'])
            ->expectsOutputToContain(str_repeat('ab', 20))
            ->assertSuccessful();

        $release = AgentRelease::query()->sole();
        $this->assertSame('0.3.0', $release->version);
        $this->assertSame($this->key['key_id'], $release->key_id);
        $this->assertSame(str_repeat('ab', 20), $release->commit);
        $this->assertSame($this->platform->id, $release->imported_by);
        $this->assertNull($release->withdrawn_at);
        $this->assertSame(['0.3.0/manifest', '0.3.0/manifest.sig', '0.3.0/serverscope-agent-0.3.0.tar.gz'], $this->storedFiles());
        $this->assertSame($release->sha256, hash_file('sha256', $release->tarballPath()));
        $this->assertSame($release->manifest, file_get_contents($release->path('manifest')));
    }

    public function test_an_invalid_signature_is_refused_and_nothing_is_stored(): void
    {
        $bundle = $this->releaseBundle('0.3.0', $this->key, ['signature' => base64_encode(random_bytes(64))]);

        $this->artisan('serverscope:release:import', ['bundle' => $bundle, '--admin' => 'platform@example.test'])
            ->expectsOutputToContain('handtekening')
            ->assertFailed();

        $this->assertSame(0, AgentRelease::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_release_signed_with_another_key_is_refused(): void
    {
        $this->assertSame(1, $this->import($this->releaseBundle('0.3.0', $this->releaseKey())));
        $this->assertSame(0, AgentRelease::query()->count());
    }

    public function test_a_changed_tarball_is_refused(): void
    {
        $tarball = $this->releaseTarball('0.3.0');
        $manifest = $this->releaseManifest('0.3.0', $tarball, $this->key['key_id']);
        $other = $this->releaseTarball('0.3.0', ['serverscope_agent/agent.py' => "import os; os.system('evil')\n"]);

        $bundle = $this->releaseBundle('0.3.0', $this->key, ['manifest' => $manifest, 'tarball' => $other]);

        $this->artisan('serverscope:release:import', ['bundle' => $bundle, '--admin' => 'platform@example.test'])
            ->expectsOutputToContain('SHA-256')
            ->assertFailed();
        $this->assertSame(0, AgentRelease::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_changed_manifest_is_refused(): void
    {
        $tarball = $this->releaseTarball('0.3.0');
        $manifest = $this->releaseManifest('0.3.0', $tarball, $this->key['key_id']);
        $signature = $this->signRelease($manifest, $this->key);

        $bundle = $this->releaseBundle('0.3.0', $this->key, ['manifest' => str_replace('min_updater_version=1', 'min_updater_version=2', $manifest), 'signature' => $signature, 'tarball' => $tarball]);

        $this->assertSame(1, $this->import($bundle));
        $this->assertSame(0, AgentRelease::query()->count());
    }

    public function test_a_bundle_with_extra_or_missing_files_is_refused(): void
    {
        $this->assertSame(1, $this->import($this->releaseBundle('0.3.0', $this->key, ['extra' => ['install.sh' => 'echo hi']])));

        $missing = $this->releaseTempDir('missing').'/x.bundle.tar';
        $tar = new PharData($missing);
        $tar->addFromString('manifest', 'x');
        unset($tar);
        $this->assertSame(1, $this->import($missing));

        $this->assertSame(1, $this->import('/does/not/exist.tar'));
        $this->assertSame(0, AgentRelease::query()->count());
    }

    public function test_the_same_version_cannot_be_imported_twice(): void
    {
        $this->assertSame(0, $this->import($this->releaseBundle('0.3.0', $this->key)));
        $sha = AgentRelease::query()->sole()->sha256;

        $this->artisan('serverscope:release:import', ['bundle' => $this->releaseBundle('0.3.0', $this->key), '--admin' => 'platform@example.test'])
            ->expectsOutputToContain('al geïmporteerd')
            ->assertFailed();
        $this->assertSame($sha, AgentRelease::query()->sole()->sha256);
    }

    public function test_only_a_platform_administrator_can_import(): void
    {
        $teamAdmin = User::factory()->admin()->create(['email' => 'teamadmin@example.test']);
        $this->assertFalse((bool) $teamAdmin->is_platform_admin);
        $bundle = $this->releaseBundle('0.3.0', $this->key);

        $this->assertSame(1, $this->import($bundle, 'teamadmin@example.test'));
        $this->assertSame(1, $this->import($bundle, 'nobody@example.test'));
        $this->assertSame(1, $this->import($bundle, ''));
        $this->assertSame(0, AgentRelease::query()->count());

        // There is no web route through which releases can be added.
        foreach (Route::getRoutes() as $route) {
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH'])) {
                $this->assertStringNotContainsString('release', $route->uri());
            }
        }
    }

    public function test_a_release_can_be_withdrawn_and_listed(): void
    {
        $this->import($this->releaseBundle('0.3.0', $this->key));
        $this->import($this->releaseBundle('0.3.1', $this->key));
        $this->assertSame('0.3.1', AgentRelease::latestAvailable()?->version);

        $this->artisan('serverscope:release:withdraw', ['version' => '0.3.1', '--admin' => 'teamadmin@example.test', '--reason' => 'x'])->assertFailed();
        $this->artisan('serverscope:release:withdraw', ['version' => '0.3.1', '--admin' => 'platform@example.test', '--reason' => 'Fout in de schijfmeting'])->assertSuccessful();

        $release = AgentRelease::query()->where('version', '0.3.1')->sole();
        $this->assertNotNull($release->withdrawn_at);
        $this->assertSame($this->platform->id, $release->withdrawn_by);
        $this->assertSame('Fout in de schijfmeting', $release->withdraw_reason);
        $this->assertSame('0.3.0', AgentRelease::latestAvailable()?->version);

        $this->artisan('serverscope:release:list')->expectsOutputToContain('teruggetrokken')->assertSuccessful();
    }

    public function test_versions_are_ordered_numerically(): void
    {
        foreach (['0.9.0', '0.10.0', '0.3.2'] as $version) {
            $this->assertSame(0, $this->import($this->releaseBundle($version, $this->key)));
        }

        $this->assertSame('0.10.0', AgentRelease::latestAvailable()->version);
    }
}
