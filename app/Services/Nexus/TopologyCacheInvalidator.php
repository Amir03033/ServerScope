<?php

namespace App\Services\Nexus;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Models\MonitoredPage;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache policy of the NEXUS topology: cached per team for at most
 * TopologyBuilder::CACHE_TTL_SECONDS.
 *
 * Cleared at once (for the team of the changed record only):
 * - any change to an incident, an incident impact or a dependency;
 * - a server, domain, page or service being added, deleted, restored;
 * - edits made by people: names, maintenance windows, pausing, page
 *   approval, enabling a service.
 *
 * Not cleared, so visible within CACHE_TTL_SECONDS: everything the
 * monitoring itself updates - heartbeats (online/offline), metrics,
 * domain/HTTP/SSL/DNS check results, page check results and service
 * states reported by the agent. The topology reports when it was built
 * (meta.generated_at).
 *
 * Inside a transaction the entry is cleared both at once and after the
 * commit, so a request that rebuilds it in between cannot keep the
 * uncommitted (old) state for a full TTL.
 */
class TopologyCacheInvalidator
{
    /** Fields edited by people that change what the map shows. */
    public const WATCHED_FIELDS = [
        Server::class => ['name', 'environment', 'maintenance_starts_at', 'maintenance_ends_at'],
        Domain::class => ['name', 'is_active', 'maintenance_starts_at', 'maintenance_ends_at'],
        MonitoredPage::class => ['domain_id', 'approval_status', 'is_active', 'title', 'url'],
        ServiceCheck::class => ['server_id', 'name', 'display_name', 'is_enabled'],
    ];

    public static function register(): void
    {
        $forget = fn (Model $model) => self::forget($model, Tenancy::teamIdOf($model));

        foreach ([Incident::class, ResourceDependency::class] as $class) {
            $class::saved($forget);
            $class::deleted($forget);
        }

        // Impacts have no team_id: they belong to the team of their incident.
        $forgetImpact = fn (IncidentImpact $impact) => self::forget($impact,
            Incident::query()->withoutGlobalScopes()->whereKey($impact->incident_id)->value('team_id'),
        );
        IncidentImpact::saved($forgetImpact);
        IncidentImpact::deleted($forgetImpact);

        foreach (self::WATCHED_FIELDS as $class => $fields) {
            $class::created($forget);
            $class::deleted($forget);
            $class::updated(fn (Model $model) => $model->wasChanged($fields) ? $forget($model) : null);
        }

        Server::restored($forget);
        Domain::restored($forget);
    }

    private static function forget(Model $model, ?int $teamId): void
    {
        TopologyBuilder::forget($teamId);

        $connection = $model->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(fn () => TopologyBuilder::forget($teamId));
        }
    }
}
