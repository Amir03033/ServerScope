<?php

namespace App\Services\Probes;

use App\Monitoring\Net\SafeHttpClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The remote probe itself (runs on a separate host with the same code:
 * `php artisan serverscope:probe:run`). It only polls ServerScope for
 * signed confirmation jobs and answers them; it accepts no inbound
 * requests, so it cannot be used as a proxy.
 *
 * Every check goes through SafeHttpClient/TargetGuard on the probe host
 * (private, loopback, link-local incl. cloud metadata 169.254.169.254 and
 * reserved addresses are blocked, also after redirects), as a plain GET
 * with a capped time-out. Only the status code, timing and an error code
 * are sent back; response bodies never leave the probe.
 */
class ProbeAgent
{
    public const VERSION = '1';

    public function __construct(private SafeHttpClient $client) {}

    /** @return array{jobs: int, accepted: int} */
    public function runOnce(): array
    {
        // The probe never checks private networks, whatever its .env says.
        config(['serverscope.monitoring.allow_private_targets' => false, 'serverscope.monitoring.allowed_cidrs' => []]);

        $jobs = $this->call('GET', '/api/probe/jobs')['data'] ?? [];
        $results = [];

        foreach (array_slice(is_array($jobs) ? $jobs : [], 0, 20) as $job) {
            if (is_array($job) && ($result = $this->execute($job)) !== null) {
                $results[] = $result;
            }
        }

        if ($results === []) {
            return ['jobs' => count($jobs), 'accepted' => 0];
        }

        $response = $this->call('POST', '/api/probe/results', ['results' => $results]);

        return ['jobs' => count($jobs), 'accepted' => count($response['accepted'] ?? [])];
    }

    /** @return array<string, mixed>|null null for a malformed job */
    public function execute(array $job): ?array
    {
        $url = $job['url'] ?? null;

        if (! is_int($job['id'] ?? null) || ! is_string($url) || strlen($url) > 2048 || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        $timeout = max(1.0, min((float) ($job['timeout_seconds'] ?? 10), (float) config('serverscope.probe.max_timeout_seconds', 10)));
        $result = $this->client->get($url, $timeout, (bool) ($job['follow_redirects'] ?? true));

        return [
            'id' => $job['id'],
            'checked_at' => now()->toIso8601String(),
            'http_status_code' => $result->failed() ? null : $result->status,
            'response_time_ms' => $result->durationMs !== null ? round($result->durationMs, 1) : null,
            'error_code' => $result->failed() ? (preg_match('/^[a-z_]{1,50}$/', (string) $result->error) ? $result->error : 'error') : null,
            'message' => $result->failed() && $result->errorMessage ? mb_substr($result->errorMessage, 0, 200) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?array $payload = null): array
    {
        $base = rtrim((string) config('serverscope.probe.central_url'), '/');
        $key = (string) config('serverscope.probe.key');
        $secret = (string) config('serverscope.probe.secret');

        if ($base === '' || $key === '' || $secret === '') {
            throw new RuntimeException('SERVERSCOPE_PROBE_URL, SERVERSCOPE_PROBE_KEY and SERVERSCOPE_PROBE_SECRET are required.');
        }

        if (! str_starts_with($base, 'https://') && ! app()->environment('local', 'testing')) {
            throw new RuntimeException('SERVERSCOPE_PROBE_URL must use https.');
        }

        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $request = Http::timeout(15)->acceptJson()
            ->withHeaders([...ProbeSignature::headers($key, $secret, $method, $path, $body), 'X-Probe-Version' => self::VERSION]);
        $response = $method === 'GET'
            ? $request->get($base.$path)
            : $request->withBody($body, 'application/json')->post($base.$path);

        if (! $response->successful() && $response->status() !== 422) {
            throw new RuntimeException("ServerScope answered HTTP {$response->status()}.");
        }

        return (array) $response->json();
    }
}
