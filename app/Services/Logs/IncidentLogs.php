<?php

namespace App\Services\Logs;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\ResourceDependency;
use App\Models\Server;
use Carbon\CarbonImmutable;

/**
 * Which log lines belong to an incident: those of the servers involved
 * (the incident's server, the servers a website runs on, servers among
 * the affected resources) from shortly before the first failure until
 * shortly after recovery.
 */
class IncidentLogs
{
    public const BEFORE_MINUTES = 15;

    public const AFTER_MINUTES = 10;

    /** @return array<int, int> */
    public function servers(Incident $incident): array
    {
        $ids = [];
        $resources = collect([$incident->subject, ...$incident->impacts()->with('resource')->get()->pluck('resource')])->filter();

        foreach ($resources as $resource) {
            if ($resource instanceof Server) {
                $ids[] = $resource->id;
            } elseif ($resource instanceof Domain) {
                array_push($ids, ...ResourceDependency::query()->where('dependent_type', 'domain')->where('dependent_id', $resource->id)
                    ->where('dependency_type', 'server')->pluck('dependency_id')->map(fn ($id) => (int) $id)->all());
            } elseif (isset($resource->server_id)) {
                $ids[] = (int) $resource->server_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function window(Incident $incident): array
    {
        $start = CarbonImmutable::parse($incident->started_at ?? $incident->opened_at)->subMinutes(self::BEFORE_MINUTES);
        $end = $incident->resolved_at ? CarbonImmutable::parse($incident->resolved_at)->addMinutes(self::AFTER_MINUTES) : CarbonImmutable::now();

        return [$start, $end->min(CarbonImmutable::now())->max($start->addMinutes(30))];
    }
}
