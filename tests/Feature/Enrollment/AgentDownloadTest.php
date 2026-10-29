<?php

namespace Tests\Feature\Enrollment;

use App\Models\User;
use App\Services\AgentPackage;
use App\Services\EnrollmentService;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\SignedReleases;
use Tests\TestCase;

/**
 * The agent package (tarball + SHA-256) and install.sh served by the app.
 */
class AgentDownloadTest extends TestCase
{
    use RefreshDatabase;
    use SignedReleases;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://monitor.example.test']);
        $this->work = sys_get_temp_dir().'/serverscope-agent-test-'.bin2hex(random_bytes(4));
        File::copyDirectory(base_path('agent'), $this->work.'/agent');
        File::deleteDirectory($this->work.'/agent/serverscope_agent/__pycache__');
        config(['serverscope.agent.source_path' => $this->work.'/agent', 'serverscope.agent.cache_path' => $this->work.'/cache']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    /** @return array<int, string> */
    private function entries(string $tarball): array
    {
        $process = new Process(['tar', '-tzf', $tarball]);
        $process->mustRun();

        return array_values(array_filter(explode("\n", trim($process->getOutput()))));
    }

    public function test_the_package_contains_exactly_the_agent_files_in_the_installed_layout(): void
    {
        $package = app(AgentPackage::class);

        $this->assertSame('0.3.0', $package->version());
        $entries = $this->entries($package->path());
        sort($entries);

        $this->assertSame([
            'requirements.txt',
            'serverscope-agent.service',
            'serverscope_agent/__init__.py',
            'serverscope_agent/agent.py',
            'serverscope_agent/client.py',
            'serverscope_agent/collector.py',
            'serverscope_agent/config.py',
            'serverscope_agent/services.py',
        ], $entries);
    }

    /**
     * Phase 3: the old URLs only serve imported, signed releases (adapted
     * with the owner's consent; it used to expect a tarball built on the
     * server). At least as strict as before: tarball and checksum must
     * match the signed manifest exactly, everything else is a 404.
     */
    public function test_download_and_checksum_match(): void
    {
        $key = $this->releaseKey();
        $this->trustReleaseKeys($key);
        config(['serverscope.releases.storage_path' => $this->releaseTempDir('storage')]);
        User::factory()->create(['email' => 'platform@example.test', 'is_platform_admin' => true]);
        $this->artisan('serverscope:release:import', ['bundle' => $this->releaseBundle('0.3.0', $key), '--admin' => 'platform@example.test'])->assertSuccessful();
        $release = \App\Models\AgentRelease::query()->sole();
        $manifest = \App\Releases\ReleaseManifest::parse($release->manifest);

        $tarball = $this->get(route('agent.package', ['version' => '0.3.0']))->assertOk();
        $this->assertSame('application/gzip', $tarball->headers->get('Content-Type'));
        $body = $tarball->getContent();
        $this->assertSame($manifest->sha256, hash('sha256', $body));
        $this->assertSame($manifest->size, strlen($body));

        $checksum = $this->get(route('agent.checksum', ['version' => '0.3.0']))->assertOk()->getContent();
        $this->assertSame($manifest->sha256.'  serverscope-agent-0.3.0.tar.gz'."\n", $checksum);

        // A version that was never imported (such as 0.2.0, which used to be
        // built on the server): 404 with an explanation.
        foreach (['agent.package', 'agent.checksum'] as $route) {
            $this->get(route($route, ['version' => '0.2.0']))->assertNotFound()->assertSee('Deze versie wordt niet meer aangeboden; download install.sh opnieuw');
            $this->get(route($route, ['version' => '9.9.9']))->assertNotFound();
        }

        // Invalid versions do not reach the controller at all.
        $this->get('/agent/serverscope-agent-0.3.tar.gz')->assertNotFound();
        $this->get('/agent/serverscope-agent-..%2F0.3.0.tar.gz')->assertNotFound();
        $this->get('/agent/serverscope-agent-0.3.0.tar.gz.sha256x')->assertNotFound();

        // A withdrawn release is no longer served either.
        $this->artisan('serverscope:release:withdraw', ['version' => '0.3.0', '--admin' => 'platform@example.test', '--reason' => 'test'])->assertSuccessful();
        $this->get(route('agent.package', ['version' => '0.3.0']))->assertNotFound();
        $this->get(route('agent.checksum', ['version' => '0.3.0']))->assertNotFound();
    }

    public function test_the_package_is_reproducible_and_rebuilt_when_the_code_changes(): void
    {
        $package = app(AgentPackage::class);
        $first = $package->path();
        $firstSum = $package->checksum();

        // Same sources, even after the cache is cleared: identical bytes.
        File::deleteDirectory($this->work.'/cache');
        $this->assertSame($firstSum, app(AgentPackage::class)->checksum());

        // Changed code with the same version number: new tarball and checksum.
        File::append($this->work.'/agent/serverscope_agent/client.py', "\n# changed\n");
        $changed = app(AgentPackage::class);

        $this->assertNotSame($first, $changed->path());
        $this->assertNotSame($firstSum, $changed->checksum());
        $this->assertSame(hash_file('sha256', $changed->path()), $changed->checksum());
    }

    public function test_the_package_is_written_atomically(): void
    {
        $package = app(AgentPackage::class);
        $path = $package->path();

        // No temporary files are left behind, and a stale partial file under
        // the final name is never served.
        $this->assertSame([basename($path)], array_map('basename', glob($this->work.'/cache/*.tar.gz')));
        $this->assertSame([], glob($this->work.'/cache/*.tmp*'));
        file_put_contents($path, 'kapot');
        $this->assertSame(hash_file('sha256', app(AgentPackage::class)->path()), app(AgentPackage::class)->checksum());
        $this->assertNotSame('kapot', file_get_contents(app(AgentPackage::class)->path()));
    }

    public function test_install_script_uses_app_url_and_https_and_holds_no_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        [, $plain] = Tenant::run($admin->current_team_id, fn () => app(EnrollmentService::class)->create($admin));

        $script = $this->withHeader('Host', 'evil.example.net')->get(route('agent.install-script'))->assertOk();
        $this->assertStringStartsWith('text/plain', $script->headers->get('Content-Type'));
        $body = $script->getContent();

        $this->assertStringStartsWith("#!/usr/bin/env bash\n", $body);
        $this->assertStringContainsString('set -euo pipefail', $body);
        $this->assertStringContainsString("SERVERSCOPE_URL_DEFAULT='https://monitor.example.test'", $body);
        $this->assertStringContainsString("--proto '=https' --tlsv1.2", $body);
        $this->assertStringNotContainsString('evil.example.net', $body);
        $this->assertStringNotContainsString($plain, $body);
        $this->assertStringNotContainsString('EnvironmentFile', $body);
        $this->assertStringContainsString('/opt/serverscope-agent', $body);
    }

    public function test_install_script_is_refused_for_an_http_app_url(): void
    {
        config(['app.url' => 'http://monitor.example.test', 'serverscope.enrollment.allow_insecure_url' => false]);

        $this->get(route('agent.install-script'))->assertStatus(503)->assertSee('APP_URL');
    }

    public function test_install_script_passes_shellcheck(): void
    {
        $shellcheck = trim((string) shell_exec('command -v shellcheck'));

        if ($shellcheck === '') {
            $this->markTestSkipped('shellcheck is not installed.');
        }

        $file = $this->work.'/install.sh';
        file_put_contents($file, $this->get(route('agent.install-script'))->getContent());

        $process = new Process([$shellcheck, '--shell=bash', '--severity=style', $file]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput());
    }
}
