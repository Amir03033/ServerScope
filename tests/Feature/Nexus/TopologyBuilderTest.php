<?php

namespace Tests\Feature\Nexus;

use App\Models\DnsSnapshot;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentImpact;
use App\Models\MonitoredPage;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\Team;
use App\Monitoring\Dns\Resolver;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Nexus\TopologyBuilder;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResolver;
use Tests\Support\TwoTeams;
use Tests\TestCase;

class TopologyBuilderTest extends TestCase
{
    use RefreshDatabase, TwoTeams;

    private Team $team;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->team = Team::query()->create(['name' => 'Nexus BV', 'slug' => 'nexus']);
    }

    private function builder(): TopologyBuilder
    {
        return app(TopologyBuilder::class);
    }

    private function server(string $name, array $attributes = []): Server
    {
        return Server::forceCreate(['team_id' => $this->team->id, 'name' => $name, 'hostname' => "{$name}.internal.test", 'ip_address' => '10.0.0.'.(++$this->sequence), 'last_seen_at' => now(), 'status' => 'online', ...$attributes]);
    }

    private function domain(string $name, array $attributes = []): Domain
    {
        return Domain::forceCreate(['team_id' => $this->team->id, 'name' => $name, 'status' => 'healthy', ...$attributes]);
    }

    private function service(Server $server, string $name, string $status = 'running', array $attributes = []): ServiceCheck
    {
        $service = ServiceCheck::query()->create(['server_id' => $server->id, 'name' => $name, ...$attributes]);
        $service->forceFill(['status' => $status])->save();

        return $service;
    }

    private function page(Domain $domain, string $path, string $status = 'up', array $attributes = []): MonitoredPage
    {
        $url = "https://{$domain->name}{$path}";
        $page = MonitoredPage::query()->create(['domain_id' => $domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), ...$attributes]);
        $page->forceFill(['status' => $status])->save();

        return $page;
    }

    private function dependsOn($dependent, $dependency, ?string $label = null): ResourceDependency
    {
        return ResourceDependency::forceCreate([
            'team_id' => $this->team->id,
            'dependent_type' => $dependent->getMorphClass(), 'dependent_id' => $dependent->id,
            'dependency_type' => $dependency->getMorphClass(), 'dependency_id' => $dependency->id,
            'label' => $label,
        ]);
    }

    private function incident($subject, string $severity, string $status = 'open', array $attributes = []): Incident
    {
        $fingerprint = 'test:'.(++$this->sequence);

        return Incident::forceCreate([
            'team_id' => $this->team->id, 'fingerprint' => $fingerprint,
            'active_fingerprint' => in_array($status, Incident::ACTIVE_STATUSES, true) ? $fingerprint : null,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->id,
            'source' => 'manual', 'severity' => $severity, 'status' => $status, 'title' => "Storing {$this->sequence}",
            'opened_at' => now()->subMinutes(10 - $this->sequence), 'last_occurrence_at' => now(),
            ...$attributes,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function nodes(array $topology): array
    {
        return collect($topology['nodes'])->keyBy('key')->all();
    }

    /** @return array<string, string> edge key => origin */
    private function edges(array $topology): array
    {
        return collect($topology['edges'])->mapWithKeys(fn ($e) => [$e['key'] => $e['origin']])->sortKeys()->all();
    }

    public function test_it_builds_nodes_and_structural_and_confirmed_edges(): void
    {
        $server = $this->server('web-01');
        $nginx = $this->service($server, 'nginx', attributes: ['display_name' => 'Nginx']);
        $domain = $this->domain('shop.example.com');
        $this->page($domain, '/');
        $this->page($domain, '/winkelwagen', 'down');
        $this->page($domain, '/nieuw', 'up', ['approval_status' => 'pending']);
        $checkout = $this->page($domain, '/afrekenen', 'up', ['title' => 'Afrekenen']);
        $this->dependsOn($domain, $server, 'draait op');
        $this->dependsOn($checkout, $nginx);

        $topology = $this->builder()->build($this->team->id);
        $nodes = $this->nodes($topology);

        $this->assertSame(
            ['server:'.$server->id, 'service:'.$nginx->id, 'domain:'.$domain->id, 'page_group:'.$domain->id, 'page:'.$checkout->id],
            array_keys($nodes),
        );
        $this->assertSame('Nginx', $nodes['service:'.$nginx->id]['label']);
        $this->assertSame('server:'.$server->id, $nodes['service:'.$nginx->id]['parent']);
        $this->assertSame('Afrekenen', $nodes['page:'.$checkout->id]['label']);

        // The individual page is not counted again in the group; pending pages are left out.
        $group = $nodes['page_group:'.$domain->id];
        $this->assertNull($group['id']);
        $this->assertNull($group['label'], 'page groups are labelled by the page, in its language');
        $this->assertSame(['total' => 2, 'up' => 1, 'degraded' => 0, 'down' => 1, 'unknown' => 0, 'paused' => 0], $group['details']['pages']);

        $this->assertSame([
            "domain:{$domain->id}->server:{$server->id}" => 'confirmed',
            "page:{$checkout->id}->domain:{$domain->id}" => 'structural',
            "page:{$checkout->id}->service:{$nginx->id}" => 'confirmed',
            "page_group:{$domain->id}->domain:{$domain->id}" => 'structural',
            "service:{$nginx->id}->server:{$server->id}" => 'structural',
        ], $this->edges($topology));

        $confirmed = collect($topology['edges'])->firstWhere('key', "domain:{$domain->id}->server:{$server->id}");
        $this->assertSame('draait op', $confirmed['label'], 'a user-entered label is kept as is');
        $this->assertNull($confirmed['relation']);
        $this->assertIsInt($confirmed['dependency_id']);

        // Structural edges carry a code, never text in some language.
        $structural = collect($topology['edges'])->where('origin', 'structural');
        $this->assertSame(['runs_on'], $structural->where('source', "service:{$nginx->id}")->pluck('relation')->all());
        $this->assertSame(['page_of'], $structural->where('target', "domain:{$domain->id}")->pluck('relation')->unique()->values()->all());
        $this->assertSame([null], $structural->pluck('label')->unique()->values()->all());

        $this->assertSame(5, $topology['meta']['counts']['nodes']);
        $this->assertSame([], $topology['meta']['truncated']);
    }

    public function test_health_follows_the_existing_status_methods(): void
    {
        $online = $this->server('online');
        $offline = $this->server('offline', ['last_seen_at' => now()->subMinutes(Server::OFFLINE_AFTER_MINUTES + 1)]);
        $pending = $this->server('pending', ['last_seen_at' => null]);
        $maintenance = $this->server('onderhoud', ['last_seen_at' => null, 'maintenance_starts_at' => now()->subHour()]);
        $failed = $this->service($online, 'mysql', 'failed');
        $disabled = $this->service($online, 'redis', 'failed', ['is_enabled' => false]);
        $warning = $this->domain('warning.example.com', ['status' => 'warning']);
        $paused = $this->domain('paused.example.com', ['is_active' => false]);
        $this->page($warning, '/', 'degraded');

        $nodes = $this->nodes($this->builder()->build($this->team->id));

        $this->assertSame(['online', 'ok'], [$nodes["server:{$online->id}"]['status'], $nodes["server:{$online->id}"]['health']]);
        $this->assertSame(['offline', 'critical'], [$nodes["server:{$offline->id}"]['status'], $nodes["server:{$offline->id}"]['health']]);
        $this->assertSame('unknown', $nodes["server:{$pending->id}"]['health']);
        $this->assertSame('maintenance', $nodes["server:{$maintenance->id}"]['health']);
        $this->assertSame('critical', $nodes["service:{$failed->id}"]['health']);
        $this->assertSame(['paused', 'paused'], [$nodes["service:{$disabled->id}"]['status'], $nodes["service:{$disabled->id}"]['health']]);
        $this->assertSame('warning', $nodes["domain:{$warning->id}"]['health']);
        $this->assertSame('paused', $nodes["domain:{$paused->id}"]['health']);
        $this->assertSame(['degraded', 'warning'], [$nodes["page_group:{$warning->id}"]['status'], $nodes["page_group:{$warning->id}"]['health']]);

        foreach ($nodes as $node) {
            $this->assertContains($node['health'], TopologyBuilder::HEALTH_STATES);
        }
    }

    public function test_active_incidents_and_their_impacts_raise_health(): void
    {
        $server = $this->server('web-01');
        $maintenance = $this->server('db-01', ['maintenance_starts_at' => now()->subHour()]);
        $domain = $this->domain('shop.example.com');
        $page = $this->page($domain, '/');
        $other = $this->domain('blog.example.com');

        $this->incident($server, 'warning');
        $critical = $this->incident($server, 'critical');
        $this->incident($server, 'critical', 'investigating');
        $this->incident($maintenance, 'critical');
        $this->incident($other, 'critical', 'resolved');
        $this->incident(null, 'critical'); // manual, about no resource

        // A website incident whose failing page is in the page group.
        $website = $this->incident($domain, 'info');
        IncidentImpact::query()->create(['incident_id' => $website->id, 'resource_type' => 'page', 'resource_id' => $page->id, 'status' => 'failing']);
        IncidentImpact::query()->create(['incident_id' => $website->id, 'resource_type' => 'server', 'resource_id' => $server->id, 'status' => 'recovered']);

        $topology = $this->builder()->build($this->team->id);
        $nodes = $this->nodes($topology);

        $node = $nodes["server:{$server->id}"];
        $this->assertSame('critical', $node['health']);
        $this->assertSame(3, $node['incidents']['active'], 'recovered impacts do not count');
        $this->assertSame('critical', $node['incidents']['max_severity']);
        // The most recent incident of the highest severity is the primary one.
        $this->assertNotSame($critical->id, $node['incidents']['primary']['id']);
        $this->assertSame('investigating', $node['incidents']['primary']['status']);
        $this->assertSame(route('incidents.show', $node['incidents']['primary']['id']), $node['incidents']['primary']['url']);

        $this->assertSame('maintenance', $nodes["server:{$maintenance->id}"]['health'], 'maintenance is not overridden');
        $this->assertSame(['ok', 0], [$nodes["domain:{$other->id}"]['health'], $nodes["domain:{$other->id}"]['incidents']['active']], 'resolved incidents are ignored');

        // An info incident does not change health but is shown.
        $this->assertSame(['ok', 1, 'info'], [$nodes["domain:{$domain->id}"]['health'], $nodes["domain:{$domain->id}"]['incidents']['active'], $nodes["domain:{$domain->id}"]['incidents']['max_severity']]);
        $this->assertSame(1, $nodes["page_group:{$domain->id}"]['incidents']['active']);

        $this->assertSame(6, $topology['meta']['counts']['active_incidents']);
        $this->assertSame(1, $topology['meta']['counts']['unplaced_incidents']);
    }

    public function test_blast_radius_counts_everything_that_depends_on_a_node(): void
    {
        $db = $this->server('db-01');
        $web = $this->server('web-01');
        $mysql = $this->service($db, 'mysql');
        $domain = $this->domain('shop.example.com');
        $this->page($domain, '/');
        $this->dependsOn($web, $mysql);
        $this->dependsOn($domain, $web);

        $nodes = $this->nodes($this->builder()->build($this->team->id));

        // db <- mysql <- web <- domain <- page group
        $this->assertSame(4, $nodes["server:{$db->id}"]['blast_radius']);
        $this->assertSame(3, $nodes["service:{$mysql->id}"]['blast_radius']);
        $this->assertSame(2, $nodes["server:{$web->id}"]['blast_radius']);
        $this->assertSame(1, $nodes["domain:{$domain->id}"]['blast_radius']);
        $this->assertSame(0, $nodes["page_group:{$domain->id}"]['blast_radius']);
    }

    public function test_blast_radius_matches_dependency_graph_descendants_with_its_depth_limit(): void
    {
        // A chain of nine servers: each depends on the previous one.
        $chain = [];

        for ($i = 0; $i < 9; $i++) {
            $chain[$i] = $this->server("keten-{$i}");

            if ($i > 0) {
                $this->dependsOn($chain[$i], $chain[$i - 1]);
            }
        }

        $nodes = $this->nodes($this->builder()->build($this->team->id));
        $graph = app(DependencyGraph::class);

        $this->assertSame(DependencyGraph::MAX_DEPTH, $nodes["server:{$chain[0]->id}"]['blast_radius'], 'limited to MAX_DEPTH levels');
        $this->assertSame(0, $nodes["server:{$chain[8]->id}"]['blast_radius'], 'nothing depends on the last one');

        foreach ($chain as $server) {
            $this->assertSame(
                count($graph->descendants('server', $server->id, teamId: $this->team->id)) - 1,
                $nodes["server:{$server->id}"]['blast_radius'],
                "same as DependencyGraph::descendants() for {$server->name}",
            );
        }
    }

    public function test_cycles_in_stored_dependencies_terminate(): void
    {
        // The interface refuses cycles; rows written otherwise must not hang the map.
        $a = $this->server('a');
        $b = $this->server('b');
        $c = $this->server('c');
        $this->dependsOn($a, $b);
        $this->dependsOn($b, $c);
        $this->dependsOn($c, $a);

        $nodes = $this->nodes($this->builder()->build($this->team->id));

        foreach ([$a, $b, $c] as $server) {
            $this->assertSame(2, $nodes["server:{$server->id}"]['blast_radius']);
        }
    }

    public function test_unapproved_pages_never_appear(): void
    {
        $server = $this->server('web-01');
        $domain = $this->domain('shop.example.com');
        $this->page($domain, '/');
        $pending = $this->page($domain, '/concept-geheim', 'down', ['approval_status' => 'pending', 'title' => 'Concept geheim']);
        $rejected = $this->page($domain, '/afgewezen-geheim', 'down', ['approval_status' => 'rejected']);
        $this->dependsOn($pending, $server);
        $this->dependsOn($rejected, $server);
        $this->incident($pending, 'critical');
        $website = $this->incident($domain, 'critical');
        IncidentImpact::query()->create(['incident_id' => $website->id, 'resource_type' => 'page', 'resource_id' => $rejected->id, 'status' => 'failing']);

        $topology = $this->builder()->build($this->team->id);
        $nodes = $this->nodes($topology);

        $this->assertStringNotContainsString('geheim', json_encode($topology));
        $this->assertSame(['server:'.$server->id, 'domain:'.$domain->id, 'page_group:'.$domain->id], array_keys($nodes));
        $this->assertSame(1, $nodes['page_group:'.$domain->id]['details']['pages']['total']);
        $this->assertSame(0, $nodes['page_group:'.$domain->id]['incidents']['active']);
        $this->assertSame(1, $topology['meta']['counts']['unplaced_incidents']);
    }

    public function test_truncation_is_reported_per_kind(): void
    {
        $small = new class extends TopologyBuilder
        {
            public const LIMITS = ['server' => 2, 'domain' => 1, 'service' => 10, 'page' => 10, 'dependency' => 10, 'incident' => 1, 'impact' => 1];
        };

        $servers = [$this->server('s1'), $this->server('s2'), $this->server('s3')];
        $domain = $this->domain('shop.example.com');
        $this->domain('blog.example.com');
        $older = $this->incident($servers[0], 'warning');
        $newer = $this->incident($servers[1], 'critical');
        IncidentImpact::query()->create(['incident_id' => $newer->id, 'resource_type' => 'domain', 'resource_id' => $domain->id, 'status' => 'failing']);
        IncidentImpact::query()->create(['incident_id' => $newer->id, 'resource_type' => 'server', 'resource_id' => $servers[0]->id, 'status' => 'failing']);
        IncidentImpact::query()->create(['incident_id' => $older->id, 'resource_type' => 'server', 'resource_id' => $servers[1]->id, 'status' => 'failing']);

        $topology = $small->build($this->team->id);
        $nodes = $this->nodes($topology);

        $this->assertSame(['server', 'domain', 'incident', 'impact'], $topology['meta']['truncated']);
        $this->assertSame(['server' => 2, 'service' => 0, 'domain' => 1, 'page_group' => 0, 'page' => 0], $topology['meta']['counts']['by_type']);
        $this->assertSame(1, $topology['meta']['counts']['active_incidents'], 'the most recent incident is kept');
        $this->assertSame(1, $nodes["server:{$servers[1]->id}"]['incidents']['active']);
        $this->assertSame(0, $nodes["server:{$servers[0]->id}"]['incidents']['active'], 'the older incident was not loaded');
        $this->assertSame(1, $nodes["domain:{$domain->id}"]['incidents']['active'], 'the first impact is kept');

        $this->assertSame([], $this->builder()->build($this->team->id)['meta']['truncated']);
    }

    public function test_deleted_resources_and_their_edges_are_left_out(): void
    {
        $server = $this->server('web-01');
        $trashed = $this->server('oud-01');
        $this->service($trashed, 'nginx');
        $domain = $this->domain('shop.example.com');
        $trashedDomain = $this->domain('oud.example.com');
        $this->page($trashedDomain, '/');
        $this->dependsOn($domain, $trashed);
        $this->dependsOn($trashedDomain, $server);
        $this->incident($trashed, 'critical');
        $trashed->delete();
        $trashedDomain->delete();

        $topology = $this->builder()->build($this->team->id);

        $this->assertSame(['server:'.$server->id, 'domain:'.$domain->id], array_keys($this->nodes($topology)));
        $this->assertSame([], $topology['edges']);
        $this->assertSame(1, $topology['meta']['counts']['unplaced_incidents']);
    }

    public function test_inferred_dns_relations_are_not_edges(): void
    {
        $server = $this->server('web-01', ['ip_address' => '93.184.216.34']);
        $domain = $this->domain('shop.example.com');
        DnsSnapshot::query()->create(['domain_id' => $domain->id, 'record_type' => 'A', 'records' => ['93.184.216.34'], 'hash' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->assertSame([], $this->builder()->build($this->team->id)['edges']);
    }

    public function test_no_connection_details_or_secrets_are_included(): void
    {
        $server = $this->server('web-01', ['hostname' => 'geheim.internal.test', 'ip_address' => '10.9.8.7', 'operating_system' => 'Ubuntu 24.04', 'agent_version' => '0.3.1']);
        $server->agentCredentials()->create(['name' => 'agent', 'token_hash' => hash('sha256', 'agent-token')]);
        $server->metricSamples()->create(['cpu_percent' => 12.5, 'memory_total_bytes' => 200, 'memory_used_bytes' => 50, 'collected_at' => now()->subMinute()]);

        $topology = $this->builder()->build($this->team->id);
        $json = json_encode($topology);

        foreach (['geheim', '10.9.8.7', 'Ubuntu', '0.3.1', 'token', hash('sha256', 'agent-token')] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        $this->assertSame(['collected_at', 'cpu_percent', 'memory_percent'], array_keys($topology['nodes'][0]['details']['metrics']));
        $this->assertSame(25.0, $topology['nodes'][0]['details']['metrics']['memory_percent']);

        $server->metricSamples()->create(['cpu_percent' => 1, 'memory_total_bytes' => 200, 'collected_at' => now()]);
        $this->assertNull($this->builder()->build($this->team->id)['nodes'][0]['details']['metrics']['memory_percent'], 'unknown, not 0%');
    }

    public function test_websites_carry_their_last_response_time_or_null(): void
    {
        $fast = $this->domain('fast.example.com', ['http_last_response_ms' => 142.6]);
        $never = $this->domain('never.example.com');
        $nodes = collect($this->builder()->build($this->team->id)['nodes'])->keyBy('key');

        $this->assertSame(143, $nodes['domain:'.$fast->id]['details']['response_ms']);
        $this->assertNull($nodes['domain:'.$never->id]['details']['response_ms'], 'not measured, not 0 ms');
    }

    public function test_labels_are_returned_as_plain_text(): void
    {
        $this->server('<script>alert(1)</script>');

        $this->assertSame('<script>alert(1)</script>', $this->builder()->build($this->team->id)['nodes'][0]['label']);
    }

    public function test_another_team_is_never_included_with_or_without_a_tenant(): void
    {
        $this->app->instance(Resolver::class, new FakeResolver);
        $this->createTwoTeams();
        $a = $this->a;
        $b = $this->b;

        $this->incident(null, 'critical', attributes: ['team_id' => $b['team']->id, 'subject_type' => 'server', 'subject_id' => $b['server']->id, 'title' => 'bravo storing 2']);
        // Inconsistent rows: team A's dependency on, and team B's incident
        // impact on, a resource of the other team.
        ResourceDependency::forceCreate(['team_id' => $a['team']->id, 'dependent_type' => 'domain', 'dependent_id' => $a['domain']->id, 'dependency_type' => 'server', 'dependency_id' => $b['server']->id]);
        IncidentImpact::query()->create(['incident_id' => $b['incident']->id, 'resource_type' => 'server', 'resource_id' => $a['server']->id, 'status' => 'failing']);
        IncidentImpact::query()->create(['incident_id' => $b['incident']->id, 'resource_type' => 'page', 'resource_id' => $a['page']->id, 'status' => 'failing']);
        // Team A's incident pointing at a page of team B.
        $this->incident(null, 'critical', attributes: ['team_id' => $a['team']->id, 'subject_type' => 'page', 'subject_id' => $b['page']->id]);

        foreach ([null, $a['team']->id] as $tenant) {
            $topology = Tenant::run($tenant, fn () => $this->builder()->build($a['team']->id));
            $json = json_encode($topology);

            $this->assertStringContainsString('alpha-server', $json);
            $this->assertStringNotContainsStringIgnoringCase('bravo', $json);

            foreach ($topology['nodes'] as $node) {
                $this->assertNotContains($node['key'], ['server:'.$b['server']->id, 'domain:'.$b['domain']->id, 'service:'.$b['service']->id, 'page_group:'.$b['domain']->id]);
            }

            foreach ($topology['edges'] as $edge) {
                $this->assertArrayHasKey($edge['source'], $this->nodes($topology));
                $this->assertArrayHasKey($edge['target'], $this->nodes($topology));
            }

            $nodes = $this->nodes($topology);
            $this->assertSame(1, $nodes['server:'.$a['server']->id]['incidents']['active'], "only team A's own incident (tenant: {$tenant})");
            $this->assertSame(0, $nodes['page_group:'.$a['domain']->id]['incidents']['active']);
            $this->assertSame(1, $topology['meta']['counts']['unplaced_incidents']);
        }

        // With another tenant active, the global scopes leave nothing rather than mixing teams.
        $this->assertSame([], Tenant::run($b['team']->id, fn () => $this->builder()->build($a['team']->id))['nodes']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_topology(): void
    {
        $seed = function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                $server = $this->server("srv-{$this->sequence}");
                $service = $this->service($server, 'nginx');
                $server->metricSamples()->create(['cpu_percent' => 5, 'collected_at' => now()->subMinute()]);
                $domain = $this->domain("site-{$this->sequence}.example.com");
                $this->page($domain, '/');
                $page = $this->page($domain, '/contact', 'down');
                $this->dependsOn($domain, $server);
                $this->dependsOn($page, $service);
                $incident = $this->incident($server, 'critical');
                IncidentImpact::query()->create(['incident_id' => $incident->id, 'resource_type' => 'page', 'resource_id' => $page->id, 'status' => 'failing']);
            }
        };

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->builder()->build($this->team->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $seed(1);
        $small = $count();
        $seed(25);
        $large = $count();

        $this->assertSame($small, $large);
        // One query per kind of row (scheduled jobs are the tenth), never per row.
        $this->assertLessThanOrEqual(10, $large);
    }
}
