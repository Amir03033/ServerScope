<?php

namespace App\Services\Domains;

use App\Models\Domain;
use App\Monitoring\Net\BlockedTargetException;
use App\Monitoring\Net\TargetGuard;
use App\Monitoring\Net\TlsInspector;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Support\Facades\DB;

/**
 * Certificate validity and expiry monitoring.
 *
 * Certificate problems (expired, hostname mismatch, untrusted chain,
 * self-signed, not yet valid) open a critical "ssl" incident. An
 * approaching expiry opens an "ssl_expiry" incident that is re-announced
 * each time a lower warning threshold (e.g. 30, 14, 7, 3, 1 days) is
 * crossed. Connectivity failures are left to the HTTP check.
 */
class SslMonitor
{
    public function __construct(
        private TargetGuard $guard,
        private TlsInspector $inspector,
        private IncidentManager $incidents,
    ) {}

    public static function fingerprint(Domain $domain): string
    {
        return "domain:{$domain->id}:ssl";
    }

    public static function expiryFingerprint(Domain $domain): string
    {
        return "domain:{$domain->id}:ssl_expiry";
    }

    public function check(Domain $domain): void
    {
        $now = now();

        try {
            $target = $this->guard->resolve("https://{$domain->name}/");
            $tls = $this->inspector->inspect($target->host, $target->ip, 443, (float) max(5, $domain->timeout_seconds));
        } catch (BlockedTargetException $exception) {
            $this->record($domain, 'error', false, $exception->reason, $exception->getMessage(), [], $now);

            return;
        }

        DB::transaction(function () use ($domain, $tls, $now): void {
            /** @var Domain $locked */
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $maintenance = $locked->isInMaintenance($now);
            $details = $tls->toArray();

            if (! $tls->hasCertificate()) {
                // No certificate could be retrieved; HTTP monitoring reports reachability.
                $locked->checks()->create([
                    'type' => 'ssl', 'result' => $tls->status, 'success' => false,
                    'response_time_ms' => $tls->durationMs, 'error_code' => $tls->status,
                    'message' => mb_substr((string) $tls->message, 0, 500), 'details' => $details, 'checked_at' => $now,
                ]);

                $locked->forceFill([
                    'ssl_status' => $tls->status,
                    'ssl_message' => mb_substr((string) $tls->message, 0, 500),
                    'ssl_last_checked_at' => $now,
                ])->save();

                DomainStatus::refresh($locked);
                $domain->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $changed = $locked->ssl_fingerprint !== null && $locked->ssl_fingerprint !== $tls->fingerprint;
            $days = $tls->daysRemaining();
            $thresholds = $locked->sslWarningDays();
            $status = $tls->status;

            if ($status === 'valid' && $days !== null && $days <= max($thresholds)) {
                $status = 'expiring';
            }

            $details['certificate_changed'] = $changed ?: null;
            $details['days_remaining'] = $days;

            $locked->checks()->create([
                'type' => 'ssl',
                'result' => $status,
                'success' => in_array($status, ['valid', 'expiring'], true),
                'response_time_ms' => $tls->durationMs,
                'error_code' => in_array($status, ['valid', 'expiring'], true) ? null : $status,
                'message' => $changed ? 'Nieuw certificaat gedetecteerd. '.($tls->message ?? '') : $tls->message,
                'details' => array_filter($details, fn ($v) => $v !== null),
                'checked_at' => $now,
            ]);

            $locked->forceFill([
                'ssl_status' => $status,
                'ssl_valid_from' => $tls->validFrom,
                'ssl_valid_to' => $tls->validTo,
                'ssl_issuer' => $tls->issuer ? mb_substr($tls->issuer, 0, 255) : null,
                'ssl_subject' => $tls->subject ? mb_substr($tls->subject, 0, 255) : null,
                'ssl_fingerprint' => $tls->fingerprint,
                'ssl_message' => $tls->message ? mb_substr($tls->message, 0, 500) : null,
                'ssl_last_checked_at' => $now,
            ]);

            // Certificate problems.
            if (in_array($status, Domain::SSL_INVALID_STATES, true)) {
                if (! $maintenance) {
                    $this->incidents->trigger(new Signal(
                        fingerprint: self::fingerprint($locked),
                        source: 'ssl',
                        severity: 'critical',
                        title: "Ongeldig certificaat voor {$locked->displayName()}",
                        description: $tls->message,
                        subject: $locked,
                        context: ['ssl_status' => $status],
                        escalationKey: $status,
                    ));
                }
            } else {
                $this->incidents->recover(self::fingerprint($locked), "Het certificaat van {$locked->displayName()} is weer geldig.");
            }

            // Approaching expiry, announced once per crossed threshold.
            if ($status === 'expiring') {
                $crossed = min(array_filter($thresholds, fn (int $t) => $days <= $t));
                $locked->ssl_warning_threshold = $crossed;

                if (! $maintenance) {
                    $this->incidents->trigger(new Signal(
                        fingerprint: self::expiryFingerprint($locked),
                        source: 'ssl_expiry',
                        severity: $days <= min(3, min($thresholds)) ? 'critical' : 'warning',
                        title: "Certificaat van {$locked->displayName()} verloopt over {$days} ".($days === 1 ? 'dag' : 'dagen'),
                        description: 'Geldig tot '.$tls->validTo?->format('d-m-Y H:i').' UTC, uitgegeven door '.($tls->issuer ?? 'onbekend').'.',
                        subject: $locked,
                        context: ['valid_to' => $tls->validTo?->toIso8601String(), 'threshold' => $crossed],
                        escalationKey: "threshold:{$crossed}",
                    ));
                }
            } else {
                $locked->ssl_warning_threshold = null;
                $message = $status === 'expired'
                    ? 'Het certificaat is verlopen; zie het certificaatincident.'
                    : "Het certificaat van {$locked->displayName()} is vernieuwd (geldig tot ".$tls->validTo?->format('d-m-Y').').';
                $this->incidents->recover(self::expiryFingerprint($locked), $message);
            }

            $locked->save();
            DomainStatus::refresh($locked);
            $domain->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function record(Domain $domain, string $result, bool $success, ?string $code, ?string $message, array $details, $now): void
    {
        $domain->checks()->create([
            'type' => 'ssl', 'result' => $result, 'success' => $success, 'error_code' => $code,
            'message' => $message ? mb_substr($message, 0, 500) : null, 'details' => $details ?: null, 'checked_at' => $now,
        ]);

        $domain->forceFill(['ssl_last_checked_at' => $now, 'ssl_message' => $message ? mb_substr($message, 0, 500) : null])->save();
    }
}
