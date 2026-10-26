<?php

namespace Tests\Feature;

use App\Jobs\RunPageCheck;
use App\Jobs\RunPageDiscovery;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Models\Prediction;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Monitoring\Dns\Resolver;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\TestCase;

class MonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $dns = new FakeResolver;
        $dns->host('example.com', '93.184.215.14');
        $dns->host('intern.example.com', '10.1.1.1');
        $this->app->instance(Resolver::class, $dns);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->viewer = User::factory()->create();
        $this->domain = Domain::query()->create(['name' => 'example.com']);
    }

    private function token(User $user, array $abilities = ['read', 'write']): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue($user, 'test', $abilities)[1], 'Accept' => 'application/json'];
    }

    private function page(string $path, array $attributes = []): MonitoredPage
    {
        $url = 'https://example.com'.$path;

        return MonitoredPage::query()->create(['domain_id' => $this->domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), ...$attributes]);
    }

    public function test_pages_can_be_created_listed_updated_and_deleted(): void
    {
        $headers = $this->token($this->admin);

        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => "/pricing\nhttps://example.com/docs", 'keyword_present' => 'Docs'], $headers)
            ->assertCreated()
            ->assertJsonPath('meta.added', 2)
            ->assertJsonPath('data.0.settings.alerts_enabled', true)
            ->assertJsonPath('data.0.settings.check_interval_minutes', 5)
            ->assertJsonPath('data.0.approval_status', 'approved');
        Queue::assertPushed(RunPageCheck::class, 2);

        $this->getJson("/api/v1/domains/{$this->domain->id}/pages", $headers)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonStructure(['data' => [['id', 'url', 'status', 'settings']], 'meta', 'links']);

        $page = MonitoredPage::query()->where('url', 'https://example.com/pricing')->sole();
        $this->putJson("/api/v1/pages/{$page->id}", ['url' => '/prijzen', 'check_interval_minutes' => 15, 'max_response_ms' => 900], $headers)
            ->assertOk()->assertJsonPath('data.url', 'https://example.com/prijzen')->assertJsonPath('data.settings.max_response_ms', 900);

        $this->postJson("/api/v1/pages/{$page->id}/pause", [], $headers)->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/pages/{$page->id}/resume", [], $headers)->assertOk()->assertJsonPath('data.monitoring_active', true);

        $page->checks()->create(['domain_id' => $this->domain->id, 'result' => 'down', 'success' => false, 'failure_type' => 'status', 'http_status_code' => 404, 'checked_at' => now()]);
        $this->getJson("/api/v1/pages/{$page->id}/checks", $headers)->assertOk()->assertJsonPath('data.0.failure_type', 'status');

        $this->deleteJson("/api/v1/pages/{$page->id}", [], $headers)->assertNoContent();
        $this->assertDatabaseMissing('monitored_pages', ['id' => $page->id]);
    }

    public function test_validation_and_ssrf_rules_apply_to_the_api(): void
    {
        $headers = $this->token($this->admin);

        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => 'https://intern.example.com/'], $headers)->assertUnprocessable()->assertJsonValidationErrors('urls');
        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => 'https://other.org/'], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => '/x', 'alert_severity' => 'extreme'], $headers)->assertJsonValidationErrors('alert_severity');
        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", [], $headers)->assertJsonValidationErrors('urls');

        $pending = $this->page('/x', ['approval_status' => 'pending']);
        $pending->forceFill(['url' => 'https://intern.example.com/x'])->save();
        $this->postJson("/api/v1/pages/{$pending->id}/approve", [], $headers)->assertUnprocessable();
        $this->assertSame('pending', $pending->fresh()->approval_status);
    }

    public function test_authorization_of_api_writes(): void
    {
        $page = $this->page('/a');

        // Read-only tokens cannot write.
        $this->postJson("/api/v1/pages/{$page->id}/pause", [], $this->token($this->admin, ['read']))->assertForbidden();
        // Write tokens of non-admins cannot change configuration.
        $viewer = $this->token($this->viewer);
        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => '/b'], $viewer)->assertForbidden();
        $this->postJson("/api/v1/pages/{$page->id}/pause", [], $viewer)->assertForbidden();
        $this->deleteJson("/api/v1/pages/{$page->id}", [], $viewer)->assertForbidden();
        $this->postJson("/api/v1/domains/{$this->domain->id}/discovery", [], $viewer)->assertForbidden();
        $this->postJson('/api/v1/dependencies', [], $viewer)->assertForbidden();

        // But they can read.
        $this->getJson("/api/v1/pages/{$page->id}", $viewer)->assertOk();
        $this->getJson('/api/v1/predictions', $viewer)->assertOk();

        $this->getJson("/api/v1/pages/{$page->id}")->assertUnauthorized();
        $this->getJson('/api/v1/pages/999', $viewer)->assertNotFound();
    }

    public function test_discovery_can_be_started_and_listed(): void
    {
        $headers = $this->token($this->admin);

        $this->postJson("/api/v1/domains/{$this->domain->id}/discovery", [], $headers)->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RunPageDiscovery::class, 1);
        $this->postJson("/api/v1/domains/{$this->domain->id}/discovery", [], $headers)->assertStatus(409);

        $this->getJson("/api/v1/domains/{$this->domain->id}/discovery-runs", $headers)->assertOk()->assertJsonPath('data.0.status', 'queued');
    }

    public function test_idempotency_keys_prevent_duplicate_writes(): void
    {
        $headers = [...$this->token($this->admin), 'Idempotency-Key' => 'create-pages-0001'];

        $first = $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => '/once'], $headers)->assertCreated();
        $second = $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => '/once'], $headers)->assertCreated();

        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.0.id'), $second->json('data.0.id'));
        Queue::assertPushed(RunPageCheck::class, 1);

        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => '/other'], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/domains/{$this->domain->id}/pages", ['urls' => '/x'], [...$headers, 'Idempotency-Key' => 'bad key!'])->assertUnprocessable();
    }

    public function test_dependencies_services_predictions_and_incident_details(): void
    {
        $headers = $this->token($this->admin);
        $server = Server::query()->create(['name' => 'web-01', 'hostname' => 'web-01', 'last_seen_at' => now()]);

        $this->postJson('/api/v1/dependencies', ['dependent_type' => 'domain', 'dependent_id' => $this->domain->id, 'dependency_type' => 'server', 'dependency_id' => $server->id, 'label' => 'draait op'], $headers)->assertCreated();
        $this->postJson('/api/v1/dependencies', ['dependent_type' => 'server', 'dependent_id' => $server->id, 'dependency_type' => 'domain', 'dependency_id' => $this->domain->id], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/dependencies', ['dependent_type' => 'server', 'dependent_id' => 99, 'dependency_type' => 'domain', 'dependency_id' => $this->domain->id], $headers)->assertUnprocessable();
        $this->getJson('/api/v1/dependencies', $headers)->assertOk()->assertJsonPath('data.0.dependency.name', 'web-01');

        ServiceCheck::query()->create(['server_id' => $server->id, 'name' => 'nginx'])->forceFill(['status' => 'failed'])->save();
        $this->getJson("/api/v1/servers/{$server->id}/services", $headers)->assertOk()->assertJsonPath('data.0.status', 'failed');
        $server->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->getJson("/api/v1/servers/{$server->id}/services", $headers)->assertJsonPath('data.0.status', 'stale')->assertJsonPath('data.0.reported_status', 'failed');

        Prediction::query()->create(['resource_type' => 'server', 'resource_id' => $server->id, 'kind' => 'forecast', 'metric' => 'disk_percent', 'dimension' => '/', 'reason' => 'Trend', 'detected_at' => now(), 'last_evaluated_at' => now(), 'horizon_days' => 5, 'confidence' => 'high']);
        $this->getJson('/api/v1/predictions?kind=forecast', $headers)->assertOk()->assertJsonPath('data.0.horizon_days', 5)->assertJsonPath('data.0.reason', 'Trend');

        $manager = app(IncidentManager::class);
        $root = $manager->trigger(new Signal('alert:1:x', 'agent', 'critical', 'Server offline', null, $server));
        $child = $manager->trigger(new Signal("domain:{$this->domain->id}:http", 'http', 'critical', 'Site down', null, $this->domain));

        $this->getJson("/api/v1/incidents/{$child->id}", $headers)->assertOk()->assertJsonPath('data.parent_id', $root->id)->assertJsonPath('data.correlation.reason_code', 'dependency');
        $this->getJson("/api/v1/incidents/{$root->id}", $headers)->assertJsonPath('data.children.0.id', $child->id)->assertJsonPath('data.impacts.0.resource_type', 'domain');

        $this->postJson("/api/v1/incidents/{$root->id}/assign", ['user_id' => $this->viewer->id], $headers)->assertOk()->assertJsonPath('data.assigned_to', $this->viewer->id);

        $dependency = ResourceDependency::query()->sole();
        $this->deleteJson("/api/v1/dependencies/{$dependency->id}", [], $headers)->assertNoContent();
    }
}
