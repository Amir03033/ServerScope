<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Heartbeat;
use App\Services\Heartbeats\HeartbeatMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The ping URL of a scheduled job: GET, POST or HEAD
 *   /api/ping/{token}          the job finished
 *   /api/ping/{token}/start    the job began
 *   /api/ping/{token}/fail     the job failed
 *   /api/ping/{token}/{code}   finished with this exit code (0 = success)
 * A POST body (the job's output) is kept, shortened and redacted. The
 * token is the credential; an unknown token gets the same plain 404.
 */
class HeartbeatPingController extends Controller
{
    private const READ_LIMIT = 10000;

    public function __invoke(Request $request, HeartbeatMonitor $monitor, string $token, ?string $action = null): Response
    {
        $heartbeat = Heartbeat::query()->withoutGlobalScopes()->where('token', $token)->first();

        if ($heartbeat === null) {
            return $this->plain('Not found', 404);
        }

        [$kind, $exitCode] = match (true) {
            $action === null => ['success', null],
            $action === 'start' => ['start', null],
            $action === 'fail' => ['fail', null],
            default => ['success', min(255, (int) $action)],
        };

        $body = $request->isMethod('post') ? mb_strcut((string) $request->getContent(), 0, self::READ_LIMIT) : null;
        $monitor->ping($heartbeat, $kind, $exitCode, $body);

        return $this->plain('OK');
    }

    private function plain(string $text, int $status = 200): Response
    {
        return response($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
