<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\MetricSample;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use Illuminate\Support\Collection;

/**
 * Explains a server's state from the available evidence, keeping three
 * situations apart that look alike from the agent's point of view:
 *
 * - host unreachable: no agent data AND websites that run on the server
 *   (confirmed dependency) are failing too;
 * - monitoring agent unhealthy: no agent data while websites on the
 *   server still answer, or the agent sends incomplete/outdated data;
 * - service failure: the agent reports normally but a service is down.
 *
 * Without independent evidence (no websites linked to the server) a
 * silent agent is reported as "cause unknown", never as a host outage.
 */
class ServerDiagnosis
{
    /** Agent data older than this has no value for "current" statements. */
    private const RECENT_CHECK_MINUTES = 15;

    /**
     * @return array{state: string, tone: string, title: string, explanation: string, evidence: array<int, string>}
     */
    public function diagnose(Server $server): array
    {
        $status = $server->displayStatus();
        $evidence = [];

        if ($status === 'maintenance') {
            return $this->result('maintenance', 'purple', 'Onderhoud', 'De server staat in onderhoud; er worden geen nieuwe incidenten aangemaakt.', []);
        }

        if ($status === 'pending') {
            return $this->result('pending', 'muted', 'Wacht op eerste meting', 'De agent heeft nog geen gegevens gestuurd. Controleer de installatie en het token.', []);
        }

        if ($status === 'offline') {
            $websites = $this->websites($server);
            $recent = $websites->filter(fn (Domain $d) => $d->http_enabled && $d->http_last_checked_at?->gt(now()->subMinutes(self::RECENT_CHECK_MINUTES)));
            $up = $recent->filter(fn (Domain $d) => $d->http_status === 'up' && $d->http_consecutive_failures === 0);
            $down = $recent->filter(fn (Domain $d) => $d->http_status === 'down' || $d->http_consecutive_failures > 0);
            $silent = 'Laatste agentgegevens '.$server->last_seen_at?->locale('nl')->diffForHumans().'.';

            foreach ($up as $domain) {
                $evidence[] = "{$domain->displayName()} reageert wel (HTTP {$domain->http_last_status_code}).";
            }

            foreach ($down as $domain) {
                $evidence[] = "{$domain->displayName()} faalt ook: ".($domain->http_last_error ?? 'geen antwoord').'.';
            }

            if ($up->isNotEmpty() && $down->isEmpty()) {
                return $this->result('agent_unhealthy', 'yellow', 'Monitoringagent reageert niet', "{$silent} Websites op deze server zijn wel bereikbaar, dus de host draait waarschijnlijk nog: controleer de agent (service, token, uitgaande verbinding).", $evidence);
            }

            if ($down->isNotEmpty() && $up->isEmpty()) {
                return $this->result('host_unreachable', 'red', 'Host waarschijnlijk onbereikbaar', "{$silent} Ook de websites op deze server falen. Dat wijst op een probleem met de host of het netwerk, niet alleen de agent.", $evidence);
            }

            if ($down->isNotEmpty()) {
                return $this->result('agent_offline_mixed', 'red', 'Agent offline, websites deels bereikbaar', "{$silent} Sommige websites op deze server reageren nog, andere niet.", $evidence);
            }

            return $this->result('agent_offline_unknown', 'red', 'Geen agentgegevens — oorzaak onbekend', "{$silent} Er zijn geen recent gecontroleerde websites aan deze server gekoppeld, dus het is niet vast te stellen of de host of alleen de agent uitgevallen is. Koppel websites via Afhankelijkheden voor een betere diagnose.", []);
        }

        // Online: look at services and data quality.
        $failing = $server->serviceChecks()->where('is_enabled', true)->whereIn('status', ServiceCheck::FAILED_STATES)->get();

        if ($failing->isNotEmpty()) {
            foreach ($failing as $service) {
                $evidence[] = "{$service->name}: ".(ServiceCheck::STATUS_LABELS[$service->status] ?? $service->status).($service->state_detail ? " ({$service->state_detail})" : '');
            }

            return $this->result('service_failed', 'red', $failing->count() === 1 ? 'Service niet actief' : "{$failing->count()} services niet actief", 'De agent rapporteert normaal, maar een of meer services draaien niet. De host is bereikbaar.', $evidence);
        }

        $quality = $this->dataQuality($server);

        if ($quality['issues'] !== []) {
            return $this->result('agent_degraded', 'yellow', 'Onvolledige agentgegevens', 'De agent rapporteert, maar niet alle verwachte gegevens komen binnen.', $quality['issues']);
        }

        return $this->result('healthy', 'green', 'In orde', 'De agent rapporteert volledig en er zijn geen problemen met services gemeld. Dat betekent niet dat alles gecontroleerd is: alleen geconfigureerde metingen worden bewaakt.', []);
    }

    /**
     * Missing metrics, gaps and agent version/integrity.
     *
     * @return array{coverage: ?float, expected: ?int, received: int, interval_seconds: ?int, missing_fields: array<int, string>, outdated: bool, integrity: string, issues: array<int, string>}
     */
    public function dataQuality(Server $server): array
    {
        $since = now()->subHour();
        $times = MetricSample::query()->where('server_id', $server->id)->where('collected_at', '>=', $since)
            ->orderBy('collected_at')->pluck('collected_at');

        $interval = null;

        if ($times->count() >= 3) {
            $gaps = [];

            for ($i = 1; $i < $times->count(); $i++) {
                $gaps[] = $times[$i - 1]->diffInSeconds($times[$i]);
            }

            sort($gaps);
            $interval = max(10, (int) round($gaps[intdiv(count($gaps), 2)]));
        }

        $expected = $interval ? (int) floor(3600 / $interval) : null;
        $coverage = $expected ? round(min(100, $times->count() / $expected * 100), 1) : null;

        $latest = $server->latestMetricSample;
        $missing = [];

        if ($latest) {
            foreach (['cpu_percent' => 'CPU', 'memory_total_bytes' => 'geheugen', 'load_1' => 'load average', 'network_rx_bytes' => 'netwerk'] as $field => $label) {
                if ($latest->{$field} === null) {
                    $missing[] = $label;
                }
            }

            if (empty($latest->disks)) {
                $missing[] = 'schijven';
            }
        }

        $latestVersion = (string) config('serverscope.agent.latest_version');
        $outdated = $server->agent_version !== null && $latestVersion !== '' && version_compare(ltrim($server->agent_version, 'v'), $latestVersion, '<');

        $known = (array) config('serverscope.agent.known_checksums', []);
        $integrity = match (true) {
            $server->agent_checksum === null => 'not_reported',
            $known === [] => 'not_verified',
            in_array($server->agent_checksum, $known, true) => 'verified',
            default => 'unknown_build',
        };

        $issues = [];

        if ($coverage !== null && $coverage < 80) {
            $issues[] = "Slechts {$coverage}% van de verwachte metingen in het afgelopen uur ontvangen.";
        }

        if ($missing !== []) {
            $issues[] = 'Ontbrekende metingen in de laatste sample: '.implode(', ', $missing).'.';
        }

        if ($outdated) {
            $issues[] = "Agentversie {$server->agent_version} is ouder dan de huidige versie {$latestVersion}.";
        }

        if ($integrity === 'unknown_build') {
            $issues[] = 'De agent meldt een controlesom die niet overeenkomt met een bekende build.';
        }

        return [
            'coverage' => $coverage,
            'expected' => $expected,
            'received' => $times->count(),
            'interval_seconds' => $interval,
            'missing_fields' => $missing,
            'outdated' => $outdated,
            'integrity' => $integrity,
            'issues' => $issues,
        ];
    }

    /** Websites with a confirmed dependency on the server. */
    private function websites(Server $server): Collection
    {
        $ids = ResourceDependency::query()
            ->where('dependency_type', 'server')->where('dependency_id', $server->id)
            ->where('dependent_type', 'domain')
            ->pluck('dependent_id');

        return Domain::query()->whereIn('id', $ids)->where('is_active', true)->get();
    }

    private function result(string $state, string $tone, string $title, string $explanation, array $evidence): array
    {
        return compact('state', 'tone', 'title', 'explanation', 'evidence');
    }
}
