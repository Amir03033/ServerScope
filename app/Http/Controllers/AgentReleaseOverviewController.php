<?php

namespace App\Http\Controllers;

use App\Models\AgentRelease;
use App\Models\Server;
use App\Services\AgentUpdates;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Platform administrators: imported releases and how many servers run
 * each version. Counts only, never servers or teams.
 */
class AgentReleaseOverviewController extends Controller
{
    public function __invoke(AgentUpdates $updates): View
    {
        Gate::authorize('platform');

        $servers = Server::query()->withoutGlobalScopes()->whereNull('deleted_at')->get(['id', 'agent_version', 'updater_state', 'updater_last_seen_at']);

        $versions = $servers->groupBy(fn (Server $server) => $server->agent_version ?? 'onbekend')
            ->map(fn ($group, $version) => [
                'version' => (string) $version,
                'servers' => $group->count(),
                'with_updater' => $group->filter(fn (Server $server) => $updates->updaterState($server) === 'active')->count(),
            ])
            ->sortByDesc(fn (array $row) => $row['version'] === 'onbekend' ? '' : sprintf('%05d.%05d.%05d', ...array_map('intval', array_pad(explode('.', $row['version']), 3, 0))))
            ->values();

        return view('platform.agent-releases', [
            'releases' => AgentRelease::query()->newestFirst()->get(),
            'versions' => $versions,
            'total' => $servers->count(),
        ]);
    }
}
