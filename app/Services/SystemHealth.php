<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Models\NotificationLog;
use App\Models\PageDiscoveryRun;
use App\Models\WebhookDelivery;
use App\Tenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Health of the monitoring system itself: is the scheduler running, is
 * the queue keeping up, are checks running on time and are notifications
 * delivered. Shown to administrators and summarised in the sidebar.
 */
class SystemHealth
{
    public const HEARTBEAT_KEY = 'serverscope:scheduler:heartbeat';

    /** Checks this many minutes past due count as overdue. */
    public const OVERDUE_MINUTES = 10;

    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }

    /**
     * Platform-wide report (no team), or the view of one team: the shared
     * scheduler plus that team's own checks, deliveries and discovery.
     * The queue is shared by all teams and only part of the platform view.
     *
     * @return array{status: string, checks: array<int, array{key: string, label: string, status: string, detail: string}>}
     */
    public function report(?int $teamId = null): array
    {
        $checks = Tenant::run($teamId, fn () => array_values(array_filter([
            $this->scheduler(),
            $teamId === null ? $this->queue() : null,
            $this->overdue(),
            $this->deliveries(),
            $this->discovery(),
        ])));

        $status = collect($checks)->contains('status', 'critical') ? 'critical' : (collect($checks)->contains('status', 'warning') ? 'warning' : 'healthy');

        return ['status' => $status, 'checks' => $checks];
    }

    /** Cheap summary for the sidebar (cached for a minute, per team). */
    public function summary(?int $teamId = null): string
    {
        return Cache::remember('serverscope:system-health:summary:'.($teamId ?? 'platform'), 60, fn () => $this->report($teamId)['status']);
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $at = $beat ? Carbon::parse($beat) : null;
        $minutes = $at?->diffInMinutes(now());

        return [
            'key' => 'scheduler',
            'label' => 'Scheduler',
            'status' => $at === null ? 'critical' : ($minutes > 5 ? 'critical' : ($minutes > 2 ? 'warning' : 'healthy')),
            'detail' => $at === null
                ? 'Nog nooit een hartslag ontvangen. Controleer de cronregel voor `php artisan schedule:run`.'
                : 'Laatste hartslag '.$at->locale('nl')->diffForHumans().'.',
        ];
    }

    private function queue(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return ['key' => 'queue', 'label' => 'Wachtrij', 'status' => 'healthy', 'detail' => 'Wachtrijdriver '.config('queue.default').': achterstand niet meetbaar vanuit de database.'];
        }

        try {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        } catch (Throwable) {
            return ['key' => 'queue', 'label' => 'Wachtrij', 'status' => 'warning', 'detail' => 'Wachtrij kon niet worden uitgelezen.'];
        }

        $age = $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : 0;

        return [
            'key' => 'queue',
            'label' => 'Wachtrij',
            'status' => $age > 900 ? 'critical' : ($age > 180 || $failed > 0 ? 'warning' : 'healthy'),
            'detail' => "{$pending} taken in de wachtrij".($age > 0 ? ', oudste wacht '.round($age / 60, 1).' min' : '')."; {$failed} mislukte taken in 24 uur.",
        ];
    }

    private function overdue(): array
    {
        $cutoff = now()->subMinutes(self::OVERDUE_MINUTES);
        $domains = Domain::query()->where('is_active', true)->where('http_enabled', true)->where('http_next_check_at', '<', $cutoff)->count();
        $pages = MonitoredPage::query()->monitored()->whereHas('domain', fn ($q) => $q->where('is_active', true))->where('next_check_at', '<', $cutoff)->count();
        $total = $domains + $pages;

        return [
            'key' => 'overdue',
            'label' => 'Controles op tijd',
            'status' => $total === 0 ? 'healthy' : ($total > 10 ? 'critical' : 'warning'),
            'detail' => $total === 0 ? 'Alle controles lopen op schema.' : "{$domains} domein- en {$pages} paginacontroles meer dan ".self::OVERDUE_MINUTES.' minuten achter.',
        ];
    }

    private function deliveries(): array
    {
        $since = now()->subDay();
        $mailFailed = NotificationLog::query()->where('status', 'failed')->where('created_at', '>=', $since)->count();
        $webhooksFailed = WebhookDelivery::query()->where('status', 'failed')->where('created_at', '>=', $since)->count();

        return [
            'key' => 'deliveries',
            'label' => 'Meldingen en webhooks',
            'status' => $mailFailed + $webhooksFailed === 0 ? 'healthy' : 'warning',
            'detail' => "{$mailFailed} mislukte meldingen en {$webhooksFailed} mislukte webhookleveringen in 24 uur.",
        ];
    }

    private function discovery(): array
    {
        $failed = PageDiscoveryRun::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();

        return [
            'key' => 'discovery',
            'label' => 'Paginaontdekking',
            'status' => $failed === 0 ? 'healthy' : 'warning',
            'detail' => "{$failed} mislukte ontdekkingen in 24 uur (bestaande monitoring blijft ongewijzigd).",
        ];
    }
}
