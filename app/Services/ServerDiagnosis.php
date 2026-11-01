<?php

namespace App\Services;

use App\Models\AgentRelease;
use App\Models\Domain;
use App\Models\MetricSample;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Support\Label;
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
            return $this->result('maintenance', 'purple', __('servers.diagnosis.maintenance.0'), __('servers.diagnosis.maintenance.1'), []);
        }

        if ($status === 'pending') {
            return $this->result('pending', 'muted', __('servers.diagnosis.pending.0'), __('servers.diagnosis.pending.1'), []);
        }

        if ($status === 'offline') {
            [$up, $down] = self::splitWebsites($this->websites($server));
            $silent = __('servers.diagnosis.silent', ['time' => $server->last_seen_at?->locale(app()->getLocale())->diffForHumans()]);

            foreach ($up as $domain) {
                $evidence[] = __('servers.diagnosis.evidence_up', ['domain' => $domain->displayName(), 'code' => $domain->http_last_status_code]);
            }

            foreach ($down as $domain) {
                $evidence[] = __('servers.diagnosis.evidence_down', ['domain' => $domain->displayName(), 'error' => $domain->http_last_error ?? __('servers.diagnosis.no_answer')]);
            }

            if ($up->isNotEmpty() && $down->isEmpty()) {
                return $this->result('agent_unhealthy', 'yellow', __('servers.diagnosis.agent_unhealthy.0'), __('servers.diagnosis.agent_unhealthy.1', ['silent' => $silent]), $evidence);
            }

            if ($down->isNotEmpty() && $up->isEmpty()) {
                return $this->result('host_unreachable', 'red', __('servers.diagnosis.host_unreachable.0'), __('servers.diagnosis.host_unreachable.1', ['silent' => $silent]), $evidence);
            }

            if ($down->isNotEmpty()) {
                return $this->result('agent_offline_mixed', 'red', __('servers.diagnosis.agent_offline_mixed.0'), __('servers.diagnosis.agent_offline_mixed.1', ['silent' => $silent]), $evidence);
            }

            return $this->result('agent_offline_unknown', 'red', __('servers.diagnosis.agent_offline_unknown.0'), __('servers.diagnosis.agent_offline_unknown.1', ['silent' => $silent]), []);
        }

        // Online: look at services and data quality.
        $failing = $server->serviceChecks()->where('is_enabled', true)->whereIn('status', ServiceCheck::FAILED_STATES)->get();

        if ($failing->isNotEmpty()) {
            foreach ($failing as $service) {
                $evidence[] = "{$service->name}: ".Label::of('service_status', $service->status, ServiceCheck::STATUS_LABELS).($service->state_detail ? " ({$service->state_detail})" : '');
            }

            return $this->result('service_failed', 'red', $failing->count() === 1 ? __('servers.diagnosis.service_failed_one') : __('servers.diagnosis.service_failed_many', ['count' => $failing->count()]), __('servers.diagnosis.service_failed'), $evidence);
        }

        $quality = $this->dataQuality($server);

        if ($quality['issues'] !== []) {
            return $this->result('agent_degraded', 'yellow', __('servers.diagnosis.agent_degraded.0'), __('servers.diagnosis.agent_degraded.1'), $quality['issues']);
        }

        return $this->result('healthy', 'green', __('servers.diagnosis.healthy.0'), __('servers.diagnosis.healthy.1'), []);
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

        // The newest signed release; the setting only before the first import.
        $latestVersion = AgentRelease::latestAvailable()?->version ?? (string) config('serverscope.agent.latest_version');
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
            $issues[] = __('servers.diagnosis.issues.coverage', ['coverage' => $coverage]);
        }

        if ($missing !== []) {
            $issues[] = __('servers.diagnosis.issues.missing', ['fields' => implode(', ', $missing)]);
        }

        if ($outdated) {
            $issues[] = __('servers.diagnosis.issues.outdated', ['version' => $server->agent_version, 'latest' => $latestVersion]);
        }

        if ($integrity === 'unknown_build') {
            $issues[] = __('servers.diagnosis.issues.checksum');
        }

        return [
            'coverage' => $coverage,
            'expected' => $expected,
            'received' => $times->count(),
            'interval_seconds' => $interval,
            'missing_fields' => $missing,
            'outdated' => $outdated,
            'latest_version' => $latestVersion,
            'integrity' => $integrity,
            'issues' => $issues,
        ];
    }

    /** Websites with a confirmed dependency on the server. */
    /**
     * Websites on a silent server that were checked recently, split into
     * answering and failing ones: the independent evidence for the
     * offline diagnosis. Shared by diagnose() and offlineState().
     *
     * @return array{0: Collection<int, Domain>, 1: Collection<int, Domain>}
     */
    public static function splitWebsites(Collection $websites): array
    {
        $recent = $websites->filter(fn (Domain $d) => $d->http_enabled && $d->http_last_checked_at?->gt(now()->subMinutes(self::RECENT_CHECK_MINUTES)));

        return [
            $recent->filter(fn (Domain $d) => $d->http_status === 'up' && $d->http_consecutive_failures === 0),
            $recent->filter(fn (Domain $d) => $d->http_status === 'down' || $d->http_consecutive_failures > 0),
        ];
    }

    /** The offline state diagnose() returns, from already loaded websites (no queries). */
    public static function offlineState(Collection $websites): string
    {
        [$up, $down] = self::splitWebsites($websites);

        return match (true) {
            $up->isNotEmpty() && $down->isEmpty() => 'agent_unhealthy',
            $down->isNotEmpty() && $up->isEmpty() => 'host_unreachable',
            $down->isNotEmpty() => 'agent_offline_mixed',
            default => 'agent_offline_unknown',
        };
    }

    /**
     * The websites of several servers in two queries (same filters as
     * websites()), keyed by server id.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<int, Collection<int, Domain>>
     */
    public function websitesFor(Collection $servers): array
    {
        $out = $servers->mapWithKeys(fn (Server $s) => [$s->id => collect()])->all();

        if ($out === []) {
            return [];
        }

        $edges = ResourceDependency::query()
            ->whereIn('team_id', $servers->pluck('team_id')->unique())
            ->where('dependency_type', 'server')->whereIn('dependency_id', $servers->pluck('id'))
            ->where('dependent_type', 'domain')
            ->get(['team_id', 'dependency_id', 'dependent_id']);
        $domains = Domain::query()->whereIn('id', $edges->pluck('dependent_id')->unique())->where('is_active', true)->get()->keyBy('id');
        $teams = $servers->pluck('team_id', 'id');

        foreach ($edges as $edge) {
            $domain = $domains->get($edge->dependent_id);

            // Same team on both ends, as in websites().
            if ($domain !== null && (int) $domain->team_id === (int) $teams[$edge->dependency_id] && (int) $edge->team_id === (int) $domain->team_id) {
                $out[$edge->dependency_id]->push($domain);
            }
        }

        return $out;
    }

    private function websites(Server $server): Collection
    {
        $ids = ResourceDependency::query()
            ->where('team_id', $server->team_id)
            ->where('dependency_type', 'server')->where('dependency_id', $server->id)
            ->where('dependent_type', 'domain')
            ->pluck('dependent_id');

        return Domain::query()->where('team_id', $server->team_id)->whereIn('id', $ids)->where('is_active', true)->get();
    }

    private function result(string $state, string $tone, string $title, string $explanation, array $evidence): array
    {
        return compact('state', 'tone', 'title', 'explanation', 'evidence');
    }
}
