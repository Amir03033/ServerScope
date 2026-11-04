@php
    use App\Support\Format;
    use App\Support\Time;

    /* Sparkline as SVG; colours come from CSS (.ss-spark). */
    $spark = function (array $values, string $variant = ''): string {
        $pts = array_values(array_filter($values, fn ($v) => $v !== null));
        if (count($pts) < 2) {
            return '';
        }
        $limit = 48;
        if (count($pts) > $limit) {
            $step = (count($pts) - 1) / ($limit - 1);
            $sampled = [];
            for ($i = 0; $i < $limit; $i++) {
                $sampled[] = $pts[(int) round($i * $step)];
            }
            $pts = $sampled;
        }
        $min = min($pts);
        $range = (max($pts) - $min) ?: 1;
        $n = count($pts);
        $line = [];
        foreach ($pts as $i => $v) {
            $line[] = round($i / ($n - 1) * 100, 2).','.round(26 - (($v - $min) / $range) * 22, 2);
        }
        $d = 'M'.implode(' L', $line);

        return '<svg class="ss-spark '.$variant.'" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">'
            .'<path class="area" d="'.$d.' L100,30 L0,30 Z"/><path class="line" d="'.$d.'"/></svg>';
    };

    $trend = function ($latest, $avg): ?array {
        if ($latest === null || $avg === null || (float) $avg <= 0) {
            return null;
        }
        $pct = (int) round((((float) $latest - (float) $avg) / (float) $avg) * 100);

        return ['pct' => abs($pct), 'up' => $pct >= 0];
    };

    /* Usage bar as SVG: the width is an attribute, not an inline style. */
    $bar = function (?float $percent): string {
        $p = $percent === null ? 0 : min(100, max(0, $percent));
        $tone = $p >= 90 ? ' is-bad' : ($p >= 75 ? ' is-warn' : '');

        return '<svg class="ss-bar'.$tone.'" aria-hidden="true"><rect class="track" width="100%" height="100%"/><rect class="fill" width="'.round($p, 1).'%" height="100%" rx="3"/></svg>';
    };

    $gib = fn ($bytes, int $decimals = 1) => $bytes > 0 ? Format::number($bytes / 1073741824, $decimals).' GiB' : '—';

    $load1Avg = collect($chartData['load1'])->filter(fn ($v) => $v !== null)->avg();
    $cpuTrend = $trend($latestSample?->cpu_percent, $cpuAverage);
    $ramTrend = $trend($memoryPercent, $memoryAverage);
    $loadTrend = $trend($latestSample?->load_1, $load1Avg);

    $primaryDisk = $disks->first();
    $extraDisks = $disks->slice(1);

    $bootedAt = $latestSample?->uptime_seconds !== null
        ? now()->subSeconds((int) $latestSample->uptime_seconds)
        : null;

    $currentRange = (int) ($hours ?? 24);

    /* Needs attention: only what is not in order, each item a link. */
    $ov = $overview;
    $expiringSoon = collect($ov['expirations'])->filter(fn ($item) => $item['days'] <= 30);
    $attention = array_values(array_filter([
        $ov['incidents']['active'] > 0 ? [
            'tone' => $ov['incidents']['critical'] > 0 ? 'is-bad' : 'is-warn', 'icon' => 'i-siren', 'count' => $ov['incidents']['active'],
            'label' => trans_choice('dashboard.attention.incidents', $ov['incidents']['active']),
            'sub' => __('dashboard.attention.incidents_critical', ['count' => $ov['incidents']['critical']]).' · '.__('dashboard.attention.incidents_unacknowledged', ['count' => $ov['incidents']['unacknowledged']]),
            'url' => route('incidents.index'),
        ] : null,
        $offlineCount > 0 ? [
            'tone' => 'is-bad', 'icon' => 'i-server', 'count' => $offlineCount,
            'label' => trans_choice('dashboard.attention.offline', $offlineCount), 'sub' => __('dashboard.attention.offline_sub'),
            'url' => route('servers.index', ['status' => 'offline']),
        ] : null,
        $activeAlertCount > 0 ? [
            'tone' => 'is-warn', 'icon' => 'i-bell', 'count' => $activeAlertCount,
            'label' => trans_choice('dashboard.attention.alerts', $activeAlertCount), 'sub' => __('dashboard.attention.alerts_sub'),
            'url' => route('alerts.index'),
        ] : null,
        $expiringSoon->isNotEmpty() ? [
            'tone' => $expiringSoon->contains(fn ($item) => $item['days'] < 8) ? 'is-bad' : 'is-warn', 'icon' => 'i-calendar', 'count' => $expiringSoon->count(),
            'label' => trans_choice('dashboard.attention.expiring', $expiringSoon->count()), 'sub' => __('dashboard.attention.expiring_sub'),
            'url' => '#expiring',
        ] : null,
        $agentUpdates['outdated'] > 0 ? [
            'tone' => '', 'icon' => 'i-refresh', 'count' => $agentUpdates['outdated'],
            'label' => trans_choice('dashboard.attention.updates', $agentUpdates['outdated']),
            'sub' => __('dashboard.attention.updates_sub', ['version' => $agentUpdates['version'], 'ready' => $agentUpdates['ready']]),
            'url' => route('servers.index'),
        ] : null,
    ]));

    /* Overview figures (used by several widgets). */

    ['servers' => $srv, 'domains' => $dom, 'incidents' => $inc, 'expirations' => $expirations, 'dnsChanges' => $dnsChanges, 'trends' => $trends] = $overview;
    $healthy = $srv['online'] + $dom['healthy'];
    $warnings = $dom['warning'];
    $critical = $srv['offline'] + $dom['critical'];

    /* Mission control (health, tiles, timeline widgets). */
    if ($mission) {
        $m = $mission;
        $ring = 2 * M_PI * 52;
        $from = \Carbon\Carbon::parse($m['timeline']['from']);
        $span = max(1, \Carbon\Carbon::parse($m['timeline']['to'])->getTimestamp() - $from->getTimestamp());
        // Only what mission.js needs (the other texts arrive translated in the data).
        $missionConfig = ['url' => route('dashboard.mission'), 'texts' => ['counts' => __('mission.counts'), 'legend' => __('mission.legend')], 'initial' => $m];
        $pos = fn (?string $iso) => $iso ? max(0, min(100, (\Carbon\Carbon::parse($iso)->getTimestamp() - $from->getTimestamp()) / $span * 100)) : 100;
    }

    /* Widgets: this person's layout (null: the standard layout). */
    $dashboardLayout = \App\Support\DashboardWidgets::normalize(auth()->user()?->dashboard_layout);
    $widgets = \App\Support\DashboardWidgets::resolve($dashboardLayout);
@endphp

<x-layouts.app :title="__('dashboard.title')">
    <x-slot:head>
        @vite('resources/js/charts.js')
        @if ($mission)@vite('resources/js/mission.js')@endif
        @if ($servers->isNotEmpty() && Route::has('nexus.graph'))@vite('resources/js/pulse.js')@endif
    </x-slot:head>

    <x-slot:breadcrumb>
        <span>›</span> {{ __('dashboard.title') }}
    </x-slot:breadcrumb>

    <x-slot:pageTitle>{{ __('dashboard.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('dashboard.subtitle') }}</x-slot:subtitle>

    <x-slot:actions>
        <span class="ss-status {{ $onlineCount > 0 ? 'ss-status-ok' : ($serverCount > 0 ? 'ss-status-bad' : 'ss-status-muted') }}">
            {{ $onlineCount > 0 ? __('dashboard.live.online') : ($serverCount > 0 ? __('dashboard.live.none_online') : __('dashboard.live.waiting')) }}
        </span>
        <span class="text-xs leading-tight text-muted">
            {{ __('dashboard.last_update') }}<br>
            <strong class="font-semibold text-fg">{{ __('dashboard.today_at', ['time' => now()->setTimezone(Time::zone())->format('H:i')]) }}</strong>
        </span>
        <a class="ss-btn ss-btn-secondary ss-btn-icon" href="{{ request()->fullUrl() }}" aria-label="{{ __('dashboard.refresh') }}" title="{{ __('dashboard.refresh') }}">
            <svg class="ico"><use href="#i-refresh"/></svg>
        </a>
        @can('admin')
            <a class="ss-btn ss-btn-primary" href="{{ route('servers.add') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('dashboard.add_server') }}</a>
        @endcan
    </x-slot:actions>

    {{-- The dashboard as widgets, in this person's own order, size and choice
         (DashboardWidgets; the standard layout is the dashboard as it always was).
         Without JavaScript they simply show in that order. --}}
    @php
        $widgetTitles = __('widgets.names');
        $widgetTexts = __('widgets.js');
        $widgetPresets = array_keys(\App\Support\DashboardWidgets::PRESETS);
        $missionWidgets = ['health', 'tiles', 'timeline'];
    @endphp
    {{-- A brand-new account has nothing to show yet: a calm start instead of fourteen empty widgets. --}}
    @if ($servers->isEmpty() && ! \App\Models\Domain::query()->exists())
        @include('dashboard._welcome')
    @else
    <div class="ss-widgets-bar" data-widgets-bar>
        <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-widgets-edit aria-pressed="false" hidden><svg class="ico" aria-hidden="true"><use href="#i-sliders"/></svg> {{ __('widgets.edit') }}</button>
    </div>
    {{-- The demo keeps the arrangement in the browser only: the layouts it needs come along. --}}
    <div class="ss-widgets" data-widgets data-layout-url="{{ route('dashboard.layout') }}" data-layout='@json($dashboardLayout)' data-texts='@json($widgetTexts)' data-presets='@json($widgetPresets)'
        @if (auth()->user()?->is_demo) data-local-layouts='@json(['standard' => \App\Support\DashboardWidgets::normalize(null), 'presets' => collect(\App\Support\DashboardWidgets::PRESETS)->keys()->mapWithKeys(fn ($name) => [$name => \App\Support\DashboardWidgets::preset($name)])])' @endif>
        @foreach ($widgets as $widgetId => $widget)
            @continue(in_array($widgetId, $missionWidgets, true) && ! $mission)
            <div class="ss-widget" data-widget="{{ $widgetId }}" data-size="{{ $widget['size'] }}" data-default-size="{{ \App\Support\DashboardWidgets::WIDGETS[$widgetId] }}" data-title="{{ $widgetTitles[$widgetId] ?? $widgetId }}" @if (in_array($widgetId, ['health', 'tiles'], true)) data-focus-keep @endif @if ($widget['hidden']) hidden @endif>
                @include('dashboard.widgets.'.$widgetId)
            </div>
        @endforeach
    </div>
    @endif

    <script type="application/json" id="chart-data">@json($chartData)</script>
</x-layouts.app>
