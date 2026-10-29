@php
    /* ---------- helpers: sparkline + trend ---------- */
    $spark = function (array $values, string $color, string $id): string {
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
            $x = round($i / ($n - 1) * 100, 2);
            $y = round(26 - (($v - $min) / $range) * 22, 2);
            $line[] = $x . ',' . $y;
        }
        $d = 'M' . implode(' L', $line);

        return '<svg class="spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">'
            . '<defs><linearGradient id="g-' . $id . '" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="' . $color . '" stop-opacity=".38"/>'
            . '<stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
            . '<path d="' . $d . ' L100,30 L0,30 Z" fill="url(#g-' . $id . ')"/>'
            . '<path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="1.6" vector-effect="non-scaling-stroke"/>'
            . '</svg>';
    };

    $trend = function ($latest, $avg): ?array {
        if ($latest === null || $avg === null || (float) $avg <= 0) {
            return null;
        }
        $pct = (int) round((((float) $latest - (float) $avg) / (float) $avg) * 100);

        return ['pct' => abs($pct), 'up' => $pct >= 0];
    };

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
    $rangeOptions = [1 => 'Laatste uur', 6 => 'Laatste 6 uur', 24 => 'Laatste 24 uur', 168 => 'Laatste 7 dagen'];
@endphp

<x-layouts.app :title="'Dashboard'">
    <x-slot:head>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </x-slot:head>

    <x-slot:styles>
        .live-status {
            display: inline-flex; align-items: center;
            height: 40px; padding: 0 14px;
            border: 1px solid #14583f; border-radius: 10px;
            background: #0a2a24; color: var(--green); font-size: 12px; white-space: nowrap;
        }
        .last-update { font-size: 10px; line-height: 1.35; color: var(--muted); }
        .last-update strong { display: block; font-weight: 500; color: #c7d5e8; font-size: 11px; }
        .button:disabled { cursor: wait; opacity: .6; }
        .button-icon { width: 40px; padding: 0; font-size: 17px; }
        .select-wrap { position: relative; display: inline-flex; align-items: center; }
        .select-wrap > .ico { position: absolute; left: 12px; font-size: 16px; color: #b6c6dd; pointer-events: none; }
        .select-wrap select {
            appearance: none; -webkit-appearance: none;
            height: 40px; padding: 0 34px 0 38px;
            border: 1px solid #2a3c58; border-radius: 10px;
            background: #111d33; color: var(--text); outline: none;
        }
        .select-wrap select:focus-visible { border-color: #0891b2; box-shadow: 0 0 0 3px #0891b230; }
        .select-wrap .chev { left: auto; right: 12px; font-size: 14px; }
        .register-panel[hidden], .token-area[hidden] { display: none; }
        .panel {
            min-width: 0; padding: 18px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--panel);
        }
        .register-panel { margin-bottom: 22px; }
        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 15px; margin: 0 0 14px; }
        .section-heading h2 { margin: 0; font-size: 16px; }
        .section-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
        .stat {
            position: relative; display: flex; flex-direction: column;
            min-width: 0; min-height: 138px; overflow: hidden;
            padding: 16px 16px 0;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: linear-gradient(160deg, color-mix(in srgb, var(--tone) 9%, #0e1829), #0b1424 70%);
        }
        .stat-body { display: flex; align-items: flex-start; gap: 12px; position: relative; z-index: 1; }
        .stat-icon {
            display: grid; place-items: center;
            width: 44px; height: 44px; flex: 0 0 44px;
            border: 1px solid color-mix(in srgb, var(--tone) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--tone) 14%, #0b1424);
            color: var(--tone); font-size: 22px;
        }
        .stat-text { min-width: 0; flex: 1; }
        .stat-label { color: #c3d1e4; font-size: 12px; }
        .stat-value { margin-top: 1px; font-size: clamp(22px, 1.9vw, 28px); font-weight: 700; letter-spacing: -.6px; line-height: 1.15; }
        .stat-caption { margin-top: 2px; color: var(--muted); font-size: 11px; }
        .trend {
            align-self: center; padding: 3px 8px; border-radius: 7px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .trend.good { background: #0d3322; color: var(--green); }
        .trend.bad { background: #3a1420; color: var(--red); }
        .spark { display: block; width: calc(100% + 32px); height: 42px; margin: auto -16px 0; }
        .chart-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }
        .chart-panel { min-width: 0; padding: 16px 16px 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--panel); }
        .chart-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .chart-title { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .chart-title .badge-icon { width: 30px; height: 30px; flex-basis: 30px; font-size: 16px; border-radius: 9px; }
        .chart-title h3 { margin: 0; font-size: 14px; font-weight: 600; }
        .pill { padding: 4px 10px; border: 1px solid #2a3c58; border-radius: 8px; background: #121f36; color: #b9c8dc; font-size: 11px; white-space: nowrap; }
        .badge-icon {
            display: grid; place-items: center;
            width: 34px; height: 34px; flex: 0 0 34px;
            border: 1px solid color-mix(in srgb, var(--tone, var(--cyan)) 40%, transparent);
            border-radius: 10px;
            background: color-mix(in srgb, var(--tone, var(--cyan)) 14%, #0b1424);
            color: var(--tone, var(--cyan)); font-size: 17px;
        }
        .legend { display: flex; gap: 14px; margin: -2px 0 8px; font-size: 11px; color: #b9c8dc; }
        .legend i { display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; }
        .chart-wrap { position: relative; height: 215px; }
        .chart-empty {
            display: grid; place-items: center; min-height: 215px; padding: 20px;
            border: 1px dashed #2c3c53; border-radius: 10px;
            color: var(--muted); text-align: center; font-size: 12px;
        }
        .details-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }
        .detail-panel { min-width: 0; }
        .panel-title { display: flex; align-items: center; gap: 10px; margin: 0 0 14px; font-size: 14px; font-weight: 600; }
        .info-list { margin: 0; }
        .info-row {
            display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr); gap: 10px; align-items: center;
            padding: 9px 0; border-bottom: 1px solid #1a2840; font-size: 12px;
        }
        .info-row:last-child { border-bottom: 0; }
        .info-row dt { display: flex; align-items: center; gap: 9px; color: var(--muted); }
        .info-row dt .ico { font-size: 15px; color: #9db1ca; }
        .info-row dd { min-width: 0; margin: 0; overflow-wrap: anywhere; color: #e3edf9; }
        .donut-row { display: flex; align-items: center; gap: 22px; }
        .donut {
            position: relative; flex: 0 0 132px; width: 132px; height: 132px; border-radius: 50%;
            background: conic-gradient(var(--cyan) calc(var(--p) * 1%), #25364b 0);
        }
        .donut::after { content: ""; position: absolute; inset: 14px; border-radius: 50%; background: var(--panel); }
        .donut-center { position: absolute; inset: 0; z-index: 1; display: grid; place-content: center; text-align: center; }
        .donut-center strong { font-size: 24px; letter-spacing: -.5px; line-height: 1.1; }
        .donut-center span { font-size: 10px; color: var(--muted); }
        .donut-legend { flex: 1; min-width: 0; }
        .donut-legend > div { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 5px 0; font-size: 12px; }
        .donut-legend i { display: inline-block; width: 8px; height: 8px; margin-right: 8px; border-radius: 50%; }
        .donut-mount { margin: 0 0 10px; font-size: 11px; color: var(--muted); overflow-wrap: anywhere; }
        .disk-item { margin-top: 14px; }
        .disk-top { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 6px; font-size: 11px; }
        .disk-track { height: 6px; overflow: hidden; border-radius: 99px; background: #25364b; }
        .disk-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0891b2, var(--cyan)); }
        .network-item { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 12px 0; border-bottom: 1px solid #1a2840; }
        .network-item:last-child { border-bottom: 0; }
        .network-label { display: flex; align-items: center; gap: 6px; color: #c3d1e4; font-size: 12px; }
        .network-label .ico { font-size: 16px; }
        .network-value { margin-top: 2px; font-size: 22px; font-weight: 700; letter-spacing: -.4px; }
        .network-spark { flex: 0 0 52%; min-width: 0; }
        .network-spark .spark { width: 100%; margin: 0; height: 44px; }
        .server-list { display: grid; gap: 11px; }
        .server-card {
            display: block; padding: 16px; border: 1px solid var(--border); border-radius: 12px;
            background: #0a1322; color: inherit; text-decoration: none; transition: border-color .15s, background .15s;
        }
        .server-card:hover { border-color: #23618a; background: #0f1d31; }
        .server-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 15px; }
        .server-name { margin: 0; font-size: 14px; }
        .server-host { margin-top: 3px; color: var(--muted); font-size: 11px; overflow-wrap: anywhere; }
        .badge {
            display: inline-flex; align-items: center; gap: 7px; padding: 5px 9px;
            border: 1px solid #2c4058; border-radius: 99px; background: #152237;
            font-size: 10px; font-weight: 700; white-space: nowrap;
        }
        .badge::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .badge.online { color: var(--green); }
        .badge.offline { color: var(--red); }
        .badge.pending { color: var(--yellow); }
        .badge.maintenance { color: var(--purple); }
        .server-meta { display: flex; flex-wrap: wrap; gap: 8px 17px; margin-top: 12px; color: var(--muted); font-size: 10px; }
        .metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 16px; }
        .metric-label { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 7px; font-size: 11px; }
        .track { height: 6px; overflow: hidden; border-radius: 99px; background: #26364b; }
        .fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0891b2, var(--cyan)); }
        .empty { padding: 40px 18px; border: 1px dashed #304158; border-radius: 12px; text-align: center; }
        .empty-icon {
            display: grid; place-items: center; width: 48px; height: 48px; margin: 0 auto 12px;
            border: 1px solid var(--border); border-radius: 13px; background: #17263a; color: var(--cyan); font-size: 22px;
        }
        .empty h3 { margin: 0; font-size: 15px; }
        .empty p { max-width: 380px; margin: 8px auto 16px; color: var(--muted); font-size: 12px; }
        .table-wrap { width: 100%; overflow-x: auto; }
        .measurements { width: 100%; border-collapse: collapse; white-space: nowrap; font-size: 12px; }
        .measurements th { padding: 10px 14px; border-bottom: 1px solid #2a3a54; color: var(--muted); font-weight: 500; text-align: right; }
        .measurements td { padding: 11px 14px; border-bottom: 1px solid #172338; text-align: right; font-variant-numeric: tabular-nums; }
        .measurements th:first-child, .measurements td:first-child { text-align: left; }
        .measurements tbody tr:last-child td { border-bottom: 0; }
        .registration-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; margin: 18px 0; }
        .field { display: flex; flex-direction: column; gap: 7px; color: #cbd5e1; font-size: 11px; }
        .field input, .token-input {
            width: 100%; min-width: 0; padding: 11px 12px;
            border: 1px solid #30435b; border-radius: 8px; outline: none; background: #080f1c; color: var(--text);
        }
        .field input:focus, .token-input:focus { border-color: #0891b2; box-shadow: 0 0 0 3px #0891b222; }
        .full-width { grid-column: 1 / -1; }
        .message { margin-top: 12px; color: #fca5a5; white-space: pre-line; }
        .token-area { margin-top: 17px; padding: 16px; border: 1px solid #854d0e; border-radius: 10px; background: #29200d; }
        .token-row { display: flex; gap: 8px; margin-top: 12px; }
        .token-row .token-input { flex: 1; }
        @media (max-width: 1280px) {
    .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .chart-grid, .details-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .chart-grid .chart-panel:last-child { grid-column: 1 / -1; }
}
        @media (max-width: 850px) {
    .chart-grid, .details-grid { grid-template-columns: 1fr; }
}
        @media (max-width: 560px) {
    .stats { grid-template-columns: 1fr; }
    .registration-grid, .metrics { grid-template-columns: 1fr; }
    .full-width { grid-column: auto; }
    .token-row, .footer { flex-direction: column; }
    .donut-row { flex-direction: column; align-items: stretch; }
    .donut { align-self: center; }
}
    </x-slot:styles>

    <x-slot:breadcrumb>
        <span>›</span> Dashboard
    </x-slot:breadcrumb>

    <x-slot:pageTitle>Dashboard</x-slot:pageTitle>
    <x-slot:subtitle>Een realtime overzicht van je servers, domeinen en incidenten.</x-slot:subtitle>

    <x-slot:actions>

        <span class="live-status">
            <span class="connection-dot"></span>
            {{ $onlineCount > 0 ? 'Server online' : ($serverCount > 0 ? 'Geen server online' : 'Wachten op server') }}
        </span>

        <span class="last-update">
            Laatste update
            <strong>Vandaag om {{ now()->format('H:i') }}</strong>
        </span>

        <button class="button button-icon" type="button" onclick="window.location.reload()" aria-label="Pagina vernieuwen">
            <svg class="ico"><use href="#i-refresh"/></svg>
        </button>

        <form method="GET" action="{{ route('dashboard') }}" class="select-wrap">
            <svg class="ico"><use href="#i-calendar"/></svg>
            <select name="range" onchange="this.form.submit()" aria-label="Periode">
                @foreach($rangeOptions as $value => $label)
                    <option value="{{ $value }}" @selected($currentRange === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <svg class="ico chev"><use href="#i-chevron"/></svg>
        </form>

        @can('admin')
            <a class="button button-primary" href="{{ route('servers.add') }}">＋ Server toevoegen</a>
        @endcan
    </x-slot:actions>


    @include('dashboard._overview')

    <div class="section-heading" style="margin:0 0 14px">
        <div>
            <h2 style="font-size:16px;font-weight:600">Serverprestaties{{ $selectedServer ? ': '.$selectedServer->name : '' }}</h2>
            <p>Actuele metingen en historie van de meest recent actieve server.</p>
        </div>
    </div>

    {{-- KPI cards --}}
    <section class="stats" aria-label="Belangrijkste statistieken">
        <article class="stat tone-cyan">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-cpu"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">CPU-gebruik</div>
                    <div class="stat-value">
                        {{ $latestSample?->cpu_percent !== null ? number_format((float) $latestSample->cpu_percent, 1, '.', '') . '%' : '—' }}
                    </div>
                    <div class="stat-caption">Gem. {{ $cpuAverage !== null ? number_format($cpuAverage, 1, '.', '') . '%' : 'geen data' }}</div>
                </div>
                @if($cpuTrend)
                    <span class="trend {{ $cpuTrend['up'] ? 'bad' : 'good' }}">{{ $cpuTrend['up'] ? '↗' : '↘' }} {{ $cpuTrend['pct'] }}%</span>
                @endif
            </div>
            {!! $spark($chartData['cpu']->all(), '#38bdf8', 'cpu') !!}
        </article>

        <article class="stat tone-purple">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-ram"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">RAM-gebruik</div>
                    <div class="stat-value">
                        {{ $memoryPercent !== null ? number_format($memoryPercent, 1, '.', '') . '%' : '—' }}
                    </div>
                    <div class="stat-caption">Gem. {{ $memoryAverage !== null ? number_format($memoryAverage, 1, '.', '') . '%' : 'geen data' }}</div>
                </div>
                @if($ramTrend)
                    <span class="trend {{ $ramTrend['up'] ? 'bad' : 'good' }}">{{ $ramTrend['up'] ? '↗' : '↘' }} {{ $ramTrend['pct'] }}%</span>
                @endif
            </div>
            {!! $spark($chartData['memory']->all(), '#a78bfa', 'ram') !!}
        </article>

        <article class="stat tone-green">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-bars"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">Load average</div>
                    <div class="stat-value">
                        {{ $latestSample?->load_1 !== null ? number_format((float) $latestSample->load_1, 2, '.', '') : '—' }}
                    </div>
                    <div class="stat-caption">1 minuut gemiddeld</div>
                </div>
                @if($loadTrend)
                    <span class="trend {{ $loadTrend['up'] ? 'bad' : 'good' }}">{{ $loadTrend['up'] ? '↗' : '↘' }} {{ $loadTrend['pct'] }}%</span>
                @endif
            </div>
            {!! $spark($chartData['load1']->all(), '#4ade80', 'load') !!}
        </article>

        <article class="stat tone-yellow">
            <div class="stat-body">
                <span class="stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                <div class="stat-text">
                    <div class="stat-label">Uptime</div>
                    <div class="stat-value">
                        @if($latestSample?->uptime_seconds !== null)
                            @php
                                $uptimeDays = intdiv((int) $latestSample->uptime_seconds, 86400);
                                $uptimeHours = intdiv((int) $latestSample->uptime_seconds % 86400, 3600);
                            @endphp
                            {{ $uptimeDays }}d {{ $uptimeHours }}u
                        @else
                            —
                        @endif
                    </div>
                    <div class="stat-caption">
                        {{ $bootedAt ? 'Sinds ' . $bootedAt->format('d-m-Y H:i') : ($selectedServer?->name ?? 'Geen server geselecteerd') }}
                    </div>
                </div>
            </div>
        </article>
    </section>

    {{-- Charts --}}
    <section class="chart-grid" id="metrics" aria-label="Servergrafieken">
        <article class="chart-panel">
            <div class="chart-heading">
                <div class="chart-title">
                    <span class="badge-icon tone-cyan"><svg class="ico"><use href="#i-cpu"/></svg></span>
                    <h3>CPU-gebruik</h3>
                </div>
                <span class="pill">Gemiddeld {{ $cpuAverage !== null ? number_format($cpuAverage, 1, '.', '') . '%' : '—' }}</span>
            </div>

            @if(count($chartData['cpu']) > 0)
                <div class="chart-wrap"><canvas id="cpu-chart" aria-label="CPU-gebruik in de tijd"></canvas></div>
            @else
                <div class="chart-empty">Nog geen CPU-metingen beschikbaar in deze periode.</div>
            @endif
        </article>

        <article class="chart-panel">
            <div class="chart-heading">
                <div class="chart-title">
                    <span class="badge-icon tone-purple"><svg class="ico"><use href="#i-ram"/></svg></span>
                    <h3>RAM-gebruik</h3>
                </div>
                <span class="pill">Gemiddeld {{ $memoryAverage !== null ? number_format($memoryAverage, 1, '.', '') . '%' : '—' }}</span>
            </div>

            @if(count($chartData['memory']) > 0)
                <div class="chart-wrap"><canvas id="memory-chart" aria-label="RAM-gebruik in de tijd"></canvas></div>
            @else
                <div class="chart-empty">Nog geen RAM-metingen beschikbaar in deze periode.</div>
            @endif
        </article>

        <article class="chart-panel">
            <div class="chart-heading">
                <div class="chart-title">
                    <span class="badge-icon tone-green"><svg class="ico"><use href="#i-bars"/></svg></span>
                    <h3>Load average</h3>
                </div>
            </div>
            <div class="legend">
                <span><i style="background:#38bdf8"></i>1 minuut</span>
                <span><i style="background:#a78bfa"></i>5 minuten</span>
                <span><i style="background:#4ade80"></i>15 minuten</span>
            </div>

            @if(count($chartData['load1']) > 0)
                <div class="chart-wrap" style="height:190px"><canvas id="load-chart" aria-label="Load average in de tijd"></canvas></div>
            @else
                <div class="chart-empty">Nog geen load-average-metingen beschikbaar in deze periode.</div>
            @endif
        </article>
    </section>

    {{-- System, disk, network --}}
    <section class="details-grid">
        <article class="panel detail-panel">
            <h3 class="panel-title">
                <span class="badge-icon"><svg class="ico"><use href="#i-server"/></svg></span>
                Systeeminformatie
            </h3>

            <dl class="info-list">
                <div class="info-row"><dt><svg class="ico"><use href="#i-server"/></svg> Servernaam</dt><dd>{{ $selectedServer?->name ?? '—' }}</dd></div>
                <div class="info-row"><dt><svg class="ico"><use href="#i-globe"/></svg> Hostname</dt><dd>{{ $selectedServer?->hostname ?? '—' }}</dd></div>
                <div class="info-row"><dt><svg class="ico"><use href="#i-swap"/></svg> IP-adres</dt><dd>{{ $selectedServer?->ip_address ?? '—' }}</dd></div>
                <div class="info-row"><dt><svg class="ico"><use href="#i-os"/></svg> Besturingssysteem</dt><dd>{{ $selectedServer?->operating_system ?? 'Niet beschikbaar' }}</dd></div>
                <div class="info-row"><dt><svg class="ico"><use href="#i-tag"/></svg> Agentversie</dt><dd>{{ $selectedServer?->agent_version ?? '—' }}</dd></div>
                <div class="info-row"><dt><svg class="ico"><use href="#i-clock"/></svg> Laatste contact</dt><dd>{{ $selectedServer?->last_seen_at?->diffForHumans() ?? 'Nog nooit' }}</dd></div>
                <div class="info-row">
                    <dt><svg class="ico"><use href="#i-ram"/></svg> RAM totaal</dt>
                    <dd>{{ $memoryTotalBytes > 0 ? number_format($memoryTotalBytes / 1073741824, 2, ',', '.') . ' GiB' : '—' }}</dd>
                </div>
            </dl>
        </article>

        <article class="panel detail-panel">
            <h3 class="panel-title">
                <span class="badge-icon" style="--tone:var(--purple)"><svg class="ico"><use href="#i-disk"/></svg></span>
                Schijfruimte
            </h3>

            @if($primaryDisk)
                <div class="donut-row">
                    <div class="donut" style="--p: {{ min(100, max(0, $primaryDisk['used_percent'] ?? 0)) }}">
                        <div class="donut-center">
                            <strong>{{ $primaryDisk['used_percent'] !== null ? number_format($primaryDisk['used_percent'], 0, '.', '') . '%' : '—' }}</strong>
                            <span>gebruikt</span>
                        </div>
                    </div>
                    <div class="donut-legend">
                        <p class="donut-mount">{{ $primaryDisk['mountpoint'] }}</p>
                        <div>
                            <span><i style="background:var(--cyan)"></i>Gebruikt</span>
                            <strong>{{ $primaryDisk['used_bytes'] > 0 ? number_format($primaryDisk['used_bytes'] / 1073741824, 1, ',', '.') . ' GiB' : '—' }}</strong>
                        </div>
                        <div>
                            <span><i style="background:var(--purple)"></i>Beschikbaar</span>
                            <strong>{{ $primaryDisk['available_bytes'] > 0 ? number_format($primaryDisk['available_bytes'] / 1073741824, 1, ',', '.') . ' GiB' : '—' }}</strong>
                        </div>
                        <div>
                            <span><i style="background:#64748b"></i>Totaal</span>
                            <strong>{{ $primaryDisk['total_bytes'] > 0 ? number_format($primaryDisk['total_bytes'] / 1073741824, 1, ',', '.') . ' GiB' : '—' }}</strong>
                        </div>
                    </div>
                </div>

                @foreach($extraDisks as $disk)
                    <div class="disk-item">
                        <div class="disk-top">
                            <strong>{{ $disk['mountpoint'] }}</strong>
                            <span class="purple">{{ $disk['used_percent'] !== null ? number_format($disk['used_percent'], 1, '.', '') . '%' : '—' }}</span>
                        </div>
                        <div class="disk-track">
                            <div class="disk-fill" style="width: {{ min(100, max(0, $disk['used_percent'] ?? 0)) }}%"></div>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="chart-empty" style="min-height:145px">Geen schijfmetingen beschikbaar.</div>
            @endif
        </article>

        <article class="panel detail-panel">
            <h3 class="panel-title">
                <span class="badge-icon" style="--tone:var(--green)"><svg class="ico"><use href="#i-swap"/></svg></span>
                Netwerk
            </h3>

            <div class="network-item">
                <div>
                    <div class="network-label cyan"><svg class="ico"><use href="#i-down"/></svg> <span style="color:#c3d1e4">Download</span></div>
                    <div class="network-value">
                        {{ $downloadRate !== null ? number_format($downloadRate / 1048576, 2, ',', '.') . ' MB/s' : '—' }}
                    </div>
                </div>
                <div class="network-spark">{!! $spark($networkSeries['rx'] ?? [], '#38bdf8', 'rx') !!}</div>
            </div>

            <div class="network-item">
                <div>
                    <div class="network-label purple"><svg class="ico"><use href="#i-up"/></svg> <span style="color:#c3d1e4">Upload</span></div>
                    <div class="network-value">
                        {{ $uploadRate !== null ? number_format($uploadRate / 1048576, 2, ',', '.') . ' MB/s' : '—' }}
                    </div>
                </div>
                <div class="network-spark">{!! $spark($networkSeries['tx'] ?? [], '#a78bfa', 'tx') !!}</div>
            </div>

            <div class="network-item">
                <div>
                    <div class="network-label"><svg class="ico"><use href="#i-ram"/></svg> Beschikbare RAM</div>
                    <div class="network-value green">
                        {{ $memoryAvailableBytes > 0 ? number_format($memoryAvailableBytes / 1073741824, 2, ',', '.') . ' GiB' : '—' }}
                    </div>
                </div>
            </div>
        </article>
    </section>

    {{-- Servers --}}
    <section class="panel" id="servers" style="margin-top:14px">
        <div class="section-heading">
            <div>
                <h2>Totaal servers</h2>
                <p>Status en meest recente meetwaarden per server.</p>
            </div>
            <span class="muted small">{{ $serverCount }} totaal</span>
        </div>

        @if($servers->isEmpty())
            <div class="empty">
                <div class="empty-icon"><svg class="ico"><use href="#i-server"/></svg></div>
                <h3>Nog geen servers geregistreerd</h3>
                <p>Registreer je eerste server om de prestaties en gezondheid te monitoren.</p>
                @can('admin')
                    <a class="button button-primary" href="{{ route('servers.add') }}">＋ Eerste server toevoegen</a>
                @endcan
            </div>
        @else
            <div class="server-list">
                @foreach($servers as $server)
                    @php
                        $sample = $server->latestMetricSample;
                        $memoryTotal = (float) ($sample?->memory_total_bytes ?? 0);
                        $memoryUsed = (float) ($sample?->memory_used_bytes ?? 0);
                        $serverMemoryPercent = $memoryTotal > 0 ? min(100, max(0, ($memoryUsed / $memoryTotal) * 100)) : null;
                        $serverCpu = $sample?->cpu_percent;
                        $statusLabels = ['online' => 'Online', 'offline' => 'Offline', 'pending' => 'Pending', 'maintenance' => 'Onderhoud'];
                        $statusLabel = $statusLabels[$server->display_status] ?? 'Pending';
                    @endphp

                    <a href="{{ route('servers.show', $server) }}" class="server-card">
                        <div class="server-heading">
                            <div>
                                <h3 class="server-name">{{ $server->name }}</h3>
                                <div class="server-host">{{ $server->hostname }}</div>
                            </div>
                            <span class="badge {{ $server->display_status }}">{{ $statusLabel }}</span>
                        </div>

                        <div class="server-meta">
                            <span>Laatste contact: {{ $server->last_seen_at?->diffForHumans() ?? 'Nog nooit' }}</span>
                            <span>Agent: {{ $server->agent_version ?? 'Onbekend' }}</span>
                            @if($server->operating_system)
                                <span>{{ $server->operating_system }}</span>
                            @endif
                        </div>

                        @if($sample)
                            <div class="metrics">
                                <div>
                                    <div class="metric-label">
                                        <span>CPU-gebruik</span>
                                        <strong>{{ $serverCpu !== null ? number_format((float) $serverCpu, 1, '.', '') . '%' : 'Geen data' }}</strong>
                                    </div>
                                    <div class="track"><div class="fill" style="width:{{ min(100, max(0, $serverCpu ?? 0)) }}%"></div></div>
                                </div>
                                <div>
                                    <div class="metric-label">
                                        <span>RAM-gebruik</span>
                                        <strong>{{ $serverMemoryPercent !== null ? number_format($serverMemoryPercent, 1, '.', '') . '%' : 'Geen data' }}</strong>
                                    </div>
                                    <div class="track"><div class="fill" style="width:{{ $serverMemoryPercent ?? 0 }}%"></div></div>
                                </div>
                            </div>

                            <p class="muted small" style="margin-bottom:0">
                                Meting ontvangen {{ $sample->collected_at?->diffForHumans() ?? 'op onbekend tijdstip' }}.
                            </p>
                        @else
                            <p class="muted small">Er zijn nog geen meetgegevens voor deze server.</p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Recent measurements --}}
    <section class="panel" style="margin-top:14px">
        <div class="section-heading">
            <div>
                <h2>Recente metingen</h2>
                <p>
                    @if($selectedServer)
                    Laatste meetgegevens van {{ $selectedServer->name }}.
                    @else
                    Er is nog geen server met meetgegevens.
                    @endif
                </p>
            </div>
        </div>

        @if($recentSamples->isNotEmpty())
            <div class="table-wrap">
                <table class="measurements">
                    <thead>
                    <tr>
                        <th>Tijdstip</th>
                        <th>CPU</th>
                        <th>RAM</th>
                        <th>Load (1m)</th>
                        <th>Load (5m)</th>
                        <th>Load (15m)</th>
                        <th>Schijf (%)</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($recentSamples as $row)
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
                            <td>{{ $row->collected_at?->format('d M, H:i') ?? '—' }}</td>
                            <td>{{ $row->cpu_percent !== null ? number_format((float) $row->cpu_percent, 1, '.', '') . '%' : '—' }}</td>
                            <td>{{ $rowMemoryPercent !== null ? number_format($rowMemoryPercent, 1, '.', '') . '%' : '—' }}</td>
                            <td>{{ $row->load_1 !== null ? number_format((float) $row->load_1, 2, '.', '') : '—' }}</td>
                            <td>{{ $row->load_5 !== null ? number_format((float) $row->load_5, 2, '.', '') : '—' }}</td>
                            <td>{{ $row->load_15 !== null ? number_format((float) $row->load_15, 2, '.', '') : '—' }}</td>
                            <td>{{ $rowDiskPercent !== null ? number_format($rowDiskPercent, 1, '.', '') . '%' : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="chart-empty" style="min-height:110px">Nog geen recente meetgegevens beschikbaar.</div>
        @endif
    </section>

    

    <x-slot:scripts>
<script>
    window.serverScopeChartData = {{ Illuminate\Support\Js::from($chartData) }};
</script>


    </x-slot:scripts>
</x-layouts.app>
