<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Services\Domains\DomainStatus;
use App\Services\ServerDiagnosis;
use Illuminate\Support\Collection;

/**
 * One health vocabulary for servers and websites, derived only from the
 * existing monitoring logic (Server::displayStatus, ServerDiagnosis,
 * DomainStatus, incident severities); nothing is calculated twice.
 *
 * healthy · warning · critical · unknown · offline · maintenance · paused
 *
 * A server is "offline" only with independent evidence (ServerDiagnosis:
 * websites on it fail as well). A silent agent without such evidence is
 * "unknown": missing data is not proof that the host is down.
 */
class Health
{
    public const STATES = ['healthy', 'warning', 'critical', 'unknown', 'offline', 'maintenance', 'paused'];

    /**
     * @param  ?string  $incidentSeverity  highest severity of the server's active root incidents
     * @param  ?Collection  $websites  preloaded websites on the server (ServerDiagnosis::websitesFor), to avoid queries per row
     */
    public static function server(Server $server, ?string $incidentSeverity = null, ?Collection $websites = null): string
    {
        return match ($server->displayStatus()) {
            'maintenance' => 'maintenance',
            'pending' => 'unknown',
            'offline' => ($websites !== null ? ServerDiagnosis::offlineState($websites) : app(ServerDiagnosis::class)->diagnose($server)['state']) === 'host_unreachable' ? 'offline' : 'unknown',
            default => match (true) {
                $incidentSeverity === 'critical' => 'critical',
                in_array($incidentSeverity, ['high', 'warning'], true) => 'warning',
                default => 'healthy',
            },
        };
    }

    public static function domain(Domain $domain): string
    {
        return match (true) {
            ! $domain->is_active => 'paused',
            $domain->isInMaintenance() => 'maintenance',
            default => DomainStatus::calculate($domain),
        };
    }

    /**
     * Highest severity of active root incidents per server id (one query).
     *
     * @param  Collection<int, Server>|array<int, int>  $servers
     * @return array<int, string>
     */
    public static function serverIncidentSeverities(Collection|array $servers): array
    {
        $ids = collect($servers)->map(fn ($s) => $s instanceof Server ? $s->id : (int) $s)->all();

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (Incident::query()->active()->whereNull('parent_id')->where('subject_type', 'server')->whereIn('subject_id', $ids)->get(['subject_id', 'severity']) as $incident) {
            $current = $out[$incident->subject_id] ?? null;
            $out[$incident->subject_id] = Severity::rank($incident->severity) > Severity::rank($current) ? $incident->severity : $current;
        }

        return $out;
    }
}
