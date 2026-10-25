<?php

namespace Tests\Feature;

use App\Events\DnsRecordsChanged;
use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\DnsChangeNotification;
use App\Notifications\IncidentNotification;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private IncidentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(IncidentManager::class);
    }

    private function incident(string $fp = 'n:1', string $severity = 'warning'): Incident
    {
        return $this->manager->trigger(new Signal($fp, 'manual', $severity, "Incident {$fp}", 'Beschrijving'));
    }

    public function test_recipients_follow_their_preferences(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $emailOnly = User::factory()->create(['notify_email' => true, 'notify_in_app' => false]);
        $criticalOnly = User::factory()->create(['notify_in_app' => true, 'notify_min_severity' => 'critical']);
        $noResolved = User::factory()->create(['notify_in_app' => true, 'notify_resolved' => false]);
        $optedOut = User::factory()->create();

        $this->assertTrue($admin->notify_email && $admin->notify_in_app, 'admins are notified by default');
        $this->assertFalse($optedOut->notify_email || $optedOut->notify_in_app);

        $this->incident('n:1', 'warning');

        Notification::assertSentTo($admin, IncidentNotification::class, fn ($n, $channels) => $channels === ['mail', 'database']);
        Notification::assertSentTo($emailOnly, IncidentNotification::class, fn ($n, $channels) => $channels === ['mail']);
        Notification::assertNotSentTo($criticalOnly, IncidentNotification::class);
        Notification::assertSentTo($noResolved, IncidentNotification::class);
        Notification::assertNotSentTo($optedOut, IncidentNotification::class);

        $this->incident('n:2', 'critical');
        Notification::assertSentTo($criticalOnly, IncidentNotification::class);

        Notification::fake();
        $this->manager->recover('n:1', 'ok');
        Notification::assertSentTo($admin, IncidentNotification::class, fn ($n) => $n->kind === 'resolved');
        Notification::assertNotSentTo($noResolved, IncidentNotification::class);
    }

    public function test_in_app_notifications_and_delivery_log(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $incident = $this->incident();

        $this->assertSame(1, $admin->unreadNotifications()->count());
        $notification = $admin->notifications()->first();
        $this->assertSame($incident->id, $notification->data['incident_id']);

        // Mail (array driver) and database deliveries are both confirmed.
        $logs = NotificationLog::query()->where('user_id', $admin->id)->get();
        $this->assertEqualsCanonicalizing(['mail', 'database'], $logs->pluck('channel')->all());
        $this->assertSame(['sent'], $logs->pluck('status')->unique()->values()->all());
        $this->assertNotNull($logs->first()->sent_at);

        $this->actingAs($admin)->get(route('dashboard'))->assertSee('<span class="nav-count info">1</span>', false);

        $this->actingAs($admin)->post(route('notifications.read', $notification->id))
            ->assertRedirect(route('incidents.show', $incident, false));
        $this->assertSame(0, $admin->unreadNotifications()->count());

        $this->actingAs($admin)->get(route('notifications.index'))->assertOk()->assertSee($incident->title);
        $this->actingAs($admin)->get(route('notifications.index', ['tab' => 'deliveries']))->assertOk()->assertSee('Verzonden');
    }

    public function test_users_cannot_read_others_notifications_or_be_redirected_off_site(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['is_admin' => true]);
        $this->incident();

        $foreign = $other->notifications()->first();
        $this->actingAs($admin)->post(route('notifications.read', $foreign->id))->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);

        $own = $admin->notifications()->first();
        $own->forceFill(['data' => [...$own->data, 'url' => '//evil.example/phish']])->save();
        $this->actingAs($admin)->from('/notifications')->post(route('notifications.read', $own->id))->assertRedirect('/notifications');
    }

    public function test_non_admins_only_see_their_own_deliveries(): void
    {
        User::factory()->create(['is_admin' => true]);
        $viewer = User::factory()->create(['notify_in_app' => true]);
        $this->incident();

        $logs = $this->actingAs($viewer)->get(route('notifications.index', ['tab' => 'deliveries']))->viewData('deliveries');
        $this->assertSame([$viewer->id], $logs->pluck('user_id')->unique()->values()->all());
    }

    public function test_failed_delivery_is_recorded(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->incident();

        $notification = null;
        Notification::assertSentTo($admin, IncidentNotification::class, function ($n) use (&$notification) {
            $notification = $n;

            return true;
        });

        $notification->failed(new \RuntimeException('SMTP 550 mailbox unavailable'));

        $log = NotificationLog::query()->where('notification_id', $notification->id)->where('channel', 'mail')->sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('SMTP 550', $log->error);
    }

    public function test_reopen_within_cooldown_is_suppressed(): void
    {
        Notification::fake();
        config(['serverscope.notifications.reopen_cooldown_minutes' => 15, 'serverscope.incidents.reopen_window_minutes' => 60]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->incident();
        $this->manager->recover('n:1', 'ok');
        $this->travel(5)->minutes();
        $this->incident(); // reopen, within cooldown of the recovery mail

        Notification::assertSentToTimes($admin, IncidentNotification::class, 2); // opened + resolved
        $this->assertDatabaseHas('notification_logs', ['kind' => 'reopened', 'status' => 'suppressed']);

        $this->manager->recover('n:1', 'ok');
        $this->travel(20)->minutes();
        $this->incident();
        Notification::assertSentTo($admin, IncidentNotification::class, fn ($n) => $n->kind === 'reopened');
    }

    public function test_hourly_mail_cap_falls_back_to_in_app(): void
    {
        Notification::fake();
        config(['serverscope.notifications.max_mails_per_hour' => 2]);
        $admin = User::factory()->create(['is_admin' => true]);

        foreach (range(1, 3) as $i) {
            $this->incident("cap:{$i}");
        }

        $channels = Notification::sent($admin, IncidentNotification::class)->map(fn ($n) => $n->channels)->all();
        $this->assertSame([['mail', 'database'], ['mail', 'database'], ['database']], $channels);
        $this->assertDatabaseHas('notification_logs', ['user_id' => $admin->id, 'channel' => 'mail', 'status' => 'suppressed']);
    }

    public function test_unacknowledged_incidents_are_escalated_once(): void
    {
        Notification::fake();
        config(['serverscope.escalation.after_minutes' => ['critical' => 15, 'warning' => 60], 'serverscope.escalation.emails' => ['oncall@example.com']]);
        $admin = User::factory()->create(['is_admin' => true]);

        $critical = $this->incident('esc:1', 'critical');
        $acknowledged = $this->incident('esc:2', 'critical');
        $this->manager->transition($acknowledged, 'acknowledged', $admin);
        $warning = $this->incident('esc:3', 'warning');

        $this->travel(16)->minutes();
        $this->artisan('serverscope:escalate-incidents')->assertSuccessful();
        $this->artisan('serverscope:escalate-incidents')->assertSuccessful();

        $this->assertNotNull($critical->fresh()->escalated_at);
        $this->assertNull($acknowledged->fresh()->escalated_at);
        $this->assertNull($warning->fresh()->escalated_at);
        $this->assertSame(1, $critical->events()->where('type', 'escalated')->count());

        Notification::assertSentToTimes($admin, IncidentNotification::class, 4); // 3 opened + 1 escalation
        Notification::assertSentOnDemand(IncidentNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'oncall@example.com' && $n->kind === 'unacknowledged');
    }

    public function test_dns_change_notifications_are_deduplicated_per_domain(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $optOut = User::factory()->create(['is_admin' => true, 'notify_dns_changes' => false]);
        $domain = Domain::create(['name' => 'example.com']);
        $change = $domain->dnsChanges()->create(['record_type' => 'NS', 'previous' => ['a.ns.'], 'current' => ['b.ns.'], 'added' => ['b.ns.'], 'removed' => ['a.ns.'], 'detected_at' => now()]);

        DnsRecordsChanged::dispatch($domain, [$change]);
        DnsRecordsChanged::dispatch($domain, [$change]);

        Notification::assertSentToTimes($admin, DnsChangeNotification::class, 1);
        Notification::assertNotSentTo($optOut, DnsChangeNotification::class);

        $mail = (new DnsChangeNotification($domain, [$change]))->toMail($admin);
        $this->assertStringContainsString('example.com (NS)', $mail->subject);
        $this->assertStringContainsString('b.ns.', (string) $mail->render());
    }

    public function test_profile_preferences_and_test_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.notifications'), [
            'notify_email' => '1', 'notify_in_app' => '1', 'notify_resolved' => '0', 'notify_dns_changes' => '0', 'notify_min_severity' => 'critical',
        ])->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue($user->notify_email);
        $this->assertFalse($user->notify_resolved);
        $this->assertSame('critical', $user->notify_min_severity);

        $this->actingAs($user)->put(route('profile.notifications'), ['notify_min_severity' => 'everything'])->assertSessionHasErrors('notify_min_severity');

        foreach (range(1, 3) as $i) {
            $this->actingAs($user)->post(route('profile.notifications.test'))->assertSessionHas('success');
        }
        $this->actingAs($user)->post(route('profile.notifications.test'))->assertSessionHas('error');
        Notification::assertSentToTimes($user, \App\Notifications\TestNotification::class, 3);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password' => 'wrong', 'password' => 'NewPassword1234', 'password_confirmation' => 'NewPassword1234',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password' => 'OldPassword123', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrorsIn('password', 'password');

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password' => 'OldPassword123', 'password' => 'NewPassword1234', 'password_confirmation' => 'NewPassword1234',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewPassword1234', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed']);
        $this->assertStringNotContainsString('NewPassword1234', json_encode(\App\Models\AuditLog::query()->get()->toArray()));
    }
}
