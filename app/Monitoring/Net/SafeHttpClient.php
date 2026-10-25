<?php

namespace App\Monitoring\Net;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The only HTTP client used for user-configured monitoring targets.
 *
 * Every request (and every redirect hop) is validated by TargetGuard and
 * sent to the vetted IP via CURLOPT_RESOLVE, so DNS cannot be re-resolved
 * to an internal address between validation and connection (DNS
 * rebinding). Redirects are followed manually, responses are capped in
 * size, environment proxies are ignored and requests per target IP are
 * rate limited.
 */
class SafeHttpClient
{
    public function __construct(
        private TargetGuard $guard,
        private int $maxRedirects = 5,
        private int $maxBytes = 1_048_576,
        private int $perIpPerMinute = 30,
        private string $userAgent = 'ServerScope-Monitor/1.0 (+https://serverscope.nl)',
    ) {}

    public function get(string $url, float $timeoutSeconds = 10.0, bool $followRedirects = true, array $headers = []): HttpResult
    {
        $started = microtime(true);
        $redirects = [];
        $seen = [];
        $current = $url;

        while (true) {
            try {
                $target = $this->guard->resolve($current);
            } catch (BlockedTargetException $exception) {
                $reason = in_array($exception->reason, ['dns_error', 'nxdomain', 'no_address'], true) ? 'dns_error' : 'blocked';

                return $this->failure($url, $current, $redirects, $started, $reason, $exception->getMessage(), $exception->reason);
            }

            $key = 'monitor-ip:'.$target->ip;

            if (RateLimiter::tooManyAttempts($key, $this->perIpPerMinute)) {
                return $this->failure($url, $current, $redirects, $started, 'rate_limited', 'Te veel verzoeken naar dit IP-adres; controle overgeslagen.', null, $target->ip);
            }

            RateLimiter::hit($key, 60);

            $remaining = $timeoutSeconds - (microtime(true) - $started);

            if ($remaining <= 0) {
                return $this->failure($url, $current, $redirects, $started, 'timeout', 'Time-out bereikt.', null, $target->ip);
            }

            $sink = new LimitedSink($this->maxBytes);

            try {
                $response = $this->send($target, $remaining, $sink, $headers);
            } catch (Throwable $exception) {
                $partial = $this->partialResponse($exception);

                // Body limit reached: keep the headers and the first bytes.
                if ($sink->exceeded && $partial !== null) {
                    return $this->success($url, $current, $partial->getStatusCode(), $partial->getHeaders(), (string) $sink, true, $redirects, $started, $target->ip);
                }

                [$error, $message] = $this->classify($exception);

                return $this->failure($url, $current, $redirects, $started, $error, $message, null, $target->ip);
            }

            $status = $response->status();
            $location = $response->header('Location');

            if ($followRedirects && $status >= 300 && $status < 400 && $location !== '') {
                $next = self::resolveUrl($current, $location);

                if ($next === null) {
                    return $this->failure($url, $current, $redirects, $started, 'invalid_redirect', 'Ongeldige redirect-locatie.', null, $target->ip);
                }

                $redirects[] = ['url' => $current, 'status' => $status];
                $seen[$current] = true;

                if (isset($seen[$next])) {
                    return $this->failure($url, $next, $redirects, $started, 'redirect_loop', 'Redirect-lus gedetecteerd.', null, $target->ip);
                }

                if (count($redirects) > $this->maxRedirects) {
                    return $this->failure($url, $next, $redirects, $started, 'too_many_redirects', "Meer dan {$this->maxRedirects} redirects.", null, $target->ip);
                }

                $current = $next;

                continue;
            }

            $body = $response->body();
            $truncated = $sink->exceeded || strlen($body) > $this->maxBytes;

            return $this->success($url, $current, $status, $response->headers(), substr($body, 0, $this->maxBytes), $truncated, $redirects, $started, $target->ip);
        }
    }

    private function success(string $url, string $current, int $status, array $headers, string $body, bool $truncated, array $redirects, float $started, string $ip): HttpResult
    {
        return new HttpResult(
            url: $url,
            finalUrl: $current,
            status: $status,
            headers: array_map(fn ($values) => implode(', ', (array) $values), array_change_key_case($headers, CASE_LOWER)),
            body: $body,
            truncated: $truncated,
            redirects: $redirects,
            durationMs: round((microtime(true) - $started) * 1000, 1),
            ip: $ip,
        );
    }

    private function partialResponse(Throwable $exception): ?\Psr\Http\Message\ResponseInterface
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof RequestException && $e->getResponse() !== null) {
                return $e->getResponse();
            }
        }

        return null;
    }

    /**
     * Guzzle/cURL options that pin the connection to the vetted address.
     */
    public function connectionOptions(ResolvedTarget $target, float $timeoutSeconds): array
    {
        $ip = str_contains($target->ip, ':') ? '['.$target->ip.']' : $target->ip;

        return [
            'allow_redirects' => false,
            'http_errors' => false,
            // Never route monitoring through an environment proxy: it would
            // resolve the hostname itself and bypass the IP pin.
            'proxy' => '',
            'verify' => true,
            'connect_timeout' => min(5.0, $timeoutSeconds),
            'timeout' => $timeoutSeconds,
            'curl' => [
                // Scheme and redirects are enforced by TargetGuard and the
                // manual redirect loop; Guzzle manages the related options.
                CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$ip}"],
            ],
        ];
    }

    private function send(ResolvedTarget $target, float $timeoutSeconds, LimitedSink $sink, array $headers = []): Response
    {
        return Http::withOptions([...$this->connectionOptions($target, $timeoutSeconds), 'sink' => $sink])
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                ...$headers,
                'User-Agent' => $this->userAgent,
            ])
            ->get($target->url);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function classify(Throwable $exception): array
    {
        $previous = $exception;
        $errno = null;

        while ($previous !== null) {
            if ($previous instanceof RequestException || $previous instanceof ConnectException) {
                $errno = $previous->getHandlerContext()['errno'] ?? $errno;
            }

            $previous = $previous->getPrevious();
        }

        $message = $exception->getMessage();

        // Laravel's ConnectionException keeps only the message.
        if ($errno === null && preg_match('/cURL error (\d+)/', $message, $m)) {
            $errno = (int) $m[1];
        }

        $error = match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT || str_contains($message, 'timed out') => 'timeout',
            in_array($errno, [CURLE_SSL_CONNECT_ERROR, 51, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE], true),
            str_contains($message, 'SSL'), str_contains($message, 'certificate') => 'tls_error',
            $errno === CURLE_COULDNT_RESOLVE_HOST => 'dns_error',
            $errno === CURLE_FILESIZE_EXCEEDED => 'too_large',
            $exception instanceof ConnectionException, $exception instanceof TransferException => 'connection_failed',
            default => 'connection_failed',
        };

        $labels = [
            'timeout' => 'Time-out: de server reageerde niet op tijd.',
            'tls_error' => 'TLS-fout: de beveiligde verbinding kon niet worden opgezet of het certificaat is ongeldig.',
            'dns_error' => 'De hostname kon niet worden opgezocht.',
            'too_large' => 'Het antwoord is te groot.',
            'connection_failed' => 'Verbinding mislukt (geweigerd of onbereikbaar).',
        ];

        // Keep the detail short and free of request internals.
        $detail = trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? '');
        $detail = preg_replace('/\(see https?:\/\/[^)]*\)/', '', $detail) ?? $detail;

        return [$error, $labels[$error].($detail !== '' ? ' ('.mb_substr($detail, 0, 160).')' : '')];
    }

    private function failure(string $url, string $current, array $redirects, float $started, string $error, string $message, ?string $detail = null, ?string $ip = null): HttpResult
    {
        return new HttpResult(
            url: $url,
            finalUrl: $current,
            redirects: $redirects,
            durationMs: round((microtime(true) - $started) * 1000, 1),
            ip: $ip,
            error: $error,
            errorMessage: $message,
        );
    }

    /**
     * Resolve a Location header against the current URL (RFC 3986 §5.2,
     * sufficient for absolute, scheme-relative, absolute-path and
     * relative-path references).
     */
    public static function resolveUrl(string $base, string $location): ?string
    {
        $location = trim($location);

        if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location)) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) {
            return $location;
        }

        $parts = parse_url($base);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $authority = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $authority.$location;
        }

        if (str_starts_with($location, '?')) {
            return $authority.($parts['path'] ?? '/').$location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, strrpos($path, '/') + 1);
        $segments = [];

        foreach (explode('/', $directory.$location) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        $resolved = implode('/', $segments);

        return $authority.(str_starts_with($resolved, '/') ? '' : '/').$resolved;
    }
}
