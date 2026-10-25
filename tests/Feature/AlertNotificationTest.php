<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Server;
use App\Models\User;
use App\Notifications\AlertStateChanged;
use App\Services\AgentHeartbeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        AlertRule::query()->forceDelete();
        Notification::fake();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->viewer = User::factory()->create(['is_admin' => false]);
    }

    public function test_admins_are_notified_once_when_an_alert_triggers(): void
    {
        $this->offlineRule();
        $this->server(now()->subMinutes(10));

        $service = app(AgentHeartbeatService::class);
        $service->check();
        $this->travel(1)->minutes();
        $service->check();
        $this->travel(1)->minutes();
        $service->check();

        Notification::assertSentToTimes($this->admin, AlertStateChanged::class, 1);
        Notification::assertSentTo(
            $this->admin,
            AlertStateChanged::class,
            fn (AlertStateChanged $n) => $n->transition === AlertStateChanged::TRIGGERED,
        );
        Notification::assertNotSentTo($this->viewer, AlertStateChanged::class);
    }

    public function test_admins_are_notified_on_recovery(): void
    {
        $this->offlineRule();
        $server = $this->server(now()->subMinutes(10));

        $service = app(AgentHeartbeatService::class);
        $service->check();

        $server->forceFill(['last_seen_at' => now()])->save();
        $service->check();

        Notification::assertSentToTimes($this->admin, AlertStateChanged::class, 2);
        Notification::assertSentTo(
            $this->admin,
            AlertStateChanged::class,
            fn (AlertStateChanged $n) => $n->transition === AlertStateChanged::RESOLVED,
        );
    }

    public function test_metric_alerts_from_agent_samples_notify_on_trigger_and_recovery(): void
    {
        AlertRule::create([
            'name' => 'Hoge CPU',
            'metric' => 'cpu_percent',
            'threshold' => 80,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => true,
        ]);

        [, $token] = $this->agent();
        $start = now()->subMinute()->startOfMinute();

        foreach ([95, 96, 97, 20] as $i => $cpu) {
            $this->withToken($token)->postJson('/api/agent/metrics', [
                'collected_at' => $start->copy()->addSeconds($i * 20)->toIso8601String(),
                'cpu_percent' => $cpu,
            ])->assertCreated();
        }

        $this->assertSame('resolved', Alert::query()->sole()->status);

        $transitions = Notification::sent($this->admin, AlertStateChanged::class)
            ->map(fn (AlertStateChanged $n) => $n->transition)
            ->all();

        $this->assertSame(
            [AlertStateChanged::TRIGGERED, AlertStateChanged::RESOLVED],
            $transitions,
        );
    }

    public function test_disabling_a_rule_does_not_send_recovery_notifications(): void
    {
        $rule = $this->offlineRule();
        $this->server(now()->subMinutes(10));
        app(AgentHeartbeatService::class)->check();

        $this->actingAs($this->admin)
            ->put(route('alert-rules.update', $rule), [
                'name' => $rule->name,
                'metric' => 'agent_offline',
                'threshold' => 5,
                'operator' => '>=',
                'duration_minutes' => 1,
                'is_enabled' => false,
            ])
            ->assertRedirect();

        $this->assertSame('disabled', Alert::query()->sole()->status);
        Notification::assertSentToTimes($this->admin, AlertStateChanged::class, 1);
    }

    public function test_notifications_can_be_disabled_by_configuration(): void
    {
        config(['serverscope.alert_mail_enabled' => false]);

        $this->offlineRule();
        $this->server(now()->subMinutes(10));
        app(AgentHeartbeatService::class)->check();

        $this->assertSame(1, Alert::query()->count());
        Notification::assertNothingSent();
    }

    public function test_mail_contains_alert_details_without_infrastructure_secrets(): void
    {
        $this->offlineRule();
        $server = $this->server(now()->subMinutes(10));
        $server->forceFill(['ip_address' => '203.0.113.7'])->save();
        app(AgentHeartbeatService::class)->check();

        $mail = (new AlertStateChanged(Alert::query()->sole(), AlertStateChanged::TRIGGERED))
            ->toMail($this->admin);

        $this->assertStringContainsString('Agent offline', $mail->subject);
        $this->assertStringContainsString($server->name, $mail->subject);

        $rendered = (string) $mail->render();
        $this->assertStringContainsString('10,0 min', $rendered);
        $this->assertStringNotContainsString('203.0.113.7', $rendered);
    }

    public function test_notification_is_queued_with_retries(): void
    {
        $notification = new AlertStateChanged(new Alert, AlertStateChanged::TRIGGERED);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
        $this->assertSame(3, $notification->tries);
        $this->assertTrue($notification->afterCommit);
    }

    public function test_scheduler_runs_a_queue_worker(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains($event->command ?? '', 'queue:work --stop-when-empty'));

        $this->assertTrue($scheduled);
    }

    private function offlineRule(): AlertRule
    {
        return AlertRule::create([
            'name' => 'Agent offline',
            'metric' => 'agent_offline',
            'threshold' => 5,
            'operator' => '>=',
            'duration_minutes' => 1,
            'is_enabled' => true,
        ]);
    }

    private function server($lastSeenAt): Server
    {
        return Server::create([
            'name' => 'VPS '.uniqid(),
            'hostname' => uniqid().'.example.test',
            'status' => 'online',
            'last_seen_at' => $lastSeenAt,
        ]);
    }

    private function agent(): array
    {
        $server = $this->server(null);
        $token = bin2hex(random_bytes(32));

        $server->agentCredentials()->create([
            'name' => 'Test agent',
            'token_hash' => hash('sha256', $token),
        ]);

        return [$server, $token];
    }
}
