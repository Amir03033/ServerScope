<?php

namespace App\Console\Commands;

use App\Models\AgentRelease;
use App\Models\User;
use App\Releases\InvalidRelease;
use App\Releases\ReleaseArchive;
use App\Releases\ReleaseKeys;
use App\Releases\ReleaseManifest;
use App\Releases\ReleaseVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Imports a release bundle made with scripts/release/build.sh. This is
 * the only way a release reaches ServerScope (there is no web route):
 * the signature is verified with the trusted public keys before anything
 * is stored. Even so, customer servers verify it again themselves with
 * their own copy of the key; this check only keeps bad releases out of
 * the dashboard.
 */
class ImportAgentRelease extends Command
{
    protected $signature = 'serverscope:release:import {bundle : Path of serverscope-agent-<version>.bundle.tar} {--admin= : E-mail address of the platform administrator doing the import}';

    protected $description = 'Import a signed agent release bundle';

    public function handle(ReleaseVerifier $verifier): int
    {
        $admin = self::platformAdmin((string) $this->option('admin'));

        if ($admin === null) {
            $this->error('Geef met --admin het e-mailadres van een platformbeheerder op.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('bundle');

        if (! is_file($path) || ! is_readable($path) || filesize($path) > ReleaseManifest::MAX_SIZE + 65536) {
            $this->error("Bundel {$path} bestaat niet, is niet leesbaar of is te groot.");

            return self::FAILURE;
        }

        try {
            $bundle = ReleaseArchive::bundle((string) file_get_contents($path));
            $manifest = $verifier->verify($bundle['manifest'], $bundle['signature']);

            if ($bundle['tarball_name'] !== $manifest->filename) {
                throw new InvalidRelease('bad_bundle', 'De tarball heeft niet de naam uit het manifest.');
            }

            $verifier->verifyTarball($manifest, $bundle['tarball']);

            if (AgentRelease::query()->where('version', $manifest->version)->exists()) {
                throw new InvalidRelease('duplicate', "Versie {$manifest->version} bestaat al; maak een nieuwe versie.");
            }

            $release = $this->store($manifest, trim($bundle['signature']), $bundle['tarball'], $admin);
        } catch (InvalidRelease $error) {
            $this->error('Geweigerd: '.$error->getMessage());
            Log::warning('agent_release.import_refused', ['reason' => $error->reason, 'admin' => $admin->id]);

            return self::FAILURE;
        }

        Log::info('agent_release.imported', ['version' => $release->version, 'sha256' => $release->sha256, 'key_id' => $release->key_id, 'commit' => $release->commit, 'admin' => $admin->id]);

        $this->info("Release {$release->version} geïmporteerd.");
        $this->line("  sha256:     {$release->sha256}");
        $this->line("  sleutel-ID: {$release->key_id}");
        $this->line("  commit:     {$release->commit}");
        $this->line('  datum:      '.$release->released_at->toIso8601ZuluString());

        return self::SUCCESS;
    }

    public static function platformAdmin(string $email): ?User
    {
        $email = strtolower(trim($email));

        return $email === '' ? null : User::query()->where('email', $email)->where('is_platform_admin', true)->first();
    }

    private function store(ReleaseManifest $manifest, string $signature, string $tarball, User $admin): AgentRelease
    {
        $final = AgentRelease::directory($manifest->version);
        $temporary = $final.'.import-'.bin2hex(random_bytes(6));

        // A directory left by an earlier, interrupted import is replaced.
        if (is_dir($final)) {
            File::deleteDirectory($final);
        }

        File::ensureDirectoryExists($temporary, 0750);

        try {
            foreach (['manifest' => $manifest->raw, 'manifest.sig' => $signature."\n", $manifest->filename => $tarball] as $file => $bytes) {
                if (file_put_contents($temporary.'/'.$file, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException("Cannot write {$file}.");
                }
            }

            if (! rename($temporary, $final)) {
                throw new \RuntimeException("Cannot move the release to {$final}.");
            }

            [$major, $minor, $patch] = ReleaseManifest::versionParts($manifest->version);

            return AgentRelease::query()->create([
                'version' => $manifest->version,
                'version_major' => $major,
                'version_minor' => $minor,
                'version_patch' => $patch,
                'filename' => $manifest->filename,
                'sha256' => $manifest->sha256,
                'size' => $manifest->size,
                'commit' => $manifest->commit,
                'key_id' => $manifest->keyId,
                'released_at' => $manifest->releasedAt,
                'min_updater_version' => $manifest->minUpdaterVersion,
                'manifest' => $manifest->raw,
                'signature' => $signature,
                'imported_by' => $admin->id,
            ]);
        } catch (\Throwable $error) {
            File::deleteDirectory($temporary);
            File::deleteDirectory($final);

            throw $error;
        }
    }
}
