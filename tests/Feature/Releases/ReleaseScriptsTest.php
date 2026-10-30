<?php

namespace Tests\Feature\Releases;

use App\Models\AgentRelease;
use App\Models\User;
use App\Releases\ReleaseArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\SignedReleases;
use Tests\TestCase;

/**
 * scripts/release/ end to end, with a key pair made during the test:
 * keygen.sh → build.sh (from a tagged commit on origin/main) → import.
 */
class ReleaseScriptsTest extends TestCase
{
    use RefreshDatabase;
    use SignedReleases;

    private string $repo;

    private string $keys;

    private array $env;

    /** The agent version in the copied sources; the scripts build that version. */
    private string $version;

    protected function setUp(): void
    {
        parent::setUp();

        if (! str_starts_with((string) shell_exec('openssl version 2>/dev/null'), 'OpenSSL 3.') || trim((string) shell_exec('command -v git')) === '') {
            $this->markTestSkipped('OpenSSL 3 and git are needed.');
        }

        $root = $this->releaseTempDir('scripts');
        $this->repo = $root.'/repo';
        $this->keys = $root.'/keys';
        File::ensureDirectoryExists($this->keys, 0700);
        file_put_contents($root.'/pass', "test-wachtwoordzin-niet-echt\n");
        $this->env = [
            'SERVERSCOPE_RELEASE_PASSFILE' => $root.'/pass',
            'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.test',
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.test',
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        ];

        // A copy of the repository parts the scripts need, with a bare origin.
        $this->exec(['git', 'init', '-q', '--bare', $root.'/origin.git'], $root);
        $this->exec(['git', 'init', '-q', '-b', 'main', $this->repo], $root);
        foreach (['agent/serverscope_agent', 'agent/updater', 'scripts/release'] as $dir) {
            File::copyDirectory(base_path($dir), $this->repo.'/'.$dir);
        }
        File::deleteDirectory($this->repo.'/agent/serverscope_agent/__pycache__');
        File::copy(base_path('agent/requirements.txt'), $this->repo.'/agent/requirements.txt');
        File::copy(base_path('agent/serverscope-agent.service'), $this->repo.'/agent/serverscope-agent.service');
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'agent');
        $this->git('remote', 'add', 'origin', $root.'/origin.git');
        $this->git('push', '-q', 'origin', 'main');
        $this->assertSame(1, preg_match('/^AGENT_VERSION = "(\d+\.\d+\.\d+)"$/m', (string) file_get_contents($this->repo.'/agent/serverscope_agent/collector.py'), $match), 'AGENT_VERSION niet gevonden in collector.py');
        $this->version = $match[1];
        $this->git('tag', 'agent-v'.$this->version);

        $this->assertSame(0, $this->script('keygen.sh', [$this->keys])->getExitCode());

        config(['serverscope.releases.storage_path' => $this->releaseTempDir('storage')]);
        User::factory()->create(['email' => 'platform@example.test', 'is_platform_admin' => true]);
    }

    protected function tearDown(): void
    {
        $this->cleanReleaseDirs();

        parent::tearDown();
    }

    private function exec(array $command, string $cwd, array $env = []): Process
    {
        $process = new Process($command, $cwd, array_merge($this->env ?? [], $env), null, 120);
        $process->run();

        return $process;
    }

    private function git(string ...$args): string
    {
        $process = $this->exec(['git', ...$args], $this->repo);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return trim($process->getOutput());
    }

    private function script(string $name, array $args, array $env = []): Process
    {
        return $this->exec(['bash', $this->repo.'/scripts/release/'.$name, ...$args], $this->repo, $env);
    }

    private function build(array $env = []): Process
    {
        return $this->script('build.sh', ['--key', $this->keys.'/release-key.pem', '--pub', $this->keys.'/release.pub', '--out', $this->keys], $env);
    }

    public function test_keygen_makes_an_encrypted_key_and_never_overwrites_it(): void
    {
        $this->assertStringContainsString('-----BEGIN ENCRYPTED PRIVATE KEY-----', file_get_contents($this->keys.'/release-key.pem'));
        $this->assertSame('600', substr(sprintf('%o', fileperms($this->keys.'/release-key.pem')), -3));
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', file_get_contents($this->keys.'/release.pub'));

        $again = $this->script('keygen.sh', [$this->keys]);
        $this->assertNotSame(0, $again->getExitCode());
        $this->assertStringContainsString('nooit overschreven', $again->getErrorOutput());
    }

    public function test_a_built_bundle_verifies_and_imports(): void
    {
        $build = $this->build();
        $this->assertSame(0, $build->getExitCode(), $build->getErrorOutput());
        $commit = $this->git('rev-parse', 'HEAD');
        $this->assertStringContainsString($commit, $build->getOutput());
        $bundle = $this->keys.'/serverscope-agent-'.$this->version.'.bundle.tar';

        $verify = $this->script('verify.sh', [$bundle, $this->keys.'/release.pub']);
        $this->assertSame(0, $verify->getExitCode(), $verify->getErrorOutput());
        $this->assertStringContainsString('Geldig', $verify->getOutput());

        $other = $this->releaseKey();
        file_put_contents($this->keys.'/other.pub', $other['pem']);
        $this->assertNotSame(0, $this->script('verify.sh', [$bundle, $this->keys.'/other.pub'])->getExitCode());

        // ServerScope accepts it with the matching public key only.
        $trusted = $this->releaseTempDir('trusted');
        config(['serverscope.releases.key_path' => $trusted]);
        File::copy($this->keys.'/other.pub', $trusted.'/other.pub');
        $this->artisan('serverscope:release:import', ['bundle' => $bundle, '--admin' => 'platform@example.test'])->assertFailed();

        File::copy($this->keys.'/release.pub', $trusted.'/release.pub');
        $this->artisan('serverscope:release:import', ['bundle' => $bundle, '--admin' => 'platform@example.test'])->assertSuccessful();

        $release = AgentRelease::query()->sole();
        $this->assertSame([$this->version, $commit], [$release->version, $release->commit]);
        $entries = ReleaseArchive::entries(file_get_contents($release->tarballPath()));
        $this->assertContains('updater/serverscope-updater', $entries);
        $this->assertNotContains('serverscope_agent/__pycache__', $entries);
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('tests', $entry);
        }
    }

    public function test_build_refuses_anything_but_a_clean_tagged_commit_on_origin_main(): void
    {
        // Uncommitted change.
        file_put_contents($this->repo.'/agent/serverscope_agent/agent.py', "# changed\n", FILE_APPEND);
        $this->assertStringContainsString('niet zijn gecommit', $this->build()->getErrorOutput());
        $this->git('checkout', '--', 'agent/serverscope_agent/agent.py');

        // A commit without the tag.
        file_put_contents($this->repo.'/agent/requirements.txt', "# changed\n", FILE_APPEND);
        $this->git('commit', '-qam', 'untagged');
        $this->git('push', '-q', 'origin', 'main');
        $this->assertStringContainsString('heeft niet de tag agent-v'.$this->version, $this->build()->getErrorOutput());

        // Tagged, but not on origin/main.
        $this->git('tag', '-f', 'agent-v'.$this->version);
        file_put_contents($this->repo.'/agent/requirements.txt', "# local\n", FILE_APPEND);
        $this->git('commit', '-qam', 'local only');
        $this->git('tag', '-f', 'agent-v'.$this->version);
        $this->assertStringContainsString('niet gelijk aan origin/main', $this->build()->getErrorOutput());

        $this->assertFileDoesNotExist($this->keys.'/serverscope-agent-'.$this->version.'.bundle.tar');
    }

    public function test_libressl_is_refused_with_a_clear_message(): void
    {
        $fake = $this->releaseTempDir('libressl').'/openssl';
        file_put_contents($fake, "#!/bin/sh\necho 'LibreSSL 3.3.6'\n");
        chmod($fake, 0755);

        $build = $this->build(['SERVERSCOPE_OPENSSL' => $fake]);
        $this->assertNotSame(0, $build->getExitCode());
        $this->assertStringContainsString('LibreSSL', $build->getErrorOutput());
        $this->assertStringContainsString('brew install openssl@3', $build->getErrorOutput());
    }

    public function test_the_updater_and_release_scripts_pass_shellcheck(): void
    {
        $shellcheck = trim((string) shell_exec('command -v shellcheck'));

        if ($shellcheck === '') {
            $this->markTestSkipped('shellcheck is not installed.');
        }

        foreach (['agent/updater/serverscope-updater', 'scripts/release/common.sh', 'scripts/release/keygen.sh', 'scripts/release/build.sh', 'scripts/release/verify.sh'] as $file) {
            $process = new Process([$shellcheck, '-x', '--shell=bash', '--severity=style', '-P', 'SCRIPTDIR/../..', base_path($file)], base_path(), ['LC_ALL' => 'C.UTF-8']);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $file."\n".$process->getOutput());
        }
    }
}
