<?php

namespace App\Http\Controllers;

use App\Models\AgentRelease;
use App\Releases\ReleaseKeys;
use App\Support\AgentInstall;
use Illuminate\Http\Response;

/**
 * Public downloads for install.sh and the updater: the script itself
 * (readable in the browser before running it) and the files of imported,
 * signed releases. Nothing is built on the server. None of it contains a
 * secret or team data, and customer servers verify every release with
 * their own copy of the public key.
 */
class AgentDownloadController extends Controller
{
    private const GONE = "Deze versie wordt niet meer aangeboden; download install.sh opnieuw.\n";

    public function script(ReleaseKeys $keys): Response
    {
        if ($problem = AgentInstall::urlProblem()) {
            return response("# {$problem}\necho 'Koppelen is uitgeschakeld: APP_URL van ServerScope gebruikt geen https.' >&2\nexit 1\n", 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // The newest release whose key is still trusted; without one the
        // script is served, but refuses to change anything when run.
        $trusted = $keys->trusted();
        $release = AgentRelease::query()->available()->whereIn('key_id', array_keys($trusted))->newestFirst()->first();
        $public = $release ? $trusted[$release->key_id] : null;

        $script = strtr((string) file_get_contents(resource_path('agent/install.sh')), [
            '__SERVERSCOPE_URL__' => AgentInstall::baseUrl(),
            '__AGENT_VERSION__' => $release->version ?? '',
            '__RELEASE_PUBLIC_KEY__' => $public ? trim(ReleaseKeys::toPem($public)) : '',
            '__RELEASE_KEY_FINGERPRINT__' => $public ? ReleaseKeys::fingerprint($public) : '',
            '__ALLOW_INSECURE__' => AgentInstall::insecureAllowed() ? '1' : '0',
        ]);

        return response($script, 200, [
            // text/plain: shown in the browser, so it can be read first.
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function manifest(string $version): Response
    {
        $release = $this->release($version);

        return response($release->manifest, 200, $this->textHeaders());
    }

    public function signature(string $version): Response
    {
        $release = $this->release($version);

        return response($release->signature."\n", 200, $this->textHeaders());
    }

    public function tarball(string $version, string $file): Response
    {
        $release = $this->release($version);
        abort_unless($file === $release->filename, 404);

        return $this->file($release);
    }

    /** Old URL of install.sh from phase 2: the signed tarball, if imported. */
    public function package(string $version): Response
    {
        $release = AgentRelease::findAvailable($version);

        return $release ? $this->file($release) : response(self::GONE, 404, $this->textHeaders());
    }

    /** Old URL of install.sh from phase 2: the checksum from the signed manifest. */
    public function checksum(string $version): Response
    {
        $release = AgentRelease::findAvailable($version);

        return $release
            ? response($release->sha256.'  '.$release->filename."\n", 200, $this->textHeaders())
            : response(self::GONE, 404, $this->textHeaders());
    }

    private function release(string $version): AgentRelease
    {
        $release = AgentRelease::findAvailable($version);
        abort_if($release === null || ! is_file($release->tarballPath()), 404);

        return $release;
    }

    private function file(AgentRelease $release): Response
    {
        abort_unless(is_file($release->tarballPath()), 404);

        // Small files (at most a few MB): sent as one body, like before.
        return response((string) file_get_contents($release->tarballPath()), 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$release->filename.'"',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** @return array<string, string> */
    private function textHeaders(): array
    {
        return ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-cache'];
    }
}
