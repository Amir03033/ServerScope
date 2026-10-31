<?php

namespace App\Services\Notifications;

use App\Models\DnsChange;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Models\PageChange;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\DnsChangeNotification;
use App\Notifications\IncidentNotification;
use App\Notifications\PageChangeNotification;
use App\Notifications\TestNotification;
use App\Support\Locale;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Decides who is notified about what, and records every delivery.
 *
 * - Recipients are always members of the team the incident, domain or
 *   page belongs to; never all users.
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
        if ($reason = $this->suppressionReason($incident, $kind)) {
            $this->logSuppressed($incident, $kind, $reason);

            return;
        }

        if ($kind === IncidentNotification::REOPENED && $this->inCooldown($incident)) {
            $this->logSuppressed($incident, $kind, 'Onderdrukt: incident heropend binnen de cooldown na de vorige melding.');

            return;
        }

        $recipients = $this->members($incident->team_id)
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
        // The env list (SERVERSCOPE_ESCALATION_EMAILS) belongs to the platform
        // owner and only applies to the default team. Every team can add its
        // own addresses (teams.escalation_emails); a team never receives
        // those of another.
        if (in_array($kind, [IncidentNotification::UNACKNOWLEDGED], true)) {
            foreach ($this->escalationEmails($incident->team_id) as $email) {
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

        $users = $this->members($domain->team_id)->where('notify_dns_changes', true)
            ->where(fn ($q) => $q->where('notify_email', true)->orWhere('notify_in_app', true))
            ->get();

        foreach ($users as $user) {
            $channels = $this->allowedChannels($user, $user->notificationChannels(), null, 'dns_changed', $domain->team_id);

            if ($channels !== []) {
                $this->send($user, new DnsChangeNotification($domain, $changes, $channels), null, 'dns_changed', $domain->team_id);
            }
        }
    }

    /** A significant content change of a page that opted in to notifications. */
    public function pageChange(MonitoredPage $page, PageChange $change): void
    {
        $key = "notify:page-change:{$page->id}";

        if (! Cache::add($key, true, now()->addMinutes((int) config('serverscope.notifications.dns_cooldown_minutes', 30)))) {
            return;
        }

        $users = $this->members($page->team_id)->where('notify_dns_changes', true)
            ->where(fn ($q) => $q->where('notify_email', true)->orWhere('notify_in_app', true))
            ->get();

        foreach ($users as $user) {
            $channels = $this->allowedChannels($user, $user->notificationChannels(), null, 'page_changed', $page->team_id);

            if ($channels !== []) {
                $this->send($user, new PageChangeNotification($page, $change, $channels), null, 'page_changed', $page->team_id);
            }
        }

        app(WebhookDispatcher::class)->pageChange($page, $change);
    }

    public function test(User $user): void
    {
        $channels = $user->notificationChannels() ?: ['database'];
        $this->send($user, new TestNotification($channels), null, 'test', \App\Tenancy\Tenant::id() ?? $user->current_team_id);
    }

    /** Users who are a member of the team. */
    private function members(?int $teamId)
    {
        return User::query()->whereHas('teams', fn ($q) => $q->where('teams.id', $teamId));
    }

    /** @return array<int, string> */
    private function escalationEmails(?int $teamId): array
    {
        $team = $teamId === null ? null : \App\Models\Team::query()->find($teamId);

        if ($team === null) {
            return [];
        }

        $emails = (array) ($team->escalation_emails ?? []);

        if ($team->is_default) {
            $emails = [...(array) config('serverscope.escalation.emails', []), ...$emails];
        }

        return array_values(array_unique(array_map('strtolower', $emails)));
    }

    private function isDefaultTeam(?int $teamId): bool
    {
        return $teamId !== null && \App\Models\Team::query()->whereKey($teamId)->where('is_default', true)->exists();
    }

    private function allowedChannels(User $user, array $channels, ?Incident $incident, string $kind, ?int $teamId = null): array
    {
        if (! in_array('mail', $channels, true)) {
            return $channels;
        }

        $key = 'notify:mail:'.$user->id;
        $limit = (int) config('serverscope.notifications.max_mails_per_hour', 30);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            NotificationLog::query()->create([
                'team_id' => $incident?->team_id ?? $teamId,
                'user_id' => $user->id, 'incident_id' => $incident?->id, 'kind' => $kind, 'channel' => 'mail',
                'status' => 'suppressed', 'error' => "Maximum van {$limit} e-mails per uur bereikt.",
            ]);

            return array_values(array_diff($channels, ['mail']));
        }

        RateLimiter::hit($key, 3600);

        return $channels;
    }

    private function send(User $user, BaseNotification $notification, ?Incident $incident, string $kind, ?int $teamId = null): void
    {
        $notification->id = (string) Str::uuid();

        foreach ($notification->via($user) as $channel) {
            $this->log($notification, $user, null, $incident, $kind, $channel, $teamId);
        }

        $user->notify($notification);
    }

    private function log(BaseNotification $notification, ?User $user, ?string $recipient, ?Incident $incident, string $kind, string $channel, ?int $teamId = null): void
    {
        NotificationLog::query()->create([
            'team_id' => $incident?->team_id ?? $teamId,
            'notification_id' => $notification->id,
            'user_id' => $user?->id,
            'recipient' => $recipient,
            'incident_id' => $incident?->id,
            'kind' => $kind,
            'channel' => $channel,
            'status' => 'queued',
            // As the recipient sees it: in their language (the app default without a user).
            'subject' => method_exists($notification, 'subjectLine') ? mb_substr(Locale::using($user?->preferredLocale(), fn () => $notification->subjectLine()), 0, 255) : null,
        ]);
    }

    /**
     * Incident-aware suppression, applied before recipients are chosen:
     * - an incident grouped under a root that is still active is covered
     *   by the root's notifications (one alert per underlying problem);
     * - notifications can be switched off per resource (the incident is
     *   still recorded).
     */
    public function suppressionReason(Incident $incident, string $kind): ?string
    {
        if ($incident->isSuppressedByParent()) {
            return "Onderdrukt: onderdeel van actief incident #{$incident->parent_id}.";
        }

        $subject = $incident->subject;

        foreach (['notifications_enabled', 'alerts_enabled'] as $flag) {
            if ($subject !== null && $subject->getAttribute($flag) !== null && ! $subject->getAttribute($flag)) {
                return 'Onderdrukt: meldingen voor deze resource staan uit.';
            }
        }

        return null;
    }

    private function inCooldown(Incident $incident): bool
    {
        $minutes = (int) config('serverscope.notifications.reopen_cooldown_minutes', 15);

        return $minutes > 0 && $incident->last_notified_at !== null && $incident->last_notified_at->gt(now()->subMinutes($minutes));
    }

    private function logSuppressed(Incident $incident, string $kind, string $reason): void
    {
        NotificationLog::query()->create([
            'team_id' => $incident->team_id,
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

        NotificationLog::query()->withoutGlobalScopes()
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

        NotificationLog::query()->withoutGlobalScopes()
            ->where('notification_id', $notificationId)
            ->where('status', 'queued')
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'updated_at' => now()]);
    }
}
