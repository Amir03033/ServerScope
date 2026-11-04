<?php

namespace App\Services\Demo;

use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Heartbeat;
use App\Models\MetricSample;
use App\Models\MonitoredPage;
use App\Models\PageCheck;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\Team;
use App\Services\Heartbeats\HeartbeatMonitor;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the demo alive, every minute: new sample measurements for the demo
 * servers by their profile, passed website and page checks and the
 * scheduler's check-in, so lists, charts and the heartbeat move. Once an
 * hour demo history older than the retention is removed. Nothing leaves
 * the server; the offline server and the late job stay as they are.
 */
class DemoTicker
{
    public function __construct(private HeartbeatMonitor $heartbeats) {}

    /** @return array{samples: int, checks: int} */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $done = ['samples' => 0, 'checks' => 0];

        foreach (Team::query()->where('is_demo', true)->get() as $team) {
            Tenant::run($team, function () use ($team, $now, &$done) {
                $anchor = CarbonImmutable::instance($team->updated_at ?? $now);

                foreach (Server::query()->where('name', '!=', DemoProfiles::OFFLINE)->get() as $server) {
                    if (! isset(DemoProfiles::SERVERS[$server->name])) {
                        continue;
                    }

                    MetricSample::query()->create(['server_id' => $server->id, 'collected_at' => $now, ...DemoProfiles::sample($server->name, $now, $anchor)]);
                    $server->forceFill(['last_seen_at' => $now, 'status' => 'online'])->saveQuietly();
                    ServiceCheck::query()->where('server_id', $server->id)->where('status', 'running')->update(['last_reported_at' => $now, 'last_running_at' => $now]);
                    $done['samples']++;
                }

                foreach (Domain::query()->get() as $domain) {
                    $ms = (int) ($domain->http_last_response_ms ?: 200);
                    $ms = max(80, $ms + (crc32($domain->name.$now->format('YmdHi')) % 41) - 20);
                    DomainCheck::query()->create(['domain_id' => $domain->id, 'type' => 'http', 'result' => 'up', 'success' => true, 'response_time_ms' => $ms, 'http_status_code' => 200, 'checked_at' => $now]);
                    $domain->forceFill(['http_status' => 'up', 'http_last_status_code' => 200, 'http_last_response_ms' => $ms, 'http_last_checked_at' => $now, 'http_last_success_at' => $now])->saveQuietly();
                    $done['checks']++;
                }

                if ($now->minute % 5 === 0) {
                    foreach (MonitoredPage::query()->where('status', 'up')->get() as $page) {
                        PageCheck::query()->create(['monitored_page_id' => $page->id, 'domain_id' => $page->domain_id, 'result' => 'up', 'success' => true, 'http_status_code' => 200, 'response_time_ms' => (int) $page->last_response_ms, 'checked_at' => $now]);
                        $page->forceFill(['last_checked_at' => $now, 'last_success_at' => $now])->saveQuietly();
                    }
                }

                // The scheduler job checks in every minute; the late import stays silent.
                foreach (Heartbeat::query()->where('status', 'up')->where('period_minutes', 1)->get() as $job) {
                    $this->heartbeats->ping($job, 'success');
                }
            });

            if ($now->minute === 0) {
                $this->prune($team, $now);
            }
        }

        return $done;
    }

    /** Demo history older than the retention (SERVERSCOPE_DEMO_RETAIN_DAYS). */
    private function prune(Team $team, CarbonImmutable $now): void
    {
        $before = $now->subDays(max(1, (int) config('serverscope.demo.retain_days', 7)));
        $servers = DB::table('servers')->where('team_id', $team->id)->pluck('id');
        $domains = DB::table('domains')->where('team_id', $team->id)->pluck('id');

        DB::table('metric_samples')->whereIn('server_id', $servers)->where('collected_at', '<', $before)->delete();
        DB::table('domain_checks')->whereIn('domain_id', $domains)->where('checked_at', '<', $before)->delete();
        DB::table('page_checks')->whereIn('domain_id', $domains)->where('checked_at', '<', $before)->delete();
        DB::table('heartbeat_pings')->whereIn('heartbeat_id', DB::table('heartbeats')->where('team_id', $team->id)->select('id'))->where('received_at', '<', $before)->delete();
    }
}
