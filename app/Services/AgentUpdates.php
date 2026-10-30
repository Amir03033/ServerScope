<?php

namespace App\Services;

use App\Models\AgentRelease;
use App\Models\AgentUpdateRequest;
use App\Models\Server;
use App\Models\User;
use App\Releases\ReleaseManifest;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Update requests for agents: a team administrator (or the per-server
 * automatic setting) asks for a signed release, the updater on that
 * server picks it up, verifies it with its own key and reports back.
 * ServerScope never sends anything but a request id and a version.
 */
class AgentUpdates
{
    /** An in_progress request without a result after this long has failed. */
    private const IN_PROGRESS_TIMEOUT_MINUTES = 60;

    public const TERMINAL = ['succeeded', 'rolled_back', 'rejected', 'failed'];

    /**
     * Whether the updater on this server checked in recently and has
     * updates enabled: active, disabled, stale or missing.
     */
    public function updaterState(Server $server): string
    {
        if ($server->updater_last_seen_at === null) {
            return 'missing';
        }

        if ($server->updater_state === 'disabled') {
            return 'disabled';
        }

        $stale = (int) config('serverscope.releases.updater_stale_minutes', 30);

        return $server->updater_last_seen_at->lt(now()->subMinutes($stale)) ? 'stale' : 'active';
    }

    /**
     * What the server page shows.
     *
     * @return array{installed: ?string, latest: ?AgentRelease, updater: string, request: ?AgentUpdateRequest, blocker: ?string}
     */
    public function summary(Server $server): array
    {
        $latest = AgentRelease::latestAvailable();
        $this->expireStale($server);

        return [
            'installed' => $server->agent_version,
            'latest' => $latest,
            'updater' => $this->updaterState($server),
            'request' => $server->updateRequests()->with('requester')->latest('id')->first(),
            'blocker' => $this->blocker($server, $latest),
        ];
    }

    /** Why this server cannot be updated to the release now, or null. */
    public function blocker(Server $server, ?AgentRelease $release): ?string
    {
        return match (true) {
            $release === null => __('servers.updates.blocker.no_release'),
            $server->trashed() => __('servers.updates.blocker.trashed'),
            ! $release->isNewerThan($server->agent_version) => __('servers.updates.blocker.current', ['version' => $server->agent_version]),
            $this->updaterState($server) === 'missing' => __('servers.updates.blocker.missing'),
            $this->updaterState($server) === 'disabled' => __('servers.updates.blocker.disabled'),
            default => null,
        };
    }

    /**
     * Ask the updater of this server to install the release. At most one
     * open request per server: an existing one is returned unchanged.
     */
    public function request(Server $server, AgentRelease $release, ?User $by, string $source): AgentUpdateRequest
    {
        return DB::transaction(function () use ($server, $release, $by, $source): AgentUpdateRequest {
            Server::query()->withoutGlobalScopes()->whereKey($server->id)->lockForUpdate()->first();
            $this->expireStale($server);

            $open = $server->updateRequests()->withoutGlobalScopes()->open()->latest('id')->first();

            if ($open !== null) {
                return $open;
            }

            $request = AgentUpdateRequest::query()->create([
                'team_id' => $server->team_id,
                'server_id' => $server->id,
                'agent_release_id' => $release->id,
                'from_version' => $server->agent_version,
                'target_version' => $release->version,
                'status' => 'pending',
                'source' => $source,
                'requested_by' => $by?->id,
            ]);

            $who = $by ? "door {$by->name}" : 'automatisch';
            Audit::log('agent_update.requested', "Agentupdate {$server->name} naar {$release->version} aangevraagd ({$who})", $server, [
                'request_id' => $request->id,
                'from' => $server->agent_version,
                'version' => $release->version,
                'source' => $source,
            ]);

            return $request;
        });
    }

    /**
     * The updater's check-in: record it and return the request it should
     * work on (or null).
     *
     * @param  array{installed: string, updater: int, state: string}  $checkIn
     */
    public function checkIn(Server $server, array $checkIn): ?AgentUpdateRequest
    {
        $server->forceFill([
            'updater_version' => $checkIn['updater'],
            'updater_state' => $checkIn['state'],
            'updater_last_seen_at' => now(),
        ])->save();

        if ($checkIn['state'] !== 'enabled') {
            return null;
        }

        return DB::transaction(function () use ($server, $checkIn): ?AgentUpdateRequest {
            Server::query()->withoutGlobalScopes()->whereKey($server->id)->lockForUpdate()->first();
            $this->expireStale($server);

            $open = $server->updateRequests()->withoutGlobalScopes()->open()->with('release')->latest('id')->first();

            if ($open?->status === 'in_progress') {
                // Being worked on: never hand out a second one meanwhile.
                return null;
            }

            if ($open !== null && ! $open->release->isNewerThan($checkIn['installed'])) {
                $this->finish($open, 'expired', 'already_installed');
                $open = null;
            }

            if ($open === null && $server->auto_update_enabled) {
                $open = $this->automatic($server, $checkIn['installed']);
            }

            return $open;
        });
    }

    /**
     * A result from the updater.
     *
     * @param  array{status: string, reason?: ?string, installed?: ?string}  $report
     */
    public function report(AgentUpdateRequest $request, array $report): bool
    {
        $status = $report['status'];
        $allowed = match ($request->status) {
            'pending' => ['in_progress', 'rejected', 'failed'],
            'in_progress' => self::TERMINAL,
            default => [],
        };

        if (! in_array($status, $allowed, true)) {
            return false;
        }

        if ($status === 'in_progress') {
            $request->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
            Audit::log('agent_update.started', "Agentupdate {$request->server->name} naar {$request->target_version} gestart", $request->server, [
                'request_id' => $request->id,
                'version' => $request->target_version,
            ]);

            return true;
        }

        $this->finish($request, $status, $report['reason'] ?? null, $report['installed'] ?? null);

        return true;
    }

    /** Open requests for a withdrawn release can no longer be served. */
    public function expireOpenRequestsFor(AgentRelease $release): int
    {
        $count = 0;

        AgentUpdateRequest::query()->withoutGlobalScopes()->where('agent_release_id', $release->id)->where('status', 'pending')->each(function (AgentUpdateRequest $request) use (&$count): void {
            $this->finish($request, 'expired', 'withdrawn');
            $count++;
        });

        return $count;
    }

    private function automatic(Server $server, string $installed): ?AgentUpdateRequest
    {
        $release = AgentRelease::latestAvailable();

        if ($release === null || ! $release->isNewerThan($installed)) {
            return null;
        }

        // Never retry automatically what already went wrong on this server.
        $failedBefore = $server->updateRequests()->withoutGlobalScopes()->where('agent_release_id', $release->id)
            ->whereIn('status', ['rolled_back', 'rejected', 'failed'])->exists();

        return $failedBefore ? null : $this->request($server, $release, null, 'auto');
    }

    private function expireStale(Server $server): void
    {
        $ttl = max(1, (int) config('serverscope.releases.pending_ttl_hours', 24));

        $server->updateRequests()->withoutGlobalScopes()->where('status', 'pending')->where('created_at', '<', now()->subHours($ttl))
            ->each(fn (AgentUpdateRequest $request) => $this->finish($request, 'expired', 'stale'));
        $server->updateRequests()->withoutGlobalScopes()->where('status', 'in_progress')->where('started_at', '<', now()->subMinutes(self::IN_PROGRESS_TIMEOUT_MINUTES))
            ->each(fn (AgentUpdateRequest $request) => $this->finish($request, 'failed', 'timeout'));
    }

    private function finish(AgentUpdateRequest $request, string $status, ?string $reason, ?string $installed = null): void
    {
        $request->forceFill(['status' => $status, 'reason' => $reason, 'finished_at' => now()])->save();

        $label = AgentUpdateRequest::STATUS_LABELS[$status] ?? $status;
        $server = $request->server;
        Audit::log("agent_update.{$status}", "Agentupdate {$server->name} naar {$request->target_version}: {$label}".($reason ? " ({$request->reasonLabel()})" : ''), $server, [
            'request_id' => $request->id,
            'version' => $request->target_version,
            'reason' => $reason,
            'installed' => ReleaseManifest::isVersion($installed) ? $installed : null,
        ]);
    }
}
