<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function token(array $abilities = ['read'], ?\DateTimeInterface $expires = null): string
    {
        return ApiToken::issue($this->user, 'test', $abilities, $expires)[1];
    }

    public function test_requests_require_a_valid_token(): void
    {
        $this->getJson('/api/v1/servers')->assertUnauthorized()->assertJsonPath('message', 'Ontbrekend of ongeldig API-token.');
        $this->withToken('ssk_'.str_repeat('0', 48))->getJson('/api/v1/servers')->assertUnauthorized();
        // Agent tokens are not API tokens.
        $this->withToken(bin2hex(random_bytes(32)))->getJson('/api/v1/servers')->assertUnauthorized();

        [$token, $plain] = ApiToken::issue($this->user, 'x', ['read']);
        $this->withToken($plain)->getJson('/api/v1/servers')->assertOk();

        $token->forceFill(['revoked_at' => now()])->save();
        $this->withToken($plain)->getJson('/api/v1/servers')->assertUnauthorized();

        $expired = $this->token(['read'], now()->subMinute());
        $this->withToken($expired)->getJson('/api/v1/servers')->assertUnauthorized();
    }

    public function test_tokens_are_stored_hashed(): void
    {
        [$token, $plain] = ApiToken::issue($this->user, 'x', ['read', 'write', 'admin']);

        $this->assertMatchesRegularExpression('/\Assk_[a-f0-9]{48}\z/', $plain);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(['read', 'write'], $token->abilities, 'unknown abilities are dropped');
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
    }

    public function test_list_and_detail_endpoints(): void
    {
        $server = Server::create(['name' => 'Web 1', 'hostname' => 'web1.example.test', 'last_seen_at' => now(), 'environment' => 'production']);
        Server::create(['name' => 'Old', 'hostname' => 'old.example.test', 'last_seen_at' => now()->subHour()]);
        MetricSample::create(['server_id' => $server->id, 'cpu_percent' => 12.5, 'memory_total_bytes' => 100, 'memory_used_bytes' => 25, 'collected_at' => now()->subMinute()]);
        $server->agentCredentials()->create(['name' => 'p', 'token_hash' => hash('sha256', 'secret-token')]);
        $domain = Domain::create(['name' => 'xn--bcher-kva.de']);
        $domain->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => 50, 'checked_at' => now()]);
        $incident = app(IncidentManager::class)->trigger(new Signal('api:1', 'manual', 'critical', 'Storing', 'x', $server));
        $plain = $this->token();

        $servers = $this->withToken($plain)->getJson('/api/v1/servers?status=online')->assertOk();
        $servers->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Web 1')->assertJsonPath('data.0.latest_metrics.memory_percent', 25)
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);
        $this->assertStringNotContainsString(hash('sha256', 'secret-token'), $servers->getContent());

        $this->withToken($plain)->getJson("/api/v1/servers/{$server->id}")->assertOk()->assertJsonPath('data.status', 'online');
        $this->withToken($plain)->getJson("/api/v1/servers/{$server->id}/metrics")->assertOk()->assertJsonPath('data.0.cpu_percent', 12.5);
        $this->withToken($plain)->getJson('/api/v1/domains')->assertOk()->assertJsonPath('data.0.display_name', 'bücher.de');
        $this->withToken($plain)->getJson("/api/v1/domains/{$domain->id}/checks?type=http")->assertOk()->assertJsonPath('data.0.response_time_ms', 50)->assertJsonPath('meta.total', 1);
        $this->withToken($plain)->getJson('/api/v1/incidents')->assertOk()->assertJsonPath('data.0.subject.name', 'Web 1');
        $this->withToken($plain)->getJson("/api/v1/incidents/{$incident->id}")->assertOk()->assertJsonPath('data.events.0.type', 'opened');

        $this->withToken($plain)->getJson('/api/v1/servers/99999')->assertNotFound()->assertJsonStructure(['message']);
        $this->withToken($plain)->getJson('/api/v1/servers?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_pagination(): void
    {
        foreach (range(1, 30) as $i) {
            Server::create(['name' => sprintf('S%02d', $i), 'hostname' => "s{$i}.example.test"]);
        }

        $page = $this->withToken($this->token())->getJson('/api/v1/servers?per_page=10&page=3')->assertOk();
        $page->assertJsonCount(10, 'data')->assertJsonPath('data.0.name', 'S21')->assertJsonPath('meta.last_page', 3);
    }

    public function test_write_endpoints_require_write_ability(): void
    {
        $incident = app(IncidentManager::class)->trigger(new Signal('api:2', 'manual', 'warning', 'Probleem'));

        $this->withToken($this->token(['read']))->postJson("/api/v1/incidents/{$incident->id}/acknowledge")->assertForbidden();

        $write = $this->token(['read', 'write']);
        $this->withToken($write)->postJson("/api/v1/incidents/{$incident->id}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->assertSame($this->user->id, $incident->fresh()->acknowledged_by);

        $this->withToken($write)->postJson("/api/v1/incidents/{$incident->id}/comments", ['message' => 'Via API'])->assertCreated();
        $this->withToken($write)->postJson("/api/v1/incidents/{$incident->id}/resolve", ['notes' => 'Opgelost'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->withToken($write)->postJson("/api/v1/incidents/{$incident->id}/acknowledge")->assertStatus(409);
    }

    public function test_rate_limiting(): void
    {
        config(['serverscope.api.requests_per_minute' => 3]);
        $plain = $this->token();

        foreach (range(1, 3) as $i) {
            $this->withToken($plain)->getJson('/api/v1/servers')->assertOk();
        }

        $this->withToken($plain)->getJson('/api/v1/servers')->assertStatus(429)->assertJsonPath('message', 'Te veel verzoeken.');
    }

    public function test_token_management_ui(): void
    {
        $other = User::factory()->create();
        [$foreign] = ApiToken::issue($other, 'other', ['read']);

        $response = $this->actingAs($this->user)->post(route('api-tokens.store'), ['name' => 'Grafana', 'abilities' => ['read'], 'expires_in_days' => 90])->assertRedirect(route('api-tokens.index'));
        $plain = $response->getSession()->get('api_token');
        $token = ApiToken::query()->where('user_id', $this->user->id)->sole();

        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(now()->addDays(90)->toDateString(), $token->expires_at->toDateString());
        $this->actingAs($this->user)->get(route('api-tokens.index'))->assertOk()->assertSee($plain)->assertSee('Grafana');
        $this->actingAs($this->user)->get(route('api-tokens.index'))->assertDontSee($plain);

        $this->actingAs($this->user)->delete(route('api-tokens.destroy', $foreign))->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);

        $this->actingAs($this->user)->delete(route('api-tokens.destroy', $token))->assertRedirect();
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_token.revoked']);
    }
}
