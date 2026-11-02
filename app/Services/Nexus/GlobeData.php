<?php

namespace App\Services\Nexus;

use App\Models\Domain;
use App\Models\Probe;
use App\Models\ProbeCheck;
use App\Support\Time;

/**
 * Data for the NEXUS globe of one team: the measuring locations and, per
 * website, whether it was reachable from each of them.
 *
 * - The main location (where ServerScope runs; config probe.main_location)
 *   checks every website continuously: its state is the website's current
 *   HTTP status.
 * - Remote probes are platform infrastructure shared by all teams (their
 *   names already appear on website pages). They check a website only to
 *   confirm a failure, so their state is the outcome of the latest
 *   confirmation of the last WINDOW_DAYS days, with its time, or unknown.
 * - Only the team's own websites and probe checks are read (explicit
 *   team filters); probes without coordinates are counted, not placed.
 */
class GlobeData
{
    public const WINDOW_DAYS = 7;

    public const MAX_SITES = 500;

    /** Outcome of a confirmation => [state seen from the main location, state seen from the probe]. */
    private const OUTCOMES = [
        'confirmed' => ['down', 'down'],
        'main_only' => ['down', 'ok'],
        'probe_only' => ['ok', 'down'],
        'not_reproduced' => ['ok', 'ok'],
        'unavailable' => [null, 'unknown'],
    ];

    /** @return array<string, mixed> */
    public function forTeam(int $teamId): array
    {
        $locations = [];
        $main = config('serverscope.probe.main_location', []);

        if ($place = Probe::coordinates($main['latitude'] ?? null, $main['longitude'] ?? null)) {
            $locations[] = ['key' => 'main', 'kind' => 'main', 'name' => $main['name'] ?: null, 'location' => $main['name'] ?: null, 'lat' => $place[0], 'lon' => $place[1], 'healthy' => true, 'last_seen_at' => null];
        }

        $probes = Probe::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'location', 'latitude', 'longitude', 'is_active', 'last_seen_at']);
        $unplaced = 0;

        foreach ($probes as $probe) {
            if (! $probe->isPlaced()) {
                $unplaced++;

                continue;
            }

            $locations[] = ['key' => 'probe:'.$probe->id, 'kind' => 'probe', 'name' => $probe->name, 'location' => $probe->location, 'lat' => $probe->latitude, 'lon' => $probe->longitude, 'healthy' => $probe->isHealthy(), 'last_seen_at' => $probe->last_seen_at?->toIso8601String()];
        }

        $domains = Domain::query()->withoutGlobalScopes()->where('team_id', $teamId)->whereNull('deleted_at')->orderBy('name')
            ->limit(self::MAX_SITES + 1)
            ->get(['id', 'team_id', 'name', 'scheme', 'path', 'is_active', 'status', 'http_status', 'http_last_checked_at', 'maintenance_starts_at', 'maintenance_ends_at']);
        $truncated = $domains->count() > self::MAX_SITES;
        $domains = $domains->take(self::MAX_SITES);

        // Latest confirmation per (website, probe), this team's only.
        $latest = [];
        $checks = ProbeCheck::query()->withoutGlobalScopes()->where('team_id', $teamId)->whereIn('domain_id', $domains->pluck('id'))
            ->whereNotNull('outcome')->where('requested_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('requested_at')->orderByDesc('id')->limit(5000)
            ->get(['id', 'domain_id', 'probe_id', 'outcome', 'requested_at', 'checked_at']);

        foreach ($checks as $check) {
            $latest[$check->domain_id]['probe:'.$check->probe_id] ??= $check;
        }

        $placed = array_flip(array_column($locations, 'key'));
        $sites = [];

        foreach ($domains as $domain) {
            $status = $domain->displayStatus();
            $reach = [];

            if (isset($placed['main'])) {
                $reach['main'] = [
                    'state' => match (true) {
                        in_array($status, ['paused', 'maintenance'], true) => $status,
                        $domain->http_status === 'up' => 'ok',
                        $domain->http_status === 'down' => 'down',
                        default => 'unknown',
                    },
                    'at' => $domain->http_last_checked_at?->toIso8601String(),
                    'source' => 'monitor',
                ];
            }

            foreach ($latest[$domain->id] ?? [] as $key => $check) {
                if (! isset($placed[$key])) {
                    continue;
                }

                $reach[$key] = ['state' => self::OUTCOMES[$check->outcome][1] ?? 'unknown', 'at' => ($check->checked_at ?? $check->requested_at)?->toIso8601String(), 'source' => 'confirmation', 'outcome' => $check->outcome];
            }

            $sites[] = ['id' => $domain->id, 'name' => $domain->displayName(), 'status' => $status, 'url' => route('domains.show', $domain->id), 'reach' => $reach];
        }

        return [
            'locations' => $locations,
            'sites' => $sites,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'window_days' => self::WINDOW_DAYS,
                'unplaced_probes' => $unplaced,
                'main_placed' => isset($placed['main']),
                'truncated' => $truncated,
                'zone' => Time::zone(),
            ],
        ];
    }
}
