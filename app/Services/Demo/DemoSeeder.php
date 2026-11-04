<?php

namespace App\Services\Demo;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Heartbeat;
use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Models\IncidentEvent;
use App\Models\IncidentLearning;
use App\Models\Investigation;
use App\Models\MetricSample;
use App\Models\MonitoredPage;
use App\Models\PageCheck;
use App\Models\Postmortem;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\PredictiveAnalyzer;
use App\Services\Deployments\DeploymentRecorder;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use App\Services\Intelligence\InvestigationAiContract;
use App\Services\Intelligence\InvestigationRunner;
use App\Services\Intelligence\Investigations;
use App\Services\Logs\LogFingerprint;
use App\Support\Demo;
use App\Support\Locale;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds (or rebuilds) the demo team of one language with sample data
 * whose times are relative to now. The team and its demo user keep their
 * ids across rebuilds, so visitors in the middle of a session stay in.
 * Nothing here talks to the outside world.
 */
class DemoSeeder
{
    /** Tables the rebuild never empties by team (the team itself and its people). */
    private const KEEP = ['teams', 'team_user', 'users', 'migrations'];

    private Team $team;

    private User $user;

    private CarbonImmutable $now;

    /** @var array<string, Server> */
    private array $servers = [];

    /** @var array<string, Domain> */
    private array $domains = [];

    public function __construct(
        private IncidentManager $incidents,
        private DeploymentRecorder $deployments,
    ) {}

    public function seed(string $locale): Team
    {
        $this->now = CarbonImmutable::now()->startOfMinute();
        // Automatic investigations would queue jobs; the seeder runs them itself.
        config(['serverscope.intelligence.auto_triggers' => []]);

        return Locale::using($locale, function () use ($locale) {
            $this->team = $this->team($locale);
            $this->user = $this->user($locale);
            Demo::flush();
            $this->wipe();

            Tenant::run($this->team, function () {
                $this->servers();
                $this->domains();
                $this->dependencies();
                $this->rules();
                $this->deploysAndLogs();
                $this->incidentHistory();
                $this->activeIncidents();
                $this->jobs();
                $this->statusPage();
                $this->predictions();
            });

            // The anchor of the disk forecast and the tick: when this data was built.
            $this->team->forceFill(['updated_at' => $this->now])->save();

            return $this->team;
        });
    }

    private function team(string $locale): Team
    {
        $team = Team::query()->where('is_demo', true)->where('slug', "demo-{$locale}")->first() ?? new Team;
        $team->forceFill(['name' => __('demo.data.team'), 'slug' => "demo-{$locale}", 'is_demo' => true, 'plan' => 'premium', 'plan_status' => 'active']);
        $team->save();

        return $team;
    }

    private function user(string $locale): User
    {
        $user = User::query()->where('email', Demo::email($locale))->first() ?? new User;
        // No password anyone knows: the demo user only comes in through /demo. Set once:
        // a new hash on every rebuild would sign the visitors out.
        $user->forceFill([
            ...($user->exists ? [] : ['password' => Hash::make(Str::random(64))]),
            'name' => __('demo.data.user'), 'email' => Demo::email($locale), 'is_demo' => true,
            'email_verified_at' => $this->now, 'terms_accepted_at' => $this->now, 'terms_version' => '1', 'current_team_id' => $this->team->id,
            'locale' => $locale, 'notify_email' => false, 'notify_in_app' => false, 'dashboard_layout' => null,
        ])->save();
        $user->teams()->sync([$this->team->id => ['role' => 'admin']]);
        $user->syncAdminMirror(true);

        return $user;
    }

    /** Empties every table that belongs to the team, children first (retried until nothing is left). */
    private function wipe(): void
    {
        $tables = collect(Schema::getTables())->pluck('name')
            ->reject(fn ($t) => in_array($t, self::KEEP, true))
            ->filter(fn ($t) => Schema::hasColumn($t, 'team_id'))
            ->values()->all();

        for ($pass = 0; $pass < 8 && $tables !== []; $pass++) {
            foreach ($tables as $i => $table) {
                try {
                    DB::table($table)->where('team_id', $this->team->id)->delete();
                    unset($tables[$i]);
                } catch (QueryException) {
                    // Still referenced by a row of another table: try again in the next pass.
                }
            }
        }
    }

    private function at(int $minutesAgo): CarbonImmutable
    {
        return $this->now->subMinutes($minutesAgo);
    }

    private function servers(): void
    {
        foreach (DemoProfiles::SERVERS as $name => $p) {
            $offline = $name === DemoProfiles::OFFLINE;
            $lastSeen = $offline ? $this->at(DemoProfiles::OFFLINE_FOR_MINUTES) : $this->now;
            $server = Server::forceCreate([
                'team_id' => $this->team->id, 'name' => $name, 'hostname' => $p['hostname'], 'operating_system' => $p['os'], 'environment' => $p['env'],
                'cpu_count' => $p['cpus'], 'agent_version' => '0.4.0', 'status' => $offline ? 'offline' : 'online', 'last_seen_at' => $lastSeen,
                'ip_address' => '192.0.2.'.(10 + count($this->servers)),
            ]);

            foreach ($p['services'] as $service => $status) {
                $check = ServiceCheck::query()->create(['server_id' => $server->id, 'name' => $service]);
                $check->forceFill(['status' => $status, 'last_reported_at' => $lastSeen, 'last_running_at' => $status === 'running' ? $lastSeen : $this->at(95), 'failing_since' => $status === 'running' ? null : $this->at(95)])->save();
            }

            // Seven days, every five minutes (offline: until it went silent).
            $rows = [];
            for ($m = 7 * 24 * 60; $m >= 0; $m -= 5) {
                $at = $this->at($m);
                if ($at->gt($lastSeen)) {
                    break;
                }
                $sample = DemoProfiles::sample($name, $at, $this->now);
                $rows[] = [...$sample, 'disks' => json_encode($sample['disks']), 'server_id' => $server->id, 'collected_at' => $at, 'created_at' => $at, 'updated_at' => $at];
            }
            foreach (array_chunk($rows, 400) as $chunk) {
                MetricSample::query()->insert($chunk);
            }

            $this->servers[$name] = $server;
        }
    }

    private function domains(): void
    {
        $sites = [
            'shop.example.com' => ['ms' => 210, 'ssl_days' => 63, 'pages' => ['/', '/pricing', '/checkout', '/api/health']],
            'api.example.com' => ['ms' => 140, 'ssl_days' => 63, 'pages' => []],
            // The certificate that expires soon.
            'blog.example.com' => ['ms' => 320, 'ssl_days' => 9, 'pages' => ['/']],
        ];

        foreach ($sites as $name => $site) {
            $domain = Domain::forceCreate([
                'team_id' => $this->team->id, 'name' => $name, 'scheme' => 'https', 'path' => '/', 'is_active' => true,
                'status' => $site['ssl_days'] <= 14 ? 'warning' : 'healthy', 'http_status' => 'up', 'http_last_status_code' => 200,
                'http_last_response_ms' => $site['ms'], 'http_last_checked_at' => $this->now, 'http_last_success_at' => $this->now, 'http_next_check_at' => $this->now->addYears(5),
                'ssl_status' => $site['ssl_days'] <= 14 ? 'expiring' : 'valid', 'ssl_valid_from' => $this->now->subDays(90 - $site['ssl_days']), 'ssl_valid_to' => $this->now->addDays($site['ssl_days']),
                'ssl_issuer' => "Let's Encrypt R11", 'ssl_subject' => $name, 'ssl_last_checked_at' => $this->now, 'ssl_next_check_at' => $this->now->addYears(5),
                'dns_status' => 'ok', 'dns_last_checked_at' => $this->now, 'dns_next_check_at' => $this->now->addYears(5),
                'expiry_status' => 'verified', 'registration_expires_at' => $this->now->addDays(212), 'registrar' => 'Example Registrar', 'registrable_domain' => 'example.com',
                'expiry_checked_at' => $this->now, 'expiry_next_check_at' => $this->now->addYears(5),
            ]);

            DB::table('ssl_certificates')->insert([
                'domain_id' => $domain->id, 'fingerprint' => hash('sha256', $name.$site['ssl_days']), 'serial' => strtoupper(substr(md5($name), 0, 16)),
                'subject' => $name, 'issuer' => "Let's Encrypt R11", 'subject_alt_names' => json_encode([$name]),
                'valid_from' => $this->now->subDays(90 - $site['ssl_days']), 'valid_to' => $this->now->addDays($site['ssl_days']), 'first_seen_at' => $this->now->subDays(30), 'last_seen_at' => $this->now,
            ]);

            $this->checks($domain, $site['ms']);

            foreach ($site['pages'] as $path) {
                $url = "https://{$name}{$path}";
                $page = MonitoredPage::query()->create(['domain_id' => $domain->id, 'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), 'title' => $path === '/' ? $name : $path]);
                $page->forceFill(['approval_status' => 'approved', 'is_active' => true, 'status' => 'up', 'last_status_code' => 200, 'last_response_ms' => $site['ms'] + 40, 'last_checked_at' => $this->now, 'last_success_at' => $this->now, 'next_check_at' => $this->now->addYears(5)])->save();
                $rows = [];
                for ($m = 24 * 60; $m >= 0; $m -= 5) {
                    $rows[] = ['monitored_page_id' => $page->id, 'domain_id' => $domain->id, 'result' => 'up', 'success' => true, 'http_status_code' => 200, 'response_time_ms' => $site['ms'] + 40 + crc32($path.$m) % 60, 'checked_at' => $this->at($m)];
                }
                foreach (array_chunk($rows, 400) as $chunk) {
                    PageCheck::query()->insert($chunk);
                }
            }

            $this->domains[$name] = $domain;
        }

        // An A record that changed three days ago.
        DB::table('dns_changes')->insert(['domain_id' => $this->domains['shop.example.com']->id, 'record_type' => 'A', 'previous' => json_encode(['192.0.2.10']), 'current' => json_encode(['192.0.2.11']), 'added' => json_encode(['192.0.2.11']), 'removed' => json_encode(['192.0.2.10']), 'detected_at' => $this->at(3 * 24 * 60 + 40)]);
    }

    /** Seven days of HTTP checks every five minutes, with the slow and failing periods of the incidents below. */
    private function checks(Domain $domain, int $ms): void
    {
        $rows = [];
        for ($m = 7 * 24 * 60; $m >= 0; $m -= 5) {
            $failing = $domain->name === 'api.example.com' && $m <= 50 * 60 - 2 && $m >= 50 * 60 - 23;
            $slow = $domain->name === 'shop.example.com' && $m <= DemoProfiles::CPU_SPIKE[0] && $m >= DemoProfiles::CPU_SPIKE[1];
            $rows[] = [
                'domain_id' => $domain->id, 'type' => 'http', 'result' => $failing ? 'down' : 'up', 'success' => ! $failing,
                'response_time_ms' => $slow ? 2400 + crc32((string) $m) % 600 : $ms + crc32($domain->name.$m) % 80,
                'http_status_code' => $failing ? 500 : 200, 'message' => $failing ? 'HTTP 500' : null, 'checked_at' => $this->at($m),
            ];
        }
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $rows[] = ['domain_id' => $domain->id, 'type' => 'ssl', 'result' => 'valid', 'success' => true, 'response_time_ms' => null, 'http_status_code' => null, 'message' => null, 'checked_at' => $this->at($day * 1440)];
            $rows[] = ['domain_id' => $domain->id, 'type' => 'dns', 'result' => 'ok', 'success' => true, 'response_time_ms' => null, 'http_status_code' => null, 'message' => null, 'checked_at' => $this->at($day * 1440 - 30)];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DomainCheck::query()->insert($chunk);
        }
    }

    private function dependencies(): void
    {
        $service = fn (string $server, string $name) => ServiceCheck::query()->where('server_id', $this->servers[$server]->id)->where('name', $name)->first();
        $link = fn ($from, $to) => ResourceDependency::forceCreate(['team_id' => $this->team->id, 'dependent_type' => $from->getMorphClass(), 'dependent_id' => $from->id, 'dependency_type' => $to->getMorphClass(), 'dependency_id' => $to->id, 'label' => 'runs on']);

        foreach (['shop.example.com', 'api.example.com', 'blog.example.com'] as $site) {
            $link($this->domains[$site], $this->servers['web-01']);
        }
        $link($this->domains['api.example.com'], $service('db-01', 'mysql'));
        $link($this->domains['shop.example.com'], $service('db-01', 'mysql'));
        $link($this->servers['worker-01'], $service('db-01', 'mysql'));
    }

    private function rules(): void
    {
        $rule = fn (string $name, string $metric, float $threshold, string $severity) => AlertRule::query()->create(['name' => $name, 'metric' => $metric, 'threshold' => $threshold, 'operator' => '>=', 'severity' => $severity, 'duration_minutes' => $metric === AlertRule::METRIC_AGENT_OFFLINE ? 1 : 5, 'is_enabled' => true]);

        $rule('Agent offline', AlertRule::METRIC_AGENT_OFFLINE, 5, 'critical');
        $rule(__('demo.data.rules.cpu'), 'cpu_percent', 90, 'high');
        $rule(__('demo.data.rules.disk'), 'disk_percent', 85, 'warning');
        $rule(__('demo.data.rules.logs'), AlertRule::METRIC_LOG_ERRORS, 20, 'warning');
    }

    private function deploysAndLogs(): void
    {
        // The recorder stamps "now" (as for a live webhook); the demo history moves it back.
        $deploy = fn (string $app, string $version, string $title, int $minutesAgo, array $on) => $this->deployments->record([
            'application' => $app, 'environment' => 'production', 'version' => $version, 'title' => $title, 'actor' => 'sam',
            'commit_sha' => substr(hash('sha1', $app.$version), 0, 40), 'source' => 'github', 'external_id' => "demo:{$this->team->id}:{$app}:{$version}",
        ], array_map(fn ($r) => ['type' => $r->getMorphClass(), 'id' => $r->id], $on))->forceFill(['deployed_at' => $this->at($minutesAgo)])->save();

        $web = $this->servers['web-01'];
        $deploy('shop', '5.8.0', 'Faster product search (#212)', 6 * 24 * 60, [$this->domains['shop.example.com'], $web]);
        $deploy('api', '2.14.0', 'Index on orders.created_at (#418)', 50 * 60, [$this->domains['api.example.com'], $web]);
        $deploy('api', '2.13.4', 'Revert "Index on orders.created_at" (#419)', 50 * 60 - 20, [$this->domains['api.example.com'], $web]);
        $deploy('api', '2.14.1', 'Index on orders.created_at without lock (#420)', 46 * 60, [$this->domains['api.example.com'], $web]);
        $deploy('shop', '5.9.0', 'New checkout layout (#219)', 5 * 60, [$this->domains['shop.example.com'], $web]);

        $line = fn (Server $s, string $source, string $level, string $message, int $minutesAgo, ?string $context = null) => [
            'team_id' => $this->team->id, 'server_id' => $s->id, 'source' => $source, 'level' => $level, 'message' => $message, 'context' => $context,
            'fingerprint' => LogFingerprint::of($source, $message), 'occurred_at' => $this->at($minutesAgo), 'created_at' => $this->at($minutesAgo),
        ];
        $rows = [];
        // The deploy incident: lock wait timeouts on the orders table.
        for ($i = 0; $i < 70; $i++) {
            $rows[] = $line($web, 'laravel', 'error', 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction (SQL: insert into `orders` ...)', 50 * 60 - 2 - intdiv($i * 21, 70), "#0 /var/www/api/vendor/laravel/framework/src/Illuminate/Database/Connection.php(822)\n#1 /var/www/api/app/Http/Controllers/OrderController.php(57)\n#2 {main}");
        }
        // The CPU overload: upstream timeouts in nginx.
        for ($i = 0; $i < 30; $i++) {
            $rows[] = $line($web, 'nginx', 'error', 'upstream timed out (110: Connection timed out) while reading response header from upstream, server: shop.example.com', DemoProfiles::CPU_SPIKE[0] - intdiv($i * 14, 30));
        }
        // Everyday noise over the week, and the stopped queue worker now.
        for ($m = 7 * 24 * 60; $m > 30; $m -= 97) {
            $rows[] = $line($web, 'laravel', 'warning', 'Slow query took '.(1100 + $m % 900).' ms: select * from `products` where `category_id` = ?', $m);
            if ($m % 3 === 0) {
                $rows[] = $line($web, 'php-fpm', 'warning', '[pool www] seems busy (you may need to increase pm.start_servers), spawning 8 children', $m - 4);
            }
        }
        for ($i = 0; $i < 12; $i++) {
            $rows[] = $line($this->servers['worker-01'], 'laravel', 'error', 'Horizon is not running. Jobs on queue "imports" are waiting.', 90 - $i * 7);
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('log_entries')->insert($chunk);
        }
    }

    /** Resolved incidents with their story: a grouped overload and a bad deploy with a lesson, postmortem and analysis. */
    private function incidentHistory(): void
    {
        $web = $this->servers['web-01'];
        [$start, $end] = DemoProfiles::CPU_SPIKE;

        $cpu = $this->pastIncident('demo:cpu', 'alert', 'high', __('demo.data.incidents.cpu_title'), __('demo.data.incidents.cpu_text'), $web, $start, $end, __('demo.data.incidents.resolution_cpu'));
        $this->event($cpu, 'comment', __('demo.data.incidents.note_cpu'), $start - 6, $this->user->id);
        $shop = $this->pastIncident('demo:shop-slow', 'http', 'warning', __('demo.data.incidents.shop_title'), null, $this->domains['shop.example.com'], $start - 2, $end + 1, null);
        $shop->forceFill(['parent_id' => $cpu->id, 'correlation' => ['reason_code' => 'dependency', 'reason' => __('demo.data.incidents.grouped'), 'path' => ['domain:'.$this->domains['shop.example.com']->id, 'server:'.$web->id]]])->save();
        $this->event($cpu, 'child_added', '#'.$shop->id.' '.$shop->title, $start - 2);

        $api = $this->domains['api.example.com'];
        $deploy = $this->pastIncident('demo:deploy', 'http', 'critical', __('demo.data.incidents.deploy_title'), __('demo.data.incidents.deploy_text'), $api, 50 * 60 - 2, 50 * 60 - 23, __('demo.data.incidents.resolution_deploy'));
        $deployment = Deployment::query()->where('version', '2.14.0')->first();

        $learning = new IncidentLearning;
        $learning->forceFill([
            'incident_id' => $deploy->id, 'cause_category' => 'deployment', 'cause_status' => 'confirmed', 'root_cause' => __('demo.data.learning.cause'),
            'symptoms' => __('demo.data.learning.symptoms'), 'resolution_steps' => __('demo.data.learning.steps'), 'outcome' => 'successful',
            'time_to_resolve_minutes' => 21, 'deployment_id' => $deployment?->id, 'recorded_by' => $this->user->id,
        ])->save();

        $postmortem = new Postmortem;
        $postmortem->forceFill([
            'incident_id' => $deploy->id, 'summary' => __('demo.data.postmortem.summary'), 'impact' => __('demo.data.postmortem.impact'),
            'went_well' => __('demo.data.postmortem.went_well'), 'went_wrong' => __('demo.data.postmortem.went_wrong'), 'updated_by' => $this->user->id,
            // Owner and state per action item; the texts come from the lang file.
            'action_items' => array_map(fn ($text, $meta) => ['text' => $text, 'owner' => $meta[0], 'done' => $meta[1]], __('demo.data.postmortem.actions'), [['Sam', true], ['Robin', false], [null, false]]),
        ])->save();

        $this->investigate($deploy, withAi: true);
        $this->investigate($cpu);
    }

    private function activeIncidents(): void
    {
        // The offline edge server: the agent-offline alert opens the incident (as in production).
        $edge = $this->servers[DemoProfiles::OFFLINE];
        $rule = AlertRule::query()->where('metric', AlertRule::METRIC_AGENT_OFFLINE)->first();
        $alert = Alert::query()->create([
            'server_id' => $edge->id, 'alert_rule_id' => $rule->id, 'metric' => AlertRule::METRIC_AGENT_OFFLINE, 'observed_value' => DemoProfiles::OFFLINE_FOR_MINUTES,
            'threshold' => 5, 'status' => 'active', 'triggered_at' => $edge->last_seen_at,
        ]);
        $incident = Incident::query()->find($alert->fresh()->incident_id);

        if ($incident) {
            $incident->forceFill(['opened_at' => $this->at(DemoProfiles::OFFLINE_FOR_MINUTES - 5), 'started_at' => $edge->last_seen_at])->save();
            $this->incidents->transition($incident, 'acknowledged', $this->user);
            $incident->forceFill(['acknowledged_at' => $this->at(DemoProfiles::OFFLINE_FOR_MINUTES - 17)])->save();
            $this->event($incident, 'comment', __('demo.data.incidents.note_ack'), DemoProfiles::OFFLINE_FOR_MINUTES - 18, $this->user->id);
            $this->investigate($incident);
        }

        // The stopped queue worker.
        $horizon = ServiceCheck::query()->where('server_id', $this->servers['worker-01']->id)->where('name', 'horizon')->first();
        $service = $this->incidents->trigger(new Signal('service:'.$horizon->id, 'service', 'high', 'horizon '.(app()->getLocale() === 'nl' ? 'gestopt op' : 'stopped on').' worker-01', null, $horizon, startedAt: $this->at(95)));
        $service->forceFill(['opened_at' => $this->at(93)])->save();
    }

    private function jobs(): void
    {
        $make = function (string $key, ?Server $server, array $schedule, string $severity) {
            $job = new Heartbeat(['name' => __("demo.data.jobs.{$key}"), 'server_id' => $server?->id, 'grace_minutes' => $key === 'scheduler' ? 3 : 15, 'severity' => $severity, 'timezone' => 'Europe/Amsterdam', ...$schedule]);
            $job->forceFill(['token' => Heartbeat::newToken(), 'status' => 'new'])->save();

            return $job;
        };
        $runs = function (Heartbeat $job, int $every, int $count, int $offset = 0, array $fails = []) {
            $rows = [];
            for ($i = $count; $i >= 1; $i--) {
                $at = $this->at($offset + $every * ($i - 1));
                $failed = in_array($i, $fails, true);
                $rows[] = ['heartbeat_id' => $job->id, 'kind' => 'start', 'exit_code' => null, 'duration_ms' => null, 'body' => null, 'received_at' => $at->subSeconds(40)];
                $rows[] = ['heartbeat_id' => $job->id, 'kind' => $failed ? 'fail' : 'success', 'exit_code' => $failed ? 1 : 0, 'duration_ms' => 38_000 + $i * 211, 'body' => $failed ? "Importing products.csv\nERROR: supplier API returned 503" : null, 'received_at' => $at];
            }
            DB::table('heartbeat_pings')->insert($rows);
            $last = $this->at($offset);
            $job->forceFill(['status' => 'up', 'last_ping_at' => $last, 'last_start_at' => $last->subSeconds(40), 'last_duration_ms' => 38_211, 'next_due_at' => $job->dueAfter($last)])->save();
        };

        // On time: the nightly backup and the scheduler (kept up to date by the tick).
        $backup = $make('backup', $this->servers['db-01'], ['schedule_type' => 'cron', 'cron_expression' => '0 3 * * *'], 'high');
        $lastBackup = (int) $this->now->diffInMinutes($this->now->setTimezone('Europe/Amsterdam')->setTime(3, 0)->subDays($this->now->setTimezone('Europe/Amsterdam')->hour < 3 ? 1 : 0), true);
        $runs($backup, 1440, 7, $lastBackup);
        $scheduler = $make('scheduler', $this->servers['web-01'], ['schedule_type' => 'interval', 'period_minutes' => 1], 'critical');
        $runs($scheduler, 1, 60);

        // Late: the hourly import failed twice and then went silent.
        $import = $make('import', $this->servers['worker-01'], ['schedule_type' => 'interval', 'period_minutes' => 60], 'warning');
        $runs($import, 60, 24, 180, [1, 2]);
        $import->forceFill(['status' => 'down'])->save();
        $this->incidents->trigger(new Signal($import->fingerprint(), 'heartbeat', 'warning', __('demo.data.incidents.job_title'), null, $import, startedAt: CarbonImmutable::instance($import->next_due_at)));
    }

    private function statusPage(): void
    {
        $page = StatusPage::query()->create(['name' => __('demo.data.status_page'), 'slug' => 'acme-demo-'.app()->getLocale(), 'description' => __('demo.data.status_page_text'), 'is_public' => true, 'show_uptime' => true, 'show_3d_hero' => true, 'accent_color' => '#2f5fd0', 'created_by' => $this->user->id]);
        $components = [['shop', $this->domains['shop.example.com']], ['api', $this->domains['api.example.com']], ['blog', $this->domains['blog.example.com']], ['db', $this->servers['db-01']]];
        foreach ($components as $i => [$key, $subject]) {
            $page->components()->create(['display_name' => __("demo.data.components.{$key}"), 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id, 'sort_order' => $i]);
        }
    }

    /** The capacity forecast of the filling disk and other findings, from the seeded history. */
    private function predictions(): void
    {
        $analyzer = app(PredictiveAnalyzer::class);
        foreach ($this->servers as $server) {
            rescue(fn () => $analyzer->analyzeServer($server->fresh()), report: false);
        }
    }

    private function pastIncident(string $fingerprint, string $source, string $severity, string $title, ?string $text, $subject, int $openedAgo, int $resolvedAgo, ?string $resolution): Incident
    {
        $incident = Incident::query()->create([
            'fingerprint' => $fingerprint, 'source' => $source, 'severity' => $severity, 'status' => 'resolved', 'title' => $title, 'description' => $text,
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id, 'started_at' => $this->at($openedAgo + 1), 'opened_at' => $this->at($openedAgo),
            'last_occurrence_at' => $this->at($resolvedAgo + 2), 'acknowledged_at' => $this->at($openedAgo - 3), 'acknowledged_by' => $this->user->id,
            'resolved_at' => $this->at($resolvedAgo), 'resolved_by' => $resolution ? $this->user->id : null, 'resolution_notes' => $resolution, 'occurrence_count' => 1,
        ]);
        $this->event($incident, 'opened', null, $openedAgo);
        $this->event($incident, 'acknowledged', null, $openedAgo - 3, $this->user->id);
        $this->event($incident, 'resolved', $resolution, $resolvedAgo, $resolution ? $this->user->id : null);

        return $incident;
    }

    private function event(Incident $incident, string $type, ?string $message, int $minutesAgo, ?int $user = null): void
    {
        IncidentEvent::query()->create(['incident_id' => $incident->id, 'type' => $type, 'message' => $message, 'user_id' => $user, 'created_at' => $this->at($minutesAgo)]);
    }

    /** Runs the rule-based investigation now; with $withAi also stores an example AI interpretation (no provider call). */
    private function investigate(Incident $incident, bool $withAi = false): void
    {
        try {
            $run = app(Investigations::class)->request($incident, 'manual', $this->user);
            if (! $run instanceof Investigation) {
                return;
            }
            app(InvestigationRunner::class)->run($run->id);

            if (! $withAi || ! ($finished = Investigations::latestWithResult($incident))) {
                return;
            }

            $contract = app(InvestigationAiContract::class);
            $input = $contract->input($finished, $incident);
            $first = $input['candidates'][0] ?? null;
            if ($first === null) {
                return;
            }
            $result = InvestigationAiContract::validate([
                'summary' => __('demo.data.ai.summary'),
                'observed_facts' => [['statement' => __('demo.data.ai.explanation'), 'evidence' => $first['supporting_evidence']]],
                'leading' => ['candidate' => $first['id'], 'explanation' => __('demo.data.ai.explanation'), 'supporting_evidence' => $first['supporting_evidence'], 'contradicting_evidence' => []],
                'alternatives' => [],
                'confidence' => ['level' => 'medium', 'explanation' => __('demo.data.ai.confidence')],
                'uncertainties' => [__('demo.data.ai.uncertainty')],
                'next_steps' => [['step' => __('demo.data.ai.step'), 'evidence' => $first['supporting_evidence']]],
                'limitations' => [],
                'historical_references' => [],
            ], $input);
            IncidentAnalysis::query()->forceCreate([
                'team_id' => $this->team->id, 'incident_id' => $incident->id, 'investigation_id' => $finished->id, 'status' => 'completed', 'trigger' => 'manual',
                'evidence_hash' => InvestigationAiContract::hash($input), 'evidence' => $input, 'result' => $result, 'provider' => 'demo', 'model' => 'demo', 'completed_at' => $this->now,
            ]);
        } catch (Throwable $exception) {
            // The demo works without an investigation; it just shows "not investigated".
            report($exception);
        }
    }
}
