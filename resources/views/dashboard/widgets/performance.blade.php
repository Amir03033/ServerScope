@php
    use App\Support\Format;
    use App\Support\Time;
@endphp
{{-- Server performance --}}
<section aria-labelledby="performance-title">
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
