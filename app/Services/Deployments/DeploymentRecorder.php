<?php

namespace App\Services\Deployments;

use App\Models\Deployment;
use App\Models\GithubCommit;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a deployment that was detected automatically (by the agent or a
 * GitHub webhook) and fills in commit details GitHub already sent. A
 * delivery or observation that arrives twice lands on the same row
 * (external_id is unique per team). Runs inside the team's tenant scope.
 */
class DeploymentRecorder
{
    /**
     * @param  array{application: string, environment?: string, commit_sha?: ?string, title?: ?string, actor?: ?string, url?: ?string, version?: ?string, source: string, external_id: string}  $data
     * @param  array<int, array{type: string, id: int}>  $resources
     */
    public function record(array $data, array $resources): Deployment
    {
        if ($existing = Deployment::query()->where('external_id', $data['external_id'])->first()) {
            return $existing;
        }

        $data['commit_sha'] = isset($data['commit_sha']) ? strtolower($data['commit_sha']) : null;
        $data = [...$data, ...array_filter($this->details($data['commit_sha']), fn ($v) => $v !== null), ...array_filter($data, fn ($v) => $v !== null)];

        try {
            $deployment = DB::transaction(function () use ($data, $resources) {
                $deployment = new Deployment([
                    'application' => mb_substr($data['application'], 0, 100),
                    'environment' => mb_substr($data['environment'] ?? 'production', 0, 50),
                    'version' => isset($data['version']) ? mb_substr($data['version'], 0, 100) : null,
                    'title' => isset($data['title']) ? mb_substr($data['title'], 0, 255) : null,
                    'commit_sha' => $data['commit_sha'],
                    'deployed_at' => now(),
                    'source' => $data['source'],
                    'actor' => isset($data['actor']) ? mb_substr($data['actor'], 0, 100) : null,
                    'url' => $data['url'] ?? null,
                    'external_id' => mb_substr($data['external_id'], 0, 191),
                ]);
                $deployment->save();

                $rows = collect($resources)->unique(fn ($r) => $r['type'].':'.$r['id'])
                    ->map(fn ($r) => ['deployment_id' => $deployment->id, 'resource_type' => $r['type'], 'resource_id' => (int) $r['id']])
                    ->values()->all();

                if ($rows !== []) {
                    DB::table('deployment_resources')->insert($rows);
                }

                return $deployment;
            });
        } catch (UniqueConstraintViolationException) {
            // The same observation, reported twice at the same moment.
            return Deployment::query()->where('external_id', $data['external_id'])->firstOrFail();
        }

        Audit::log('deployment.recorded', "Deployment {$deployment->label()} automatisch vastgelegd ({$deployment->source})", $deployment);

        return $deployment;
    }

    /** Fill in title, author and link on earlier deployments of this commit that lack them. */
    public function enrich(GithubCommit $commit): void
    {
        $details = $this->present($commit);

        Deployment::query()->where('commit_sha', strtolower($commit->sha))->get()
            ->each(function (Deployment $deployment) use ($details): void {
                foreach (['title', 'actor', 'url'] as $field) {
                    if ($deployment->{$field} === null && $details[$field] !== null) {
                        $deployment->{$field} = $details[$field];
                    }
                }

                $deployment->save();
            });
    }

    /** @return array{title: ?string, actor: ?string, url: ?string} */
    private function details(?string $sha): array
    {
        $commit = $sha ? GithubCommit::query()->where('sha', $sha)->latest('updated_at')->first() : null;

        return $commit ? $this->present($commit) : ['title' => null, 'actor' => null, 'url' => null];
    }

    /** @return array{title: ?string, actor: ?string, url: ?string} */
    private function present(GithubCommit $commit): array
    {
        return [
            'title' => $commit->pr_number ? "{$commit->title} (#{$commit->pr_number})" : $commit->title,
            'actor' => $commit->author,
            'url' => $commit->pr_url ?? $commit->url,
        ];
    }
}
