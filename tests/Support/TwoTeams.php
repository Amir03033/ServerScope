<?php

namespace Tests\Support;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\MonitoredPage;
use App\Models\NotificationLog;
use App\Models\PageChange;
use App\Models\PageDiscoveryRun;
use App\Models\Prediction;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;

/**
 * Two tenants with a complete, distinguishable data set each: every
 * name, title and URL of team B contains "bravo", so a response of
 * team A must never contain that word.
 */
trait TwoTeams
{
    /** @var array<string, mixed> */
    protected array $a = [];

    /** @var array<string, mixed> */
    protected array $b = [];

    protected function setUpTwoTeams(): void
    {
        $this->a = $this->tenant('alpha');
        $this->b = $this->tenant('bravo');
    }

    /** @return array<string, mixed> */
    private function tenant(string $label): array
    {
        $team = Team::query()->create(['name' => ucfirst($label).' BV', 'slug' => $label]);

        $admin = $this->member($team, 'owner', ['name' => "{$label} beheerder", 'email' => "{$label}-admin@example.test", 'notify_email' => true, 'notify_in_app' => true]);
        $member = $this->member($team, 'member', ['name' => "{$label} lid", 'email' => "{$label}-lid@example.test", 'notify_email' => true, 'notify_in_app' => true, 'notify_min_severity' => 'info']);

        $server = Server::forceCreate(['team_id' => $team->id, 'name' => "{$label}-server", 'hostname' => "{$label}.host.test", 'ip_address' => $label === 'alpha' ? '93.184.216.10' : '93.184.216.20', 'last_seen_at' => now(), 'status' => 'online']);
        $trashed = Server::forceCreate(['team_id' => $team->id, 'name' => "{$label}-oud", 'hostname' => "{$label}-oud.host.test"]);
        $trashed->delete();

        $credential = $server->agentCredentials()->create(['name' => "{$label} agent", 'token_hash' => hash('sha256', "{$label}-agent-token")]);
        $service = ServiceCheck::query()->create(['server_id' => $server->id, 'name' => "{$label}-nginx"]);
        MetricSample::create(['server_id' => $server->id, 'cpu_percent' => 10, 'memory_total_bytes' => 100, 'memory_used_bytes' => 10, 'collected_at' => now()->subMinute()]);

        $domain = Domain::forceCreate(['team_id' => $team->id, 'name' => "{$label}-shop.example.com"]);
        $domain->checks()->create(['type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => 40, 'checked_at' => now()->subMinutes(5)]);
        $dnsChange = DnsChange::query()->create(['domain_id' => $domain->id, 'record_type' => 'A', 'previous' => [], 'current' => ['1.2.3.4'], 'added' => ['1.2.3.4'], 'removed' => [], 'detected_at' => now()]);
        $url = "https://{$label}-shop.example.com/{$label}-pagina";
        $page = MonitoredPage::query()->create(['domain_id' => $domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), 'title' => "{$label} pagina"]);
        $change = PageChange::query()->create(['monitored_page_id' => $page->id, 'domain_id' => $domain->id, 'new_fingerprint' => str_repeat('a', 64), 'summary' => "{$label} wijziging", 'detected_at' => now()]);
        $run = PageDiscoveryRun::query()->create(['domain_id' => $domain->id, 'status' => 'completed']);

        $incident = app(IncidentManager::class)->trigger(new Signal("test:{$label}", 'manual', 'critical', "{$label} storing", "{$label} omschrijving", $server));

        $rule = AlertRule::forceCreate(['team_id' => $team->id, 'name' => "{$label} cpu-regel", 'metric' => 'cpu_percent', 'threshold' => 99, 'operator' => '>=', 'duration_minutes' => 1, 'is_enabled' => true]);
        $alert = Alert::forceCreate(['team_id' => $team->id, 'server_id' => $server->id, 'alert_rule_id' => $rule->id, 'metric' => 'cpu_percent', 'observed_value' => 99.5, 'threshold' => 99, 'status' => 'resolved', 'triggered_at' => now()->subHour(), 'resolved_at' => now()]);

        $dependency = ResourceDependency::forceCreate(['team_id' => $team->id, 'dependent_type' => 'domain', 'dependent_id' => $domain->id, 'dependency_type' => 'server', 'dependency_id' => $server->id, 'label' => "{$label} draait op"]);

        $statusPage = StatusPage::forceCreate(['team_id' => $team->id, 'name' => "{$label} status", 'slug' => "{$label}-status", 'is_public' => false, 'accent_color' => '#38bdf8']);
        $statusPage->components()->create(['display_name' => "{$label} component", 'subject_type' => 'server', 'subject_id' => $server->id]);

        $webhook = WebhookEndpoint::forceCreate(['team_id' => $team->id, 'name' => "{$label} hook", 'url' => "https://hooks.example.com/{$label}", 'secret' => 'whsec_'.str_repeat('1', 40), 'events' => array_keys(WebhookEndpoint::EVENTS), 'is_active' => true]);
        $delivery = WebhookDelivery::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'webhook_endpoint_id' => $webhook->id, 'event' => 'test', 'payload' => ['data' => ['message' => "{$label} payload"]], 'status' => 'failed']);

        [$token, $plain] = ApiToken::issue($admin, "{$label} token", ['read', 'write'], null, $team->id);

        $audit = AuditLog::forceCreate(['team_id' => $team->id, 'user_id' => $admin->id, 'action' => 'test.created', 'description' => "{$label} auditregel"]);
        NotificationLog::forceCreate(['team_id' => $team->id, 'user_id' => $admin->id, 'incident_id' => $incident->id, 'kind' => 'opened', 'channel' => 'mail', 'status' => 'failed', 'subject' => "{$label} melding", 'error' => "{$label} fout"]);

        $prediction = Prediction::forceCreate(['team_id' => $team->id, 'resource_type' => 'server', 'resource_id' => $server->id, 'kind' => 'forecast', 'metric' => 'disk_percent', 'status' => 'active', 'active_key' => "{$label}-forecast", 'reason' => "{$label} voorspelling", 'detected_at' => now(), 'last_evaluated_at' => now()]);

        return compact('team', 'admin', 'member', 'server', 'trashed', 'credential', 'service', 'domain', 'dnsChange', 'page', 'change', 'run', 'incident', 'rule', 'alert', 'dependency', 'statusPage', 'webhook', 'delivery', 'token', 'plain', 'audit', 'prediction');
    }

    protected function member(Team $team, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->teams()->sync([$team->id => ['role' => $role]]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    /** @return array<string, string> */
    protected function apiHeaders(string $plain): array
    {
        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }
}
