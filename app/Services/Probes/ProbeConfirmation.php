<?php

namespace App\Services\Probes;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Probe;
use App\Models\ProbeCheck;
use App\Services\Domains\HttpMonitor;
use App\Services\Incidents\IncidentManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Second-location confirmation of HTTP outages (Global Pulse Lite).
 *
 * When a website reaches its failure threshold and a healthy probe exists,
 * the incident is not opened right away: a confirmation job is queued for
 * the probe (one per website at a time). The probe's answer decides:
 * - confirmed (probe fails too): critical incident, as before;
 * - main_only (probe reaches the site): warning incident, rechecked every
 *   recheck_minutes and raised to critical once the probe confirms;
 * - probe_only (probe fails, main monitor recovered): recorded, no incident;
 * - unavailable (no healthy probe, or no answer before the job expires):
 *   critical incident exactly as without a probe.
 * Without any configured probe nothing changes.
 */
class ProbeConfirmation
{
    public function __construct(private IncidentManager $incidents) {}

    /**
     * Called by HttpMonitor once the failure threshold is reached.
     *
     * @return array{severity: string, confirmation: ?string}|null null = wait for the probe
     */
    public function beforeTrigger(Domain $domain, CarbonInterface $now): ?array
    {
        if (! config('serverscope.probe.confirmation_enabled', true)) {
            return ['severity' => 'critical', 'confirmation' => null];
        }

        $active = Incident::query()->withoutGlobalScopes()->where('active_fingerprint', HttpMonitor::fingerprint($domain))->first();

        if ($active !== null) {
            if (($active->context['confirmation'] ?? null) === 'main_only') {
                $this->request($domain, $now, recheck: true);

                return ['severity' => 'warning', 'confirmation' => 'main_only'];
            }

            return ['severity' => 'critical', 'confirmation' => null];
        }

        if (Probe::healthy() === null) {
            return ['severity' => 'critical', 'confirmation' => Probe::query()->where('is_active', true)->exists() ? 'unavailable' : null];
        }

        $this->request($domain, $now);

        return null;
    }

    /** Queues a job unless one is pending (or, for a recheck, one was requested recently). */
    public function request(Domain $domain, CarbonInterface $now, bool $recheck = false): ?ProbeCheck
    {
        $probe = Probe::healthy();
        $jobs = ProbeCheck::query()->withoutGlobalScopes()->where('domain_id', $domain->id);

        if ($probe === null || (clone $jobs)->where('status', 'pending')->exists()) {
            return null;
        }

        if ($recheck && (clone $jobs)->where('requested_at', '>', $now->copy()->subMinutes((int) config('serverscope.probe.recheck_minutes', 10)))->exists()) {
            return null;
        }

        $job = new ProbeCheck([
            'probe_id' => $probe->id,
            'domain_id' => $domain->id,
            'status' => 'pending',
            'requested_at' => $now,
            'expires_at' => $now->copy()->addSeconds((int) config('serverscope.probe.job_ttl_seconds', 120)),
        ]);
        $job->forceFill(['team_id' => $domain->team_id])->save();

        return $job;
    }

    /**
     * Stores a probe result for a pending job and applies it.
     *
     * @param  array{checked_at: CarbonInterface, http_status_code: ?int, response_time_ms: ?float, error_code: ?string, message: ?string}  $result
     */
    public function record(ProbeCheck $job, array $result): ProbeCheck
    {
        return DB::transaction(function () use ($job, $result) {
            $job = ProbeCheck::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($job->id);
            $domain = Domain::query()->withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($job->domain_id);
            $probeOk = $result['error_code'] === null && $result['http_status_code'] !== null && $domain?->acceptsStatus($result['http_status_code']);
            $mainFailing = $domain !== null && $domain->http_status === 'down' && $domain->http_consecutive_failures > 0;

            $job->forceFill([
                ...$result,
                'status' => 'completed',
                'success' => $probeOk,
                'outcome' => $mainFailing ? ($probeOk ? 'main_only' : 'confirmed') : ($probeOk ? 'not_reproduced' : 'probe_only'),
            ])->save();

            if (! $mainFailing || $domain->trashed() || ! $domain->is_active || $domain->isInMaintenance(now())) {
                return $job;
            }

            $active = $this->incidents->isActive(HttpMonitor::fingerprint($domain));

            if ($job->outcome === 'confirmed') {
                $this->incidents->trigger(HttpMonitor::signal($domain, 'critical', 'confirmed'));
            } elseif (! $active) {
                $this->incidents->trigger(HttpMonitor::signal($domain, 'warning', 'main_only'));
            }

            return $job;
        });
    }

    /** Jobs the probe did not answer in time: fall back to the behaviour without a probe. */
    public function expire(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $count = 0;

        foreach (ProbeCheck::query()->withoutGlobalScopes()->where('status', 'pending')->where('expires_at', '<', $now)->limit(500)->get() as $job) {
            DB::transaction(function () use ($job, $now) {
                $job->forceFill(['status' => 'expired', 'outcome' => 'unavailable'])->save();
                $domain = Domain::query()->withoutGlobalScopes()->lockForUpdate()->find($job->domain_id);

                if ($domain !== null && $domain->is_active && $domain->http_status === 'down'
                    && $domain->http_consecutive_failures >= $domain->failure_threshold
                    && ! $domain->isInMaintenance($now)
                    && ! $this->incidents->isActive(HttpMonitor::fingerprint($domain))) {
                    $this->incidents->trigger(HttpMonitor::signal($domain, 'critical', 'unavailable'));
                }
            });
            $count++;
        }

        return $count;
    }
}
