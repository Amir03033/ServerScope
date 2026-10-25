<?php

namespace App\Services\Notifications;

use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\DnsChangeNotification;
use App\Notifications\IncidentNotification;
use App\Notifications\TestNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Decides who is notified about what, and records every delivery.
 *
 * - Recipients follow their own preferences (channels, minimum severity,
 *   recoveries, DNS changes).
 * - Flapping: a reopen within the cooldown after the last notification
 *   is suppressed (and logged as such).
 * - Each user receives at most N e-mails per hour; the rest is logged as
 *   suppressed. In-app notifications are not capped.
 * - Delivery is asynchronous (queued notifications with retries); the
 *   log moves from queued to sent or failed.
 */
class NotificationDispatcher
{
    public function incident(Incident $incident, string $kind): void
    {
        if ($kind === IncidentNotification::REOPENED && $this->inCooldown($incident)) {
            $this->logSuppressed($incident, $kind, 'Onderdrukt: incident heropend binnen de cooldown na de vorige melding.');

            return;
        }

        $recipients = User::query()
            ->where(fn ($q) => $q->where('notify_email', true)->orWhere('notify_in_app', true))
            ->get()
            ->filter(fn (User $user) => $user->wantsSeverity($incident->severity))
            ->filter(fn (User $user) => $kind !== IncidentNotification::RESOLVED || $user->notify_resolved);

        $sent = 0;

        foreach ($recipients as $user) {
            $channels = $this->allowedChannels($user, $user->notificationChannels(), $incident, $kind);

            if ($channels !== []) {
                $this->send($user, new IncidentNotification($incident, $kind, $channels), $incident, $kind);
                $sent++;
            }
        }

        if ($kind !== IncidentNotification::RESOLVED || $sent > 0) {
            $incident->forceFill(['last_notified_at' => now()])->saveQuietly();
        }

        // Escalation contacts outside the user list (e.g. an on-call mailbox).
        if (in_array($kind, [IncidentNotification::UNACKNOWLEDGED], true)) {
            foreach (config('serverscope.escalation.emails', []) as $email) {
                $notification = new IncidentNotification($incident, $kind, ['mail']);
                $notification->id = (string) Str::uuid();
                $this->log($notification, null, $email, $incident, $kind, 'mail');
                Notification::route('mail', $email)->notify($notification);
            }
        }
    }

    /**
     * @param  array<int, DnsChange>  $changes
     */
    public function dnsChange(Domain $domain, array $changes): void
    {
        // One notification per domain per cooldown; the history keeps all.
        $key = "notify:dns:{$domain->id}";

        if (! Cache::add($key, true, now()->addMinutes((int) config('serverscope.notifications.dns_cooldown_minutes', 30)))) {
            return;
        }

        $users = User::query()->where('notify_dns_changes', true)
            ->where(fn ($q) => $q->where('notify_email', true)->orWhere('notify_in_app', true))
            ->get();

        foreach ($users as $user) {
            $channels = $this->allowedChannels($user, $user->notificationChannels(), null, 'dns_changed');

            if ($channels !== []) {
                $this->send($user, new DnsChangeNotification($domain, $changes, $channels), null, 'dns_changed');
            }
        }
    }

    public function test(User $user): void
    {
        $channels = $user->notificationChannels() ?: ['database'];
        $this->send($user, new TestNotification($channels), null, 'test');
    }

    private function allowedChannels(User $user, array $channels, ?Incident $incident, string $kind): array
    {
        if (! in_array('mail', $channels, true)) {
            return $channels;
        }

        $key = 'notify:mail:'.$user->id;
        $limit = (int) config('serverscope.notifications.max_mails_per_hour', 30);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            NotificationLog::query()->create([
                'user_id' => $user->id, 'incident_id' => $incident?->id, 'kind' => $kind, 'channel' => 'mail',
                'status' => 'suppressed', 'error' => "Maximum van {$limit} e-mails per uur bereikt.",
            ]);

            return array_values(array_diff($channels, ['mail']));
        }

        RateLimiter::hit($key, 3600);

        return $channels;
    }

    private function send(User $user, BaseNotification $notification, ?Incident $incident, string $kind): void
    {
        $notification->id = (string) Str::uuid();

        foreach ($notification->via($user) as $channel) {
            $this->log($notification, $user, null, $incident, $kind, $channel);
        }

        $user->notify($notification);
    }

    private function log(BaseNotification $notification, ?User $user, ?string $recipient, ?Incident $incident, string $kind, string $channel): void
    {
        NotificationLog::query()->create([
            'notification_id' => $notification->id,
            'user_id' => $user?->id,
            'recipient' => $recipient,
            'incident_id' => $incident?->id,
            'kind' => $kind,
            'channel' => $channel,
            'status' => 'queued',
            'subject' => method_exists($notification, 'subjectLine') ? mb_substr($notification->subjectLine(), 0, 255) : null,
        ]);
    }

    private function inCooldown(Incident $incident): bool
    {
        $minutes = (int) config('serverscope.notifications.reopen_cooldown_minutes', 15);

        return $minutes > 0 && $incident->last_notified_at !== null && $incident->last_notified_at->gt(now()->subMinutes($minutes));
    }

    private function logSuppressed(Incident $incident, string $kind, string $reason): void
    {
        NotificationLog::query()->create([
            'incident_id' => $incident->id, 'kind' => $kind, 'channel' => '-', 'status' => 'suppressed', 'error' => $reason,
        ]);
    }

    /**
     * Mark deliveries; called from notification events.
     */
    public static function markSent(?string $notificationId, string $channel, object $notifiable): void
    {
        if ($notificationId === null) {
            return;
        }

        NotificationLog::query()
            ->where('notification_id', $notificationId)
            ->where('channel', $channel)
            ->when($notifiable instanceof User, fn ($q) => $q->where('user_id', $notifiable->id))
            ->when($notifiable instanceof AnonymousNotifiable, fn ($q) => $q->whereNull('user_id'))
            ->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
    }

    public static function markFailed(?string $notificationId, string $error, ?string $channel = null): void
    {
        if ($notificationId === null) {
            return;
        }

        NotificationLog::query()
            ->where('notification_id', $notificationId)
            ->where('status', 'queued')
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'updated_at' => now()]);
    }
}
