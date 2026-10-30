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
        return Cache::remember(\App\Support\Locale::cacheKey('serverscope:system-health:summary:'.($teamId ?? 'platform')), 60, fn () => $this->report($teamId)['status']);
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $at = $beat ? Carbon::parse($beat) : null;
        $minutes = $at?->diffInMinutes(now());

        return [
            'key' => 'scheduler',
            'label' => __('system_health.checks.scheduler'),
            'status' => $at === null ? 'critical' : ($minutes > 5 ? 'critical' : ($minutes > 2 ? 'warning' : 'healthy')),
            'detail' => $at === null
                ? __('system_health.detail.no_heartbeat')
                : __('system_health.detail.heartbeat', ['time' => $at->locale(app()->getLocale())->diffForHumans()]),
        ];
    }

    private function queue(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return ['key' => 'queue', 'label' => __('system_health.checks.queue'), 'status' => 'healthy', 'detail' => __('system_health.detail.queue_driver', ['driver' => config('queue.default')])];
        }

        try {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        } catch (Throwable) {
            return ['key' => 'queue', 'label' => __('system_health.checks.queue'), 'status' => 'warning', 'detail' => __('system_health.detail.queue_unreadable')];
        }

        $age = $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : 0;

        return [
            'key' => 'queue',
            'label' => __('system_health.checks.queue'),
            'status' => $age > 900 ? 'critical' : ($age > 180 || $failed > 0 ? 'warning' : 'healthy'),
            'detail' => __('system_health.detail.queue_pending', ['count' => $pending])
                .($age > 0 ? __('system_health.detail.queue_oldest', ['minutes' => \App\Support\Format::number($age / 60, 1)]) : '')
                .__('system_health.detail.queue_failed', ['count' => $failed]),
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
            'label' => __('system_health.checks.overdue'),
            'status' => $total === 0 ? 'healthy' : ($total > 10 ? 'critical' : 'warning'),
            'detail' => $total === 0 ? __('system_health.detail.on_schedule') : __('system_health.detail.overdue', ['domains' => $domains, 'pages' => $pages, 'minutes' => self::OVERDUE_MINUTES]),
        ];
    }

    private function deliveries(): array
    {
        $since = now()->subDay();
        $mailFailed = NotificationLog::query()->where('status', 'failed')->where('created_at', '>=', $since)->count();
        $webhooksFailed = WebhookDelivery::query()->where('status', 'failed')->where('created_at', '>=', $since)->count();

        return [
            'key' => 'deliveries',
            'label' => __('system_health.checks.deliveries'),
            'status' => $mailFailed + $webhooksFailed === 0 ? 'healthy' : 'warning',
            'detail' => __('system_health.detail.deliveries', ['mail' => $mailFailed, 'webhooks' => $webhooksFailed]),
        ];
    }

    private function discovery(): array
    {
        $failed = PageDiscoveryRun::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();

        return [
            'key' => 'discovery',
            'label' => __('system_health.checks.discovery'),
            'status' => $failed === 0 ? 'healthy' : 'warning',
            'detail' => __('system_health.detail.discovery', ['count' => $failed]),
        ];
    }
}
