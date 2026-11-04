<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeployWatch;
use App\Models\GithubCommit;
use App\Models\GithubConnection;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TwoTeams;
use Tests\TestCase;

/** Deployments that arrive by themselves: the agent watching git folders, and GitHub webhooks. */
class AutomaticDeploymentsTest extends TestCase
{
    use RefreshDatabase;
    use TwoTeams;

    private const SHA1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SHA2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $agentToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTwoTeams();

        $this->agentToken = bin2hex(random_bytes(32));
        $this->a['server']->agentCredentials()->create(['name' => 'deploy test', 'token_hash' => hash('sha256', $this->agentToken)]);
    }

    private function sample(string $team, array $deploys, int $secondsAgo): void
    {
        $this->withToken($this->agentToken)->postJson('/api/agent/metrics', [
            'collected_at' => now()->subSeconds($secondsAgo)->toIso8601String(),
            'cpu_percent' => 5,
            'deploys' => $deploys,
        ])->assertCreated();
    }

    public function test_a_new_commit_in_a_watched_folder_is_a_deployment(): void
    {
        // The first look only sets the starting point.
        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA1]], 40);
        $this->assertSame(0, Deployment::query()->withoutGlobalScopes()->count());

        // Same commit: nothing new.
        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA1]], 30);
        $this->assertSame(0, Deployment::query()->withoutGlobalScopes()->count());

        // A git pull later: one deployment, on the server and the website that runs on it.
        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA2]], 20);
        $deployment = Deployment::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['shop', self::SHA2, 'agent', $this->a['team']->id], [$deployment->application, $deployment->commit_sha, $deployment->source, $deployment->team_id]);
        $this->assertEqualsCanonicalizing([['type' => 'server', 'id' => $this->a['server']->id], ['type' => 'domain', 'id' => $this->a['domain']->id]], $deployment->resourceRefs());

        // Reported again: still one.
        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA2]], 10);
        $this->assertSame(1, Deployment::query()->withoutGlobalScopes()->count());
    }

    public function test_a_folder_the_agent_cannot_read_is_shown_as_such(): void
    {
        $this->sample('alpha', [['name' => 'shop', 'error' => 'unreadable']], 10);

        $watch = DeployWatch::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['unreadable', $this->a['team']->id], [$watch->error, $watch->team_id]);

        $this->actingAs($this->a['member'])->get(route('deploy-sources.index'))->assertOk()
            ->assertSee(__('deploy_sources.agent.errors.unreadable'))->assertDontSee('bravo');
    }

    public function test_bad_deploy_data_is_rejected_and_old_agents_keep_working(): void
    {
        $this->withToken($this->agentToken)->postJson('/api/agent/metrics', [
            'collected_at' => now()->toIso8601String(),
            'deploys' => [['name' => 'shop; rm -rf /', 'commit_sha' => 'not-a-sha']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['deploys.0.name', 'deploys.0.commit_sha']);

        $this->withToken($this->agentToken)->postJson('/api/agent/metrics', ['collected_at' => now()->toIso8601String(), 'cpu_percent' => 5])->assertCreated();
        $this->assertSame(0, DeployWatch::query()->withoutGlobalScopes()->count());
    }

    private function connection(string $deployOn = 'agent', array $attributes = []): GithubConnection
    {
        return Tenant::run($this->a['team'], function () use ($deployOn, $attributes) {
            $connection = new GithubConnection(['repository' => 'acme/shop', 'application' => 'shop', 'environment' => 'production', 'deploy_on' => $deployOn, 'resources' => [['type' => 'domain', 'id' => $this->a['domain']->id]], ...$attributes]);
            $connection->forceFill(['uuid' => (string) Str::uuid(), 'secret' => 'test-secret'])->save();

            return $connection;
        });
    }

    private function deliver(GithubConnection $connection, string $event, array $payload, string $secret = 'test-secret')
    {
        $body = json_encode(['repository' => ['full_name' => 'acme/shop', 'default_branch' => 'main'], ...$payload]);

        return $this->call('POST', route('webhooks.github', $connection->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    private function push(string $sha, string $message = "Fix checkout\n\nDetails", string $branch = 'main'): array
    {
        $commit = ['id' => $sha, 'message' => $message, 'url' => "https://github.com/acme/shop/commit/{$sha}", 'author' => ['username' => 'amir'], 'timestamp' => now()->toIso8601String()];

        return ['ref' => "refs/heads/{$branch}", 'after' => $sha, 'compare' => 'https://github.com/acme/shop/compare/x...y', 'pusher' => ['name' => 'amir'], 'commits' => [$commit], 'head_commit' => $commit];
    }

    public function test_github_deliveries_must_be_signed_with_the_secret(): void
    {
        $connection = $this->connection();

        $this->deliver($connection, 'ping', [], 'wrong-secret')->assertUnauthorized();
        $this->deliver($connection, 'ping', [])->assertOk();
        $this->assertNotNull($connection->fresh()->last_delivery_at);

        $this->call('POST', route('webhooks.github', (string) Str::uuid()), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
    }

    public function test_github_fills_in_the_commit_and_pull_request_of_an_agent_deployment(): void
    {
        $connection = $this->connection();

        // GitHub first (merge, push), then the agent sees the deploy.
        $this->deliver($connection, 'push', $this->push(self::SHA2))->assertOk();
        $this->deliver($connection, 'pull_request', ['action' => 'closed', 'pull_request' => ['merged' => true, 'merge_commit_sha' => self::SHA2, 'title' => 'New checkout flow', 'number' => 42, 'html_url' => 'https://github.com/acme/shop/pull/42', 'user' => ['login' => 'amir'], 'merged_at' => now()->toIso8601String()]])->assertOk();
        $this->assertSame(0, Deployment::query()->withoutGlobalScopes()->count(), 'details only: the agent sees the deploy');

        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA1]], 20);
        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA2]], 10);

        $deployment = Deployment::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['New checkout flow (#42)', 'amir', 'https://github.com/acme/shop/pull/42'], [$deployment->title, $deployment->actor, $deployment->url]);
    }

    public function test_details_arriving_after_the_deploy_are_added_later(): void
    {
        $connection = $this->connection();
        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA1]], 20);
        $this->sample('alpha', [['name' => 'shop', 'branch' => 'main', 'commit_sha' => self::SHA2]], 10);

        $this->deliver($connection, 'push', $this->push(self::SHA2, 'Hotfix prices'))->assertOk();

        $this->assertSame('Hotfix prices', Deployment::query()->withoutGlobalScopes()->sole()->title);
    }

    public function test_with_deploy_on_push_a_push_to_the_branch_is_a_deployment(): void
    {
        $connection = $this->connection('push');

        $this->deliver($connection, 'push', $this->push(self::SHA1, 'Feature work', 'feature/x'))->assertOk();
        $this->assertSame(0, Deployment::query()->withoutGlobalScopes()->count(), 'other branch');

        $this->deliver($connection, 'push', $this->push(self::SHA2))->assertOk();
        $this->deliver($connection, 'push', $this->push(self::SHA2))->assertOk();

        $deployment = Deployment::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['github', 'Fix checkout', 'main', $this->a['team']->id], [$deployment->source, $deployment->title, $deployment->version, $deployment->team_id]);
        $this->assertSame([['type' => 'domain', 'id' => $this->a['domain']->id]], $deployment->resourceRefs());
    }

    public function test_a_successful_github_deployment_status_is_a_deployment(): void
    {
        $connection = $this->connection();
        $payload = ['deployment' => ['id' => 99, 'sha' => self::SHA1, 'ref' => 'main', 'environment' => 'Production EU'], 'deployment_status' => ['state' => 'success', 'target_url' => 'https://github.com/acme/shop/actions/runs/1', 'creator' => ['login' => 'ci-bot']]];

        $this->deliver($connection, 'deployment_status', ['deployment_status' => ['state' => 'failure']] + $payload)->assertStatus(202);
        $this->deliver($connection, 'deployment_status', $payload)->assertOk();

        $deployment = Deployment::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['Production-EU', 'ci-bot', 'https://github.com/acme/shop/actions/runs/1'], [$deployment->environment, $deployment->actor, $deployment->url]);
    }

    public function test_a_delivery_for_another_repository_changes_nothing(): void
    {
        $connection = $this->connection('push');
        $body = json_encode(['repository' => ['full_name' => 'evil/other', 'default_branch' => 'main'], ...$this->push(self::SHA1)]);

        $this->call('POST', route('webhooks.github', $connection->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertStatus(202);

        $this->assertSame(0, GithubCommit::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Deployment::query()->withoutGlobalScopes()->count());
    }

    public function test_an_admin_connects_a_repository_and_sees_the_secret_once(): void
    {
        $response = $this->actingAs($this->a['admin'])->post(route('deploy-sources.github.store'), [
            'repository' => 'acme/shop', 'application' => 'shop', 'environment' => 'production', 'deploy_on' => 'agent',
            'resources' => ["domain:{$this->a['domain']->id}", "server:{$this->b['server']->id}"],
        ]);
        $response->assertRedirect(route('deploy-sources.index'));

        $connection = GithubConnection::query()->withoutGlobalScopes()->sole();
        $this->assertSame([['type' => 'domain', 'id' => $this->a['domain']->id]], $connection->resources, 'another team\'s server is dropped');
        $secret = session('github_secret')['secret'];
        $this->assertSame(40, strlen($secret));
        $this->assertNotSame($secret, $connection->getRawOriginal('secret'), 'stored encrypted');

        $this->followRedirects($response)->assertSee($secret)->assertSee($connection->webhookUrl());
        $this->get(route('deploy-sources.index'))->assertOk()->assertDontSee($secret);

        // Members see the page but cannot connect.
        $this->actingAs($this->a['member'])->post(route('deploy-sources.github.store'), ['repository' => 'acme/other', 'application' => 'x', 'environment' => 'production', 'deploy_on' => 'agent'])->assertForbidden();
        $this->actingAs($this->b['admin'])->delete(route('deploy-sources.github.destroy', $connection))->assertNotFound();
    }
}
