<?php

namespace App\Services\Deployments;

use App\Models\DeployWatch;
use App\Models\ResourceDependency;
use App\Models\Server;
use Illuminate\Support\Carbon;

/**
 * What the agent saw in the git checkouts it watches. The first time a
 * checkout is seen only sets the starting point; after that, a different
 * commit is a deployment of that checkout on this server, linked to the
 * server and to the websites that depend on it.
 */
class DeployWatcher
{
    public const MAX_WATCHES = 10;

    public function __construct(private DeploymentRecorder $recorder) {}

    /** @param  array<int, array{name: string, branch?: ?string, commit_sha?: ?string, error?: ?string}>  $observed */
    public function observe(Server $server, array $observed, Carbon $at): void
    {
        foreach (array_slice($observed, 0, self::MAX_WATCHES) as $item) {
            $watch = DeployWatch::query()->firstOrNew(['server_id' => $server->id, 'name' => $item['name']]);
            $sha = isset($item['commit_sha']) ? strtolower($item['commit_sha']) : null;
            $isNew = ! $watch->exists;
            $changed = ! $isNew && $sha !== null && $watch->commit_sha !== null && $watch->commit_sha !== $sha;

            $watch->forceFill([
                'team_id' => $server->team_id,
                'branch' => $item['branch'] ?? ($sha ? null : $watch->branch),
                'commit_sha' => $sha ?? $watch->commit_sha,
                'error' => $item['error'] ?? null,
                'last_seen_at' => $at,
                'changed_at' => $changed ? $at : $watch->changed_at,
            ])->save();

            if ($changed) {
                $this->recorder->record([
                    'application' => $watch->name,
                    'environment' => $server->environment ?: 'production',
                    'version' => $watch->branch,
                    'commit_sha' => $sha,
                    'source' => 'agent',
                    'external_id' => "agent:{$server->id}:{$watch->name}:{$sha}",
                ], $this->resources($server));
            }
        }
    }

    /** @return array<int, array{type: string, id: int}> */
    private function resources(Server $server): array
    {
        $websites = ResourceDependency::query()
            ->where('dependency_type', 'server')->where('dependency_id', $server->id)
            ->where('dependent_type', 'domain')
            ->pluck('dependent_id')
            ->map(fn ($id) => ['type' => 'domain', 'id' => (int) $id])
            ->all();

        return [['type' => 'server', 'id' => $server->id], ...$websites];
    }
}
