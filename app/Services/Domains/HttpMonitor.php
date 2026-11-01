<?php

namespace App\Services\Domains;

use App\Models\Domain;
use App\Monitoring\Net\SafeHttpClient;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Probes\ProbeConfirmation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HTTP(S) availability check with consecutive failure / recovery
 * thresholds: a single failed request only marks the domain as degraded;
 * an incident is opened once failure_threshold checks in a row failed and
 * resolved after recovery_threshold successful checks.
 */
class HttpMonitor
{
    public function __construct(
        private SafeHttpClient $client,
        private IncidentManager $incidents,
        private ProbeConfirmation $confirmation,
    ) {}

    public static function fingerprint(Domain $domain): string
    {
        return "domain:{$domain->id}:http";
    }

    /** Time of the first failed check after the last success. */
    private static function failingSince(Domain $domain): ?Carbon
    {
        $first = $domain->checks()
            ->where('type', 'http')
            ->where('success', false)
            ->when($domain->http_last_success_at, fn ($q, $at) => $q->where('checked_at', '>', $at))
            ->min('checked_at');

        return $first !== null ? Carbon::parse($first, 'UTC') : null;
    }

    /** The HTTP incident signal; $confirmation is the remote probe outcome, if any. */
    public static function signal(Domain $domain, string $severity, ?string $confirmation = null, ?string $code = null, ?int $status = null, ?string $message = null): Signal
    {
        $message ??= $domain->http_last_error ?? 'De website is niet bereikbaar.';
        $note = match ($confirmation) {
            'confirmed' => ' Bevestigd vanaf een tweede meetlocatie.',
            'main_only' => ' Alleen vanaf de hoofdlocatie waargenomen: de tweede meetlocatie bereikte de website wel.',
            'unavailable' => ' Niet bevestigd: de tweede meetlocatie was niet beschikbaar.',
            default => '',
        };

        return new Signal(
            fingerprint: self::fingerprint($domain),
            source: 'http',
            severity: $severity,
            title: "{$domain->displayName()} is onbereikbaar",
            description: "{$message} ({$domain->http_consecutive_failures} opeenvolgende mislukte controles van {$domain->url()}).".$note,
            subject: $domain,
            context: array_filter(['error' => $code ?? $domain->checks()->where('type', 'http')->latest('checked_at')->value('error_code'), 'status_code' => $status ?? $domain->http_last_status_code, 'confirmation' => $confirmation], fn ($v) => $v !== null),
            startedAt: self::failingSince($domain),
        );
    }

    public function check(Domain $domain): void
    {
        $result = $this->client->get($domain->url(), (float) $domain->timeout_seconds, $domain->follow_redirects);
        $now = now();

        [$success, $code, $message] = match (true) {
            $result->failed() => [false, $result->error, $result->errorMessage],
            ! $domain->acceptsStatus($result->status) => [false, 'unexpected_status', "Onverwachte HTTP-statuscode {$result->status}."],
            $domain->expected_content !== null && $domain->expected_content !== '' && ! str_contains($result->body, $domain->expected_content) => [false, 'content_mismatch', 'De verwachte inhoud is niet gevonden'.($result->truncated ? ' (antwoord ingekort tot de maximale grootte).' : '.')],
            default => [true, null, null],
        };

        DB::transaction(function () use ($domain, $result, $success, $code, $message, $now): void {
            /** @var Domain $locked */
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            $locked->checks()->create([
                'type' => 'http',
                'result' => $success ? 'up' : 'down',
                'success' => $success,
                'response_time_ms' => $result->durationMs,
                'http_status_code' => $result->status,
                'error_code' => $code,
                'message' => $message ? mb_substr($message, 0, 500) : null,
                'details' => array_filter([
                    'final_url' => $result->finalUrl !== $result->url ? $result->finalUrl : null,
                    'redirects' => $result->redirects ?: null,
                    'ip' => $result->ip,
                    'truncated' => $result->truncated ?: null,
                ]),
                'checked_at' => $now,
            ]);

            $locked->http_last_checked_at = $now;
            $locked->http_last_status_code = $result->status;
            $locked->http_last_response_ms = $result->durationMs;
            $locked->http_last_error = $message ? mb_substr($message, 0, 500) : null;

            if ($success) {
                $locked->http_last_success_at = $now;
                $locked->http_consecutive_failures = 0;
                $locked->http_consecutive_successes++;

                if ($locked->http_status !== 'down' || $locked->http_consecutive_successes >= $locked->recovery_threshold) {
                    $wasDown = $locked->http_status === 'down';
                    $locked->http_status = 'up';

                    if ($wasDown || $this->incidents->isActive(self::fingerprint($locked))) {
                        $this->incidents->recover(self::fingerprint($locked), "{$locked->displayName()} is weer bereikbaar (HTTP {$result->status}, ".round((float) $result->durationMs).' ms).');
                    }
                }
            } else {
                $locked->http_consecutive_successes = 0;
                $locked->http_consecutive_failures++;

                if ($locked->http_consecutive_failures >= $locked->failure_threshold) {
                    $locked->http_status = 'down';

                    // With a healthy remote probe the incident waits for its
                    // confirmation (ProbeConfirmation); otherwise as before.
                    if (! $locked->isInMaintenance($now) && ($decision = $this->confirmation->beforeTrigger($locked, $now)) !== null) {
                        $this->incidents->trigger(self::signal($locked, $decision['severity'], $decision['confirmation'], $code, $result->status, $message));
                    }
                }
            }

            $locked->save();
            DomainStatus::refresh($locked);
            $domain->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
