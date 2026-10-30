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
@endphp

<x-layouts.app :title="__('dashboard.title')">
    <x-slot:head>
        @vite('resources/js/charts.js')
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

    {{-- Needs attention --}}
    <section class="mb-6" aria-labelledby="attention-title">
        <h2 id="attention-title" class="ss-section-title mb-3">{{ __('dashboard.attention.title') }}</h2>
        @if ($attention === [])
            <div class="ss-alert ss-alert-success" role="status">
                <svg class="ico"><use href="#i-check"/></svg>
                <div><strong>{{ __('dashboard.attention.all_good') }}</strong> <span class="text-muted">{{ __('dashboard.attention.all_good_text') }}</span></div>
            </div>
        @else
            <div class="ss-attention">
                @foreach ($attention as $item)
                    <a class="ss-attention-item {{ $item['tone'] }}" href="{{ $item['url'] }}">
                        <span class="ss-attention-count">{{ $item['count'] }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold">{{ $item['label'] }}</span>
                            <span class="block truncate text-xs text-muted">{{ $item['sub'] }}</span>
                        </span>
                        <svg class="ico"><use href="#{{ $item['icon'] }}"/></svg>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    @if ($servers->isEmpty())
        {{-- First steps when there is nothing to monitor yet. --}}
        <section class="ss-panel mb-6" aria-labelledby="getting-started-title">
            <h2 id="getting-started-title" class="ss-panel-title">{{ __('dashboard.getting_started.title') }}</h2>
            <p class="ss-panel-sub">{{ __('dashboard.getting_started.text') }}</p>
            <ol class="m-0 mt-4 grid list-none gap-3 p-0 md:grid-cols-3">
                @foreach ([['servers.add', 'i-server', 'server'], ['domains.create', 'i-globe', 'domain'], ['profile.edit', 'i-bell', 'notifications']] as $i => [$route, $icon, $key])
                    <li>
                        <a class="ss-stat h-full" href="{{ route($route) }}">
                            <span class="ss-stat-icon"><svg class="ico"><use href="#{{ $icon }}"/></svg></span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ $i + 1 }}. {{ __('dashboard.getting_started.'.$key) }}</span>
                                <span class="mt-0.5 block text-xs text-muted">{{ __('dashboard.getting_started.'.$key.'_text') }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @include('dashboard._overview')

    {{-- Server performance --}}
    <section class="mt-8" aria-labelledby="performance-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="performance-title" class="ss-section-title">{{ $selectedServer ? __('dashboard.performance.title_for', ['name' => $selectedServer->name]) : __('dashboard.performance.title') }}</h2>
                <p class="ss-panel-sub">{{ __('dashboard.performance.text') }}</p>
            </div>
            <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-2" data-auto-submit>
                <label for="range" class="sr-only">{{ __('dashboard.period') }}</label>
                <select id="range" name="range" class="ss-input !w-auto !py-1.5 !text-sm">
                    @foreach ([1, 6, 24, 168] as $value)
                        <option value="{{ $value }}" @selected($currentRange === $value)>{{ __('dashboard.ranges.'.$value) }}</option>
                    @endforeach
                </select>
                <noscript><button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ __('dashboard.apply') }}</button></noscript>
            </form>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('dashboard.performance.stats_label') }}" role="group">
            @foreach ([
                ['cpu', 'i-cpu', $latestSample?->cpu_percent !== null ? Format::percent($latestSample->cpu_percent, 1) : '—', __('dashboard.performance.avg', ['value' => $cpuAverage !== null ? Format::percent($cpuAverage, 1) : __('dashboard.performance.no_data')]), $cpuTrend, $spark($chartData['cpu']->all())],
                ['ram', 'i-ram', $memoryPercent !== null ? Format::percent($memoryPercent, 1) : '—', __('dashboard.performance.avg', ['value' => $memoryAverage !== null ? Format::percent($memoryAverage, 1) : __('dashboard.performance.no_data')]), $ramTrend, $spark($chartData['memory']->all())],
                ['load', 'i-bars', $latestSample?->load_1 !== null ? Format::number($latestSample->load_1, 2) : '—', __('dashboard.performance.load_caption'), $loadTrend, $spark($chartData['load1']->all(), 'is-muted')],
            ] as [$key, $icon, $value, $caption, $t, $sparkline])
                <article class="ss-stat flex-col !gap-2">
                    <div class="flex w-full items-start gap-3">
                        <span class="ss-stat-icon"><svg class="ico"><use href="#{{ $icon }}"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <div class="ss-stat-label">{{ __('dashboard.performance.'.$key) }}</div>
                            <div class="ss-stat-value">{{ $value }}</div>
                            <div class="ss-stat-caption">{{ $caption }}</div>
                        </div>
                        @if ($t)
                            <span class="ss-chip" title="{{ __($t['up'] ? 'dashboard.performance.trend_up' : 'dashboard.performance.trend_down', ['percent' => $t['pct'].'%']) }}">{{ $t['up'] ? '↗' : '↘' }} {{ $t['pct'] }}%</span>
                        @endif
                    </div>
                    {!! $sparkline !!}
                </article>
            @endforeach
            <article class="ss-stat">
                <span class="ss-stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="min-w-0">
                    <div class="ss-stat-label">{{ __('dashboard.performance.uptime') }}</div>
                    <div class="ss-stat-value">
                        @if ($latestSample?->uptime_seconds !== null)
                            {{ intdiv((int) $latestSample->uptime_seconds, 86400) }}{{ __('dashboard.performance.unit_day') }} {{ intdiv((int) $latestSample->uptime_seconds % 86400, 3600) }}{{ __('dashboard.performance.unit_hour') }}
                        @else
                            —
                        @endif
                    </div>
                    <div class="ss-stat-caption">{{ $bootedAt ? __('dashboard.performance.since', ['time' => Time::dateTime($bootedAt)]) : ($selectedServer?->name ?? __('dashboard.performance.no_server')) }}</div>
                </div>
            </article>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3" id="metrics" aria-label="{{ __('dashboard.performance.charts_label') }}" role="group">
            @foreach ([
                ['cpu', 'i-cpu', 'cpu-chart', $chartData['cpu'], $cpuAverage, 'cpu_over_time', 'no_cpu'],
                ['ram', 'i-ram', 'memory-chart', $chartData['memory'], $memoryAverage, 'ram_over_time', 'no_ram'],
                ['load', 'i-bars', 'load-chart', $chartData['load1'], null, 'load_over_time', 'no_load'],
            ] as [$key, $icon, $canvasId, $series, $average, $label, $empty])
                <article class="ss-panel">
                    <div class="ss-panel-head !mb-3">
                        <h3 class="ss-panel-title flex items-center gap-2"><svg class="ico text-muted"><use href="#{{ $icon }}"/></svg>{{ __('dashboard.performance.'.$key) }}</h3>
                        @if ($key !== 'load')
                            <span class="ss-chip">{{ __('dashboard.performance.avg_long', ['value' => $average !== null ? Format::percent($average, 1) : '—']) }}</span>
                        @else
                            <span class="flex flex-wrap gap-3 text-xs text-muted">
                                <span class="inline-flex items-center gap-1.5"><i class="ss-legend-dot"></i>{{ __('dashboard.performance.load_1') }}</span>
                                <span class="inline-flex items-center gap-1.5"><i class="ss-legend-dot is-2"></i>{{ __('dashboard.performance.load_5') }}</span>
                                <span class="inline-flex items-center gap-1.5"><i class="ss-legend-dot is-3"></i>{{ __('dashboard.performance.load_15') }}</span>
                            </span>
                        @endif
                    </div>
                    @if (count($series) > 0)
                        <div class="ss-chart"><canvas id="{{ $canvasId }}" role="img" aria-label="{{ __('dashboard.performance.'.$label) }}"></canvas></div>
                    @else
                        <x-empty-state icon="i-metrics" :title="__('dashboard.performance.'.$empty)" />
                    @endif
                </article>
            @endforeach
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <article class="ss-panel">
                <h3 class="ss-panel-title mb-3 flex items-center gap-2"><svg class="ico text-muted"><use href="#i-server"/></svg>{{ __('dashboard.system.title') }}</h3>
                <dl class="ss-dl">
                    <dt><svg class="ico"><use href="#i-server"/></svg> {{ __('dashboard.system.name') }}</dt><dd>{{ $selectedServer?->name ?? '—' }}</dd>
                    <dt><svg class="ico"><use href="#i-globe"/></svg> {{ __('dashboard.system.hostname') }}</dt><dd>{{ $selectedServer?->hostname ?? '—' }}</dd>
                    <dt><svg class="ico"><use href="#i-swap"/></svg> {{ __('dashboard.system.ip') }}</dt><dd class="font-mono text-xs">{{ $selectedServer?->ip_address ?? '—' }}</dd>
                    <dt><svg class="ico"><use href="#i-os"/></svg> {{ __('dashboard.system.os') }}</dt><dd>{{ $selectedServer?->operating_system ?? __('dashboard.system.not_available') }}</dd>
                    <dt><svg class="ico"><use href="#i-tag"/></svg> {{ __('dashboard.system.agent') }}</dt><dd>{{ $selectedServer?->agent_version ?? '—' }}</dd>
                    <dt><svg class="ico"><use href="#i-clock"/></svg> {{ __('dashboard.system.last_contact') }}</dt><dd>{{ $selectedServer?->last_seen_at?->diffForHumans() ?? __('dashboard.system.never') }}</dd>
                    <dt><svg class="ico"><use href="#i-ram"/></svg> {{ __('dashboard.system.ram_total') }}</dt><dd>{{ $gib($memoryTotalBytes, 2) }}</dd>
                </dl>
            </article>

            <article class="ss-panel">
                <h3 class="ss-panel-title mb-3 flex items-center gap-2"><svg class="ico text-muted"><use href="#i-disk"/></svg>{{ __('dashboard.disk.title') }}</h3>
                @if ($primaryDisk)
                    @php
                        $diskPct = min(100, max(0, (float) ($primaryDisk['used_percent'] ?? 0)));
                        $circumference = 2 * M_PI * 42;
                    @endphp
                    <div class="flex items-center gap-5">
                        <svg class="ss-donut {{ $diskPct >= 90 ? 'is-bad' : ($diskPct >= 75 ? 'is-warn' : '') }} h-28 w-28 shrink-0" viewBox="0 0 100 100" role="img" aria-label="{{ $primaryDisk['mountpoint'] }}: {{ Format::percent($diskPct, 0) }} {{ __('dashboard.disk.used_short') }}">
                            <circle class="track" cx="50" cy="50" r="42" fill="none" stroke-width="10"/>
                            <circle class="fill" cx="50" cy="50" r="42" fill="none" stroke-width="10" stroke-linecap="round" transform="rotate(-90 50 50)"
                                    stroke-dasharray="{{ round($circumference * $diskPct / 100, 2) }} {{ round($circumference, 2) }}"/>
                            <text x="50" y="50" text-anchor="middle" font-size="17" font-weight="650" fill="currentColor">{{ $primaryDisk['used_percent'] !== null ? Format::percent($primaryDisk['used_percent'], 0) : '—' }}</text>
                            <text x="50" y="64" text-anchor="middle" font-size="9" fill="var(--ss-muted)">{{ __('dashboard.disk.used_short') }}</text>
                        </svg>
                        <dl class="ss-dl min-w-0 flex-1 !grid-cols-[auto_1fr]">
                            <dt class="col-span-2 !border-0 !pb-1 font-mono text-xs">{{ $primaryDisk['mountpoint'] }}</dt>
                            <dt>{{ __('dashboard.disk.used') }}</dt><dd class="text-right tabular-nums">{{ $gib($primaryDisk['used_bytes']) }}</dd>
                            <dt>{{ __('dashboard.disk.available') }}</dt><dd class="text-right tabular-nums">{{ $gib($primaryDisk['available_bytes']) }}</dd>
                            <dt>{{ __('dashboard.disk.total') }}</dt><dd class="text-right tabular-nums">{{ $gib($primaryDisk['total_bytes']) }}</dd>
                        </dl>
                    </div>
                    @foreach ($extraDisks as $disk)
                        <div class="mt-4">
                            <div class="mb-1.5 flex justify-between text-sm"><span class="font-mono text-xs">{{ $disk['mountpoint'] }}</span><span class="tabular-nums text-muted">{{ $disk['used_percent'] !== null ? Format::percent($disk['used_percent'], 1) : '—' }}</span></div>
                            {!! $bar($disk['used_percent'] !== null ? (float) $disk['used_percent'] : null) !!}
                        </div>
                    @endforeach
                @else
                    <x-empty-state icon="i-disk" :title="__('dashboard.disk.none')" />
                @endif
            </article>

            <article class="ss-panel">
                <h3 class="ss-panel-title mb-3 flex items-center gap-2"><svg class="ico text-muted"><use href="#i-swap"/></svg>{{ __('dashboard.network.title') }}</h3>
                @foreach ([['download', 'i-down', $downloadRate, $networkSeries['rx'] ?? [], ''], ['upload', 'i-up', $uploadRate, $networkSeries['tx'] ?? [], 'is-muted']] as [$key, $icon, $rate, $series, $variant])
                    <div class="flex items-center gap-4 border-b border-line py-3 first:pt-0">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5 text-xs text-muted"><svg class="ico"><use href="#{{ $icon }}"/></svg> {{ __('dashboard.network.'.$key) }}</div>
                            <div class="mt-0.5 text-lg font-semibold tabular-nums">{{ $rate !== null ? Format::number($rate / 1048576, 2).' MB/s' : '—' }}</div>
                        </div>
                        <div class="w-28">{!! $spark($series, $variant) !!}</div>
                    </div>
                @endforeach
                <div class="pt-3">
                    <div class="flex items-center gap-1.5 text-xs text-muted"><svg class="ico"><use href="#i-ram"/></svg> {{ __('dashboard.network.available_ram') }}</div>
                    <div class="mt-0.5 text-lg font-semibold tabular-nums">{{ $gib($memoryAvailableBytes, 2) }}</div>
                </div>
            </article>
        </div>
    </section>

    {{-- Servers --}}
    <section class="ss-panel mt-8" id="servers" aria-labelledby="servers-title">
        <div class="ss-panel-head">
            <div>
                <h2 id="servers-title" class="ss-panel-title">{{ __('dashboard.servers.title') }}</h2>
                <p class="ss-panel-sub">{{ __('dashboard.servers.text') }}</p>
            </div>
            <span class="text-xs text-muted">{{ __('dashboard.servers.total', ['count' => $serverCount]) }}</span>
        </div>

        @if ($servers->isEmpty())
            <x-empty-state icon="i-server" :title="__('dashboard.servers.empty_title')">
                {{ __('dashboard.servers.empty_text') }}
                @can('admin')
                    <x-slot:actions>
                        <a class="ss-btn ss-btn-primary" href="{{ route('servers.add') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('dashboard.servers.add_first') }}</a>
                    </x-slot:actions>
                @endcan
            </x-empty-state>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('dashboard.servers.col_server') }}</th>
                            <th scope="col">{{ __('dashboard.servers.col_status') }}</th>
                            <th scope="col">{{ __('dashboard.servers.col_contact') }}</th>
                            <th scope="col">{{ __('dashboard.servers.col_agent') }}</th>
                            <th scope="col" class="w-40">{{ __('dashboard.servers.col_cpu') }}</th>
                            <th scope="col" class="w-40">{{ __('dashboard.servers.col_ram') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            @php
                                $sample = $server->latestMetricSample;
                                $serverCpu = $sample?->cpu_percent;
                                $statusTone = ['online' => 'ss-status-ok', 'offline' => 'ss-status-bad', 'maintenance' => 'ss-status-info'][$server->display_status] ?? 'ss-status-muted';
                                $statusKey = in_array($server->display_status, ['online', 'offline', 'maintenance'], true) ? $server->display_status : 'pending';
                            @endphp
                            <tr>
                                <td class="is-primary">
                                    <a class="font-semibold text-fg no-underline hover:text-accent" href="{{ route('servers.show', $server) }}">{{ $server->name }}</a>
                                    <span class="block text-xs text-muted">{{ $server->hostname }}@if ($server->operating_system) · {{ $server->operating_system }}@endif</span>
                                </td>
                                <td data-label="{{ __('dashboard.servers.col_status') }}"><span class="ss-status {{ $statusTone }}">{{ __('dashboard.servers.status.'.$statusKey) }}</span></td>
                                <td data-label="{{ __('dashboard.servers.col_contact') }}" class="whitespace-nowrap text-muted" title="{{ $sample ? __('dashboard.servers.measured', ['time' => $sample->collected_at?->diffForHumans() ?? __('dashboard.servers.unknown_time')]) : __('dashboard.servers.no_samples') }}">{{ $server->last_seen_at?->diffForHumans() ?? __('dashboard.system.never') }}</td>
                                <td data-label="{{ __('dashboard.servers.col_agent') }}" class="text-muted">{{ $server->agent_version ?? __('dashboard.servers.unknown') }}</td>
                                <td data-label="{{ __('dashboard.servers.col_cpu') }}">
                                    @if ($sample)
                                        <div class="flex items-center gap-2"><span class="w-12 text-right tabular-nums">{{ $serverCpu !== null ? Format::percent($serverCpu, 1) : __('dashboard.servers.no_data') }}</span><span class="min-w-16 flex-1">{!! $bar($serverCpu !== null ? (float) $serverCpu : null) !!}</span></div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('dashboard.servers.col_ram') }}">
                                    @if ($sample)
                                        <div class="flex items-center gap-2"><span class="w-12 text-right tabular-nums">{{ $server->memory_percent !== null ? Format::percent($server->memory_percent, 1) : __('dashboard.servers.no_data') }}</span><span class="min-w-16 flex-1">{!! $bar($server->memory_percent) !!}</span></div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Recent measurements (collapsed: details for whoever needs them) --}}
    <details class="ss-panel ss-details mt-4">
        <summary>
            <span>
                <span class="ss-panel-title block">{{ __('dashboard.samples.title') }}</span>
                <span class="ss-panel-sub block">{{ $selectedServer ? __('dashboard.samples.text_for', ['name' => $selectedServer->name]) : __('dashboard.samples.text_none') }}</span>
            </span>
            <svg class="ico ss-chevron h-4 w-4"><use href="#i-chevron"/></svg>
        </summary>

        <div class="mt-4">
            @if ($recentSamples->isNotEmpty())
                <div class="ss-table-wrap" tabindex="0" role="region" aria-label="{{ __('dashboard.samples.table_label') }}">
                    <table class="ss-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('dashboard.samples.time') }}</th>
                                <th scope="col" class="num">{{ __('dashboard.samples.cpu') }}</th>
                                <th scope="col" class="num">{{ __('dashboard.samples.ram') }}</th>
                                <th scope="col" class="num">{{ __('dashboard.samples.load_1') }}</th>
                                <th scope="col" class="num">{{ __('dashboard.samples.load_5') }}</th>
                                <th scope="col" class="num">{{ __('dashboard.samples.load_15') }}</th>
                                <th scope="col" class="num">{{ __('dashboard.samples.disk') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentSamples as $row)
                                @php
                                    $rowTotal = (float) ($row->memory_total_bytes ?? 0);
                                    $rowUsed = (float) ($row->memory_used_bytes ?? 0);
                                    $rowMemoryPercent = $rowTotal > 0 ? ($rowUsed / $rowTotal) * 100 : null;

                                    $firstDisk = collect($row->disks ?? [])->first();
                                    $firstDiskTotal = (float) ($firstDisk['total_bytes'] ?? $firstDisk['total'] ?? 0);
                                    $firstDiskUsed = (float) ($firstDisk['used_bytes'] ?? $firstDisk['used'] ?? 0);
                                    $rowDiskPercent = $firstDiskTotal > 0 ? ($firstDiskUsed / $firstDiskTotal) * 100 : null;
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap">{{ $row->collected_at ? Time::dateTime($row->collected_at) : '—' }}</td>
                                    <td class="num">{{ $row->cpu_percent !== null ? Format::percent($row->cpu_percent, 1) : '—' }}</td>
                                    <td class="num">{{ $rowMemoryPercent !== null ? Format::percent($rowMemoryPercent, 1) : '—' }}</td>
                                    <td class="num">{{ Format::number($row->load_1, 2) }}</td>
                                    <td class="num">{{ Format::number($row->load_5, 2) }}</td>
                                    <td class="num">{{ Format::number($row->load_15, 2) }}</td>
                                    <td class="num">{{ $rowDiskPercent !== null ? Format::percent($rowDiskPercent, 1) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-empty-state icon="i-metrics" :title="__('dashboard.samples.none')" />
            @endif
        </div>
    </details>

    <script type="application/json" id="chart-data">@json($chartData)</script>
</x-layouts.app>
