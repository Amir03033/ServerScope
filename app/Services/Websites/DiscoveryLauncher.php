<?php

namespace App\Services\Websites;

use App\Jobs\RunPageDiscovery;
use App\Models\Domain;
use App\Models\PageDiscoveryRun;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Starts a background discovery run (web and API): one run per website at
 * a time, runs that never finished are closed, at most three per hour.
 */
class DiscoveryLauncher
{
    /** A run that has not finished after this many minutes is considered dead. */
    public const STALE_AFTER_MINUTES = 15;

    public const RUNS_PER_HOUR = 3;

    /**
     * @return array{run: ?PageDiscoveryRun, error: ?string}
     */
    public function start(Domain $domain, User $user): array
    {
        if (! $domain->is_active || $domain->trashed()) {
            return ['run' => null, 'error' => 'Hervat de monitoring van dit domein voordat je pagina\'s ontdekt.'];
        }

        $domain->discoveryRuns()->whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update(['status' => 'failed', 'errors' => json_encode(['Niet afgerond binnen '.self::STALE_AFTER_MINUTES.' minuten.']), 'finished_at' => now()]);

        if ($domain->discoveryRuns()->whereIn('status', ['queued', 'running'])->exists()) {
            return ['run' => null, 'error' => 'Er loopt al een ontdekking voor deze website.'];
        }

        $key = 'website-discovery:'.$domain->id;

        if (RateLimiter::tooManyAttempts($key, self::RUNS_PER_HOUR)) {
            return ['run' => null, 'error' => 'Te veel ontdekkingen; probeer het over '.ceil(RateLimiter::availableIn($key) / 60).' minuten opnieuw.'];
        }

        RateLimiter::hit($key, 3600);

        $run = PageDiscoveryRun::query()->create([
            'domain_id' => $domain->id,
            'triggered_by' => $user->id,
            'status' => 'queued',
            'settings' => $domain->discoverySettings(),
        ]);

        Audit::log('website.discovery_started', "Paginaontdekking gestart voor {$domain->name}", $domain);
        RunPageDiscovery::dispatch($run->id, $domain->id);

        return ['run' => $run, 'error' => null];
    }
}
