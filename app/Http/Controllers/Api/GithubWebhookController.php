<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubCommit;
use App\Models\GithubConnection;
use App\Services\Deployments\DeploymentRecorder;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GitHub webhook of one connected repository (/api/webhooks/github/{uuid}).
 *
 * Every delivery is checked against the connection's secret
 * (X-Hub-Signature-256) and must come from the connected repository.
 * - push: remembers the commits (title, author, link); with deploy_on
 *   "push" a push to the branch is a deployment of its head commit.
 * - pull_request closed + merged: the merge commit gets the PR title,
 *   number and link.
 * - deployment_status success: a deployment (e.g. GitHub Actions).
 * Other events are acknowledged and ignored. Nothing in a payload is
 * executed or fetched.
 */
class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, string $uuid, DeploymentRecorder $recorder): JsonResponse
    {
        $connection = GithubConnection::query()->withoutGlobalScopes()->where('uuid', $uuid)->first();
        abort_if($connection === null, 404);

        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $connection->secret);
        abort_unless($signature !== '' && hash_equals($expected, $signature), 401, 'Invalid signature.');

        $event = (string) $request->header('X-GitHub-Event');
        $payload = $request->json()->all();
        $repository = (string) data_get($payload, 'repository.full_name');

        if ($event !== 'ping' && strcasecmp($repository, $connection->repository) !== 0) {
            return response()->json(['message' => 'Other repository; ignored.'], 202);
        }

        return Tenant::run($connection->team_id, function () use ($connection, $event, $payload, $recorder) {
            $connection->forceFill(['last_delivery_at' => now(), 'last_event' => mb_substr($event, 0, 50)])->save();

            $result = match ($event) {
                'push' => $this->push($connection, $payload, $recorder),
                'pull_request' => $this->pullRequest($connection, $payload, $recorder),
                'deployment_status' => $this->deploymentStatus($connection, $payload, $recorder),
                'ping' => 'connected',
                default => 'ignored',
            };

            return response()->json(['message' => $result], $result === 'ignored' ? 202 : 200);
        });
    }

    private function push(GithubConnection $connection, array $payload, DeploymentRecorder $recorder): string
    {
        $branch = str_starts_with((string) ($payload['ref'] ?? ''), 'refs/heads/') ? substr($payload['ref'], 11) : null;

        foreach (array_slice((array) ($payload['commits'] ?? []), -50) as $commit) {
            $this->remember($connection, $commit, $recorder);
        }

        $head = $payload['head_commit'] ?? null;

        if (! is_array($head) || ! $this->sha($head['id'] ?? null)) {
            return 'stored';
        }

        $this->remember($connection, $head, $recorder);

        $wanted = $connection->branch ?: data_get($payload, 'repository.default_branch');

        if ($connection->deploy_on !== 'push' || $branch === null || $branch !== $wanted || ($payload['deleted'] ?? false)) {
            return 'stored';
        }

        $recorder->record([
            'application' => $connection->application,
            'environment' => $connection->environment,
            'version' => $branch,
            'commit_sha' => $head['id'],
            'actor' => $this->text(data_get($payload, 'pusher.name'), 100),
            'url' => $this->url($payload['compare'] ?? null) ?? $this->url($head['url'] ?? null),
            'source' => 'github',
            'external_id' => 'github:push:'.$connection->id.':'.$head['id'],
        ], $connection->resourceRefs());

        return 'deployment';
    }

    private function pullRequest(GithubConnection $connection, array $payload, DeploymentRecorder $recorder): string
    {
        $pr = $payload['pull_request'] ?? null;

        if (($payload['action'] ?? null) !== 'closed' || ! is_array($pr) || ! ($pr['merged'] ?? false) || ! $this->sha($pr['merge_commit_sha'] ?? null)) {
            return 'ignored';
        }

        $commit = GithubCommit::query()->firstOrNew(['github_connection_id' => $connection->id, 'sha' => strtolower($pr['merge_commit_sha'])]);
        $commit->forceFill([
            'team_id' => $connection->team_id,
            'title' => $this->text($pr['title'] ?? null, 255),
            'author' => $this->text(data_get($pr, 'user.login'), 100),
            'pr_number' => is_int($pr['number'] ?? null) ? $pr['number'] : null,
            'pr_url' => $this->url($pr['html_url'] ?? null),
            'committed_at' => $this->time($pr['merged_at'] ?? null),
        ])->save();
        $recorder->enrich($commit);

        return 'stored';
    }

    private function deploymentStatus(GithubConnection $connection, array $payload, DeploymentRecorder $recorder): string
    {
        $status = $payload['deployment_status'] ?? [];
        $deployment = $payload['deployment'] ?? [];

        if (($status['state'] ?? null) !== 'success' || ! $this->sha($deployment['sha'] ?? null) || ! is_int($deployment['id'] ?? null)) {
            return 'ignored';
        }

        $environment = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($deployment['environment'] ?? '')) ?: $connection->environment;

        $recorder->record([
            'application' => $connection->application,
            'environment' => mb_substr($environment, 0, 50),
            'version' => $this->text($deployment['ref'] ?? null, 100),
            'commit_sha' => $deployment['sha'],
            'actor' => $this->text(data_get($status, 'creator.login') ?? data_get($deployment, 'creator.login'), 100),
            'url' => $this->url($status['target_url'] ?? null) ?? $this->url($status['log_url'] ?? null) ?? $this->url($status['environment_url'] ?? null),
            'source' => 'github',
            'external_id' => 'github:deployment:'.$connection->id.':'.$deployment['id'],
        ], $connection->resourceRefs());

        return 'deployment';
    }

    private function remember(GithubConnection $connection, mixed $commit, DeploymentRecorder $recorder): void
    {
        if (! is_array($commit) || ! $this->sha($commit['id'] ?? null)) {
            return;
        }

        $row = GithubCommit::query()->firstOrNew(['github_connection_id' => $connection->id, 'sha' => strtolower($commit['id'])]);

        // A pull request title, once known, stays: it says more than the merge commit message.
        if ($row->pr_number === null) {
            $row->title = $this->text(strtok((string) ($commit['message'] ?? ''), "\n"), 255);
        }

        $row->forceFill([
            'team_id' => $connection->team_id,
            'author' => $row->author ?? $this->text(data_get($commit, 'author.username') ?? data_get($commit, 'author.name'), 100),
            'url' => $this->url($commit['url'] ?? null),
            'committed_at' => $this->time($commit['timestamp'] ?? null),
        ])->save();
        $recorder->enrich($row);
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-fA-F]{7,64}$/', $value) === 1;
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    /** Only https links to GitHub-like hosts are kept; they are shown as links. */
    private function url(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 2048 && preg_match('#^https://[^\s/]+/#', $value) === 1 ? $value : null;
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_string($value) ? Carbon::parse($value)->utc() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
