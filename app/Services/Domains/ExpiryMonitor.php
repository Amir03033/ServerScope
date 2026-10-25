<?php

namespace App\Services\Domains;

use App\Models\Domain;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Support\Facades\DB;

/**
 * Domain registration expiry via RDAP. Only verified dates (an RDAP
 * "expiration" event) are used for warnings; unavailable or unsupported
 * data is shown as such and never estimated.
 */
class ExpiryMonitor
{
    public function __construct(
        private RdapClient $rdap,
        private IncidentManager $incidents,
    ) {}

    public static function fingerprint(Domain $domain): string
    {
        return "domain:{$domain->id}:expiry";
    }

    public function check(Domain $domain): void
    {
        $started = microtime(true);
        $result = $this->rdap->lookup($domain->name);
        $now = now();

        DB::transaction(function () use ($domain, $result, $started, $now): void {
            /** @var Domain $locked */
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            $locked->checks()->create([
                'type' => 'expiry',
                'result' => $result->status,
                'success' => in_array($result->status, ['verified', 'unavailable', 'unsupported'], true),
                'response_time_ms' => round((microtime(true) - $started) * 1000, 1),
                'error_code' => in_array($result->status, ['error', 'rate_limited', 'not_found'], true) ? $result->status : null,
                'message' => $result->message ? mb_substr($result->message, 0, 500) : null,
                'details' => array_filter([
                    'source' => $result->source,
                    'expires_at' => $result->expiresAt?->toIso8601String(),
                    'registered_at' => $result->registeredAt?->toIso8601String(),
                    'registrar' => $result->registrar,
                    'statuses' => $result->statuses ?: null,
                ]),
                'checked_at' => $now,
            ]);

            $locked->expiry_checked_at = $now;
            $locked->expiry_message = $result->message ? mb_substr($result->message, 0, 500) : null;

            // Keep the last verified data on transient errors.
            if (in_array($result->status, ['error', 'rate_limited'], true)) {
                $locked->expiry_next_check_at = $now->copy()->addSeconds($result->retryAfterSeconds ?? 6 * 3600);

                if ($locked->expiry_status === 'unknown') {
                    $locked->expiry_status = $result->status;
                }

                $locked->save();

                return;
            }

            $locked->expiry_status = $result->status;
            $locked->expiry_source = $result->source ? mb_substr($result->source, 0, 500) : null;
            $locked->registrable_domain = $result->registrableDomain;
            $locked->registrar = $result->registrar;
            $locked->registered_at = $result->registeredAt;
            $locked->registration_expires_at = $result->status === 'verified' ? $result->expiresAt : null;
            $locked->expiry_next_check_at = $now->copy()->addMinutes(
                $result->status === 'verified' ? (int) config('serverscope.monitoring.expiry_interval_minutes', 1440) : 7 * 1440,
            );

            $this->evaluate($locked, $now);

            $locked->save();
            DomainStatus::refresh($locked);
            $domain->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function evaluate(Domain $domain, $now): void
    {
        $days = $domain->expiry_status === 'verified' ? $domain->expiryDaysRemaining() : null;
        $thresholds = $domain->expiryWarningDays();

        if ($days === null || $days > max($thresholds)) {
            $domain->expiry_warning_threshold = null;
            $this->incidents->recover(self::fingerprint($domain), $days === null
                ? 'Er is geen geverifieerde vervaldatum meer beschikbaar.'
                : "De registratie van {$domain->displayName()} is verlengd tot ".$domain->registration_expires_at->format('d-m-Y').'.');

            return;
        }

        $crossed = $days < 0 ? 0 : min(array_filter($thresholds, fn (int $t) => $days <= $t));
        $domain->expiry_warning_threshold = $crossed;

        if ($domain->isInMaintenance($now)) {
            return;
        }

        $name = $domain->registrable_domain ?? $domain->name;

        $this->incidents->trigger(new Signal(
            fingerprint: self::fingerprint($domain),
            source: 'expiry',
            severity: $days <= min(7, min($thresholds)) ? 'critical' : 'warning',
            title: $days < 0
                ? "Domeinregistratie {$name} is verlopen"
                : "Domeinregistratie {$name} verloopt over {$days} ".($days === 1 ? 'dag' : 'dagen'),
            description: 'Vervaldatum volgens RDAP: '.$domain->registration_expires_at->format('d-m-Y H:i').' UTC'
                .($domain->registrar ? ", registrar: {$domain->registrar}" : '').'. Bron: '.$domain->expiry_source,
            subject: $domain,
            context: ['expires_at' => $domain->registration_expires_at->toIso8601String(), 'threshold' => $crossed],
            escalationKey: "threshold:{$crossed}",
        ));
    }
}
