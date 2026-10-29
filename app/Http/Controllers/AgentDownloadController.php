<?php

namespace App\Http\Controllers;

use App\Services\AgentPackage;
use App\Support\AgentInstall;
use Illuminate\Http\Response;

/**
 * Public downloads for install.sh: the script itself (readable in the
 * browser before running it), the agent package and its SHA-256.
 * None of them contains a secret or team data.
 */
class AgentDownloadController extends Controller
{
    public function script(AgentPackage $package): Response
    {
        if ($problem = AgentInstall::urlProblem()) {
            return response("# {$problem}\necho 'Koppelen is uitgeschakeld: APP_URL van ServerScope gebruikt geen https.' >&2\nexit 1\n", 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $script = strtr((string) file_get_contents(resource_path('agent/install.sh')), [
            '__SERVERSCOPE_URL__' => AgentInstall::baseUrl(),
            '__AGENT_VERSION__' => $package->version(),
            '__ALLOW_INSECURE__' => AgentInstall::insecureAllowed() ? '1' : '0',
        ]);

        return response($script, 200, [
            // text/plain: shown in the browser, so it can be read first.
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function package(AgentPackage $package, string $version): Response
    {
        abort_unless($version === $package->version(), 404);

        return response((string) file_get_contents($package->path()), 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$package->filename().'"',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function checksum(AgentPackage $package, string $version): Response
    {
        abort_unless($version === $package->version(), 404);

        return response($package->checksum().'  '.$package->filename()."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
