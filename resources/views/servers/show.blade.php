<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>ServerScope — {{ $server->name }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        :root {
            color-scheme: dark;
            --bg: #080d18;
            --panel: #101827;
            --panel-hover: #141f31;
            --border: #263246;
            --muted: #94a3b8;
            --text: #e5e7eb;
            --cyan: #67e8f9;
            --green: #4ade80;
            --red: #f87171;
            --yellow: #facc15;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 system-ui, sans-serif;
        }

        a { color: inherit; text-decoration: none; }

        .layout {
            display: grid;
            grid-template-columns: 225px minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            padding: 28px 18px;
            border-right: 1px solid var(--border);
            background: #0b1220;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 42px;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .brand-mark {
            display: grid;
            place-items: center;
            width: 35px;
            height: 35px;
            border: 1px solid #155e75;
            border-radius: 10px;
            background: #083344;
            color: var(--cyan);
            font-size: 19px;
        }

        .eyebrow {
            margin: 24px 10px 10px;
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 12px;
            margin: 4px 0;
            border-radius: 9px;
            color: var(--muted);
        }

        .nav-item.active {
            border: 1px solid #164e63;
            background: #102838;
            color: var(--cyan);
        }

        .sidebar-bottom {
            margin-top: 42px;
            padding: 14px 12px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: var(--panel);
        }

        .sidebar-bottom strong {
            display: block;
            margin-bottom: 4px;
            font-size: 12px;
        }

        .muted { color: var(--muted); }
        .small { font-size: 12px; }

        .main {
            min-width: 0;
            padding: 28px 34px 40px;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .breadcrumb {
            margin-bottom: 9px;
            color: var(--muted);
            font-size: 12px;
        }

        .breadcrumb a:hover { color: var(--cyan); }

        .page-title {
            margin: 0;
            font-size: 27px;
            letter-spacing: -.7px;
        }

        .page-subtitle {
            margin: 5px 0 0;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border: 1px solid #334155;
            border-radius: 9px;
            background: #172235;
            color: var(--text);
            font: inherit;
            cursor: pointer;
            transition: background .15s ease;
        }

        .button:hover { background: #22314a; }

        .account {
            margin: 17px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .server-overview {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-top: 28px;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background:
                radial-gradient(ellipse at top right, #164e6325, transparent 55%),
                var(--panel);
        }

        .server-identity {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }

        .server-icon {
            display: grid;
            place-items: center;
            flex-shrink: 0;
            width: 54px;
            height: 54px;
            border: 1px solid #164e63;
            border-radius: 14px;
            background: #083344;
            color: var(--cyan);
            font-size: 25px;
        }

        .server-host {
            margin-top: 4px;
            color: var(--muted);
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #172235;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge::before {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
            content: "";
        }

        .online { color: var(--green); }
        .offline { color: var(--red); }
        .pending { color: var(--yellow); }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }

        .stat {
            min-width: 0;
            padding: 19px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--panel);
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
        }

        .stat-value {
            margin-top: 11px;
            font-size: 25px;
            font-weight: 750;
            letter-spacing: -.5px;
            overflow-wrap: anywhere;
        }

        .stat-description {
            margin-top: 5px;
            color: var(--muted);
            font-size: 11px;
        }

        .section-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin: 32px 0 15px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: 17px;
        }

        .section-heading p {
            margin: 4px 0 0;
            color: var(--muted);
        }

        .range-form {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 4px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #0b1220;
        }

        .range-option {
            padding: 7px 10px;
            border-radius: 7px;
            color: var(--muted);
            font-size: 11px;
        }

        .range-option.active {
            background: #164e63;
            color: #ecfeff;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .panel {
            min-width: 0;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--panel);
        }

        .chart-panel {
            min-height: 300px;
        }

        .chart-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        .chart-heading h3 {
            margin: 0;
            font-size: 14px;
        }

        .chart-heading p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 11px;
        }

        .chart-wrap {
            position: relative;
            height: 205px;
        }

        .empty-chart {
            display: grid;
            place-items: center;
            min-height: 205px;
            padding: 20px;
            border: 1px dashed #334155;
            border-radius: 9px;
            color: var(--muted);
            text-align: center;
        }

        .empty-chart strong {
            display: block;
            margin-bottom: 6px;
            color: var(--text);
        }

        .disk-list {
            display: grid;
            gap: 18px;
        }

        .disk-item { min-width: 0; }

        .disk-heading {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .disk-heading strong { white-space: nowrap; }

        .track {
            height: 7px;
            overflow: hidden;
            border-radius: 99px;
            background: #263246;
        }

        .fill {
            height: 100%;
            border-radius: inherit;
            background: var(--cyan);
        }

        .disk-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 7px;
            color: var(--muted);
            font-size: 11px;
        }

        .details-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 22px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
            font-size: 12px;
        }

        .detail-row span { color: var(--muted); }

        .detail-row strong {
            text-align: right;
            overflow-wrap: anywhere;
        }

        .empty {
            padding: 34px 20px;
            border: 1px dashed #334155;
            border-radius: 11px;
            color: var(--muted);
            text-align: center;
        }

        .footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 30px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            color: #64748b;
            font-size: 11px;
        }

        @media (max-width: 1150px) {
            .layout { grid-template-columns: 190px minmax(0, 1fr); }
            .main { padding: 24px; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 850px) {
            .chart-grid { grid-template-columns: 1fr; }
            .chart-panel { min-height: 280px; }
            .server-overview { align-items: flex-start; }
        }

        @media (max-width: 700px) {
            .layout { display: block; }
            .sidebar { display: none; }
            .main { padding: 20px 14px 30px; }
            .topbar { flex-direction: column; }
            .page-title { font-size: 23px; }
            .actions { width: 100%; }
            .actions form, .actions .button { flex: 1; }
            .actions form .button { width: 100%; }
            .server-overview { flex-direction: column; padding: 16px; }
            .server-identity { align-items: flex-start; }
            .stats { gap: 9px; }
            .stat { padding: 14px; }
            .stat-value { font-size: 21px; }
            .section-heading { align-items: flex-start; flex-direction: column; }
            .range-form { width: 100%; }
            .range-option { flex: 1; text-align: center; }
            .panel { padding: 15px; }
            .details-list { grid-template-columns: 1fr; }
            .footer { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="brand">
            <span class="brand-mark">⌁</span>
            <span>ServerScope</span>
        </a>

        <div class="eyebrow">Workspace</div>
        <a href="{{ route('dashboard') }}" class="nav-item">
            <span>▦</span> Overview
        </a>

        <div class="eyebrow">Infrastructure</div>
        <a href="{{ route('dashboard') }}#servers" class="nav-item active">
            <span>▤</span> Servers
        </a>

        <div class="sidebar-bottom">
            <strong>Monitoring status</strong>
            <span class="muted small">Agentverbinding via HTTPS</span>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="breadcrumb">
                    <a href="{{ route('dashboard') }}">Overview</a>
                    <span> / </span>
                    <span>{{ $server->name }}</span>
                </div>

                <h1 class="page-title">{{ $server->name }}</h1>
                <p class="page-subtitle">Serverdetails en historische prestaties.</p>
            </div>

            <div class="actions">
                <a href="{{ route('dashboard') }}" class="button">← Terug naar overzicht</a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button" type="submit">Uitloggen</button>
                </form>
            </div>
        </header>

        <p class="account">
            Ingelogd als {{ auth()->user()->email }}
        </p>

        <section class="server-overview">
            <div class="server-identity">
                <div class="server-icon">▤</div>
                <div>
                    <h2 style="margin:0;font-size:18px;">{{ $server->name }}</h2>
                    <div class="server-host">{{ $server->hostname }}</div>
                    <div class="muted small" style="margin-top:6px;">
                        {{ $server->operating_system ?? 'Besturingssysteem onbekend' }}
                        · Agent {{ $server->agent_version ?? 'onbekend' }}
                    </div>
                </div>
            </div>

            <span class="badge {{ $displayStatus }}">
                @switch($displayStatus)
                    @case('online')
                        Online
                        @break
                    @case('offline')
                        Offline
                        @break
                    @default
                        In afwachting
                @endswitch
            </span>
        </section>

        <section class="stats" aria-label="Actuele serverstatistieken">
            <article class="stat">
                <div class="stat-label">CPU-gebruik</div>
                <div class="stat-value">
                    {{ $latestSample?->cpu_percent !== null
                        ? number_format($latestSample->cpu_percent, 1, ',', '.') . '%'
                        : '—' }}
                </div>
                <div class="stat-description">Laatste meting</div>
            </article>

            <article class="stat">
                <div class="stat-label">RAM-gebruik</div>
                <div class="stat-value">
                    {{ $memoryPercent !== null
                        ? number_format($memoryPercent, 1, ',', '.') . '%'
                        : '—' }}
                </div>
                <div class="stat-description">
                    {{ $memoryTotal > 0 ? $formatBytes($memoryUsed) . ' van ' . $formatBytes($memoryTotal) : 'Geen geheugendata' }}
                </div>
            </article>

            <article class="stat">
                <div class="stat-label">Load average (1m)</div>
                <div class="stat-value">
                    {{ $latestSample?->load_1 !== null
                        ? number_format($latestSample->load_1, 2, ',', '.')
                        : '—' }}
                </div>
                <div class="stat-description">Systeemgemiddelde over 1 minuut</div>
            </article>

            <article class="stat">
                <div class="stat-label">Uptime</div>
                <div class="stat-value">
                    {{ $formatUptime($latestSample?->uptime_seconds) }}
                </div>
                <div class="stat-description">Sinds de laatste systeemstart</div>
            </article>
        </section>

        <div class="section-heading">
            <div>
                <h2>Prestatiemonitoring</h2>
                <p>Historische metingen van deze server.</p>
            </div>

            <form method="GET" action="{{ route('servers.show', $server) }}" class="range-form">
                @foreach ($rangeOptions as $key => $option)
                    <button
                        class="range-option {{ $range === $key ? 'active' : '' }}"
                        type="submit"
                        name="range"
                        value="{{ $key }}"
                        aria-pressed="{{ $range === $key ? 'true' : 'false' }}"
                    >
                        {{ $key === '1h' ? '1u' : ($key === '6h' ? '6u' : ($key === '24h' ? '24u' : $key)) }}
                    </button>
                @endforeach
            </form>
        </div>

        <div class="chart-grid">
            <section class="panel chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>CPU-gebruik</h3>
                        <p>Percentage processorbelasting</p>
                    </div>
                    <span class="muted small">{{ $sampleCount }} meetpunten</span>
                </div>

                @if ($samples->contains(fn ($sample) => $sample->cpu_percent !== null))
                    <div class="chart-wrap">
                        <canvas id="cpu-chart" aria-label="Grafiek CPU-gebruik" role="img"></canvas>
                    </div>
                @else
                    <div class="empty-chart">
                        <div>
                            <strong>Nog geen CPU-historie</strong>
                            Er zijn geen CPU-metingen in deze periode.
                        </div>
                    </div>
                @endif
            </section>

            <section class="panel chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Geheugengebruik</h3>
                        <p>Gebruikt RAM als percentage van totaal</p>
                    </div>
                </div>

                @if ($samples->contains(fn ($sample) =>
                    $sample->memory_total_bytes > 0 &&
                    $sample->memory_used_bytes !== null &&
                    $sample->memory_used_bytes <= $sample->memory_total_bytes
                ))
                    <div class="chart-wrap">
                        <canvas id="memory-chart" aria-label="Grafiek RAM-gebruik" role="img"></canvas>
                    </div>
                @else
                    <div class="empty-chart">
                        <div>
                            <strong>Nog geen geheugengeschiedenis</strong>
                            Er zijn geen geldige RAM-metingen in deze periode.
                        </div>
                    </div>
                @endif
            </section>

            <section class="panel chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Load average</h3>
                        <p>Gemiddelde systeembelasting over 1, 5 en 15 minuten</p>
                    </div>
                </div>

                @if ($samples->contains(fn ($sample) =>
                    $sample->load_1 !== null ||
                    $sample->load_5 !== null ||
                    $sample->load_15 !== null
                ))
                    <div class="chart-wrap">
                        <canvas id="load-chart" aria-label="Grafiek load average" role="img"></canvas>
                    </div>
                @else
                    <div class="empty-chart">
                        <div>
                            <strong>Nog geen loadgegevens</strong>
                            Er zijn geen loadmetingen in deze periode.
                        </div>
                    </div>
                @endif
            </section>

            <section class="panel chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Netwerkverkeer</h3>
                        <p>Ontvangen en verzonden data per seconde, op basis van opeenvolgende metingen</p>
                    </div>
                </div>

                <div class="empty-chart">
                    <div>
                        <strong>Netwerkdetails</strong>
                        <div>
                            Ontvangen totaal:
                            {{ $network['rx'] !== null ? $formatBytes($network['rx']) : '—' }}
                        </div>
                        <div>
                            Verzonden totaal:
                            {{ $network['tx'] !== null ? $formatBytes($network['tx']) : '—' }}
                        </div>
                        <div style="margin-top:10px;">
                            Inkomend:
                            {{ $networkRates['rx'] !== null ? $formatBytes((int) $networkRates['rx']) . '/s' : 'Onvoldoende geldige meetpunten' }}
                        </div>
                        <div>
                            Uitgaand:
                            {{ $networkRates['tx'] !== null ? $formatBytes((int) $networkRates['tx']) . '/s' : 'Onvoldoende geldige meetpunten' }}
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="section-heading">
            <div>
                <h2>Schijfgebruik</h2>
                <p>Opslagruimte volgens de meest recente meting.</p>
            </div>
        </div>

        <section class="panel">
            @if ($disks->isEmpty())
                <div class="empty">
                    Er zijn nog geen schijfgegevens beschikbaar voor deze server.
                </div>
            @else
                <div class="disk-list">
                    @foreach ($disks as $disk)
                        <div class="disk-item">
                            <div class="disk-heading">
                                <strong>{{ $disk['path'] }}</strong>
                                <span>
                                    {{ $disk['percent'] !== null
                                        ? number_format($disk['percent'], 1, ',', '.') . '%'
                                        : 'Onbekend' }}
                                </span>
                            </div>

                            <div class="track">
                                <div
                                    class="fill"
                                    style="width: {{ $disk['percent'] ?? 0 }}%"
                                ></div>
                            </div>

                            <div class="disk-meta">
                                <span>
                                    Gebruikt: {{ $formatBytes($disk['used_bytes']) }}
                                </span>
                                <span>
                                    Vrij: {{ $formatBytes($disk['available_bytes']) }}
                                </span>
                                <span>
                                    Totaal: {{ $formatBytes($disk['total_bytes']) }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="section-heading">
            <div>
                <h2>Systeeminformatie</h2>
                <p>Identificatie en laatste communicatie.</p>
            </div>
        </div>

        <section class="panel">
            <div class="details-list">
                <div class="detail-row">
                    <span>Servernaam</span>
                    <strong>{{ $server->name }}</strong>
                </div>

                <div class="detail-row">
                    <span>Hostname</span>
                    <strong>{{ $server->hostname }}</strong>
                </div>

                <div class="detail-row">
                    <span>IP-adres</span>
                    <strong>{{ $server->ip_address ?? 'Niet ingesteld' }}</strong>
                </div>

                <div class="detail-row">
                    <span>Besturingssysteem</span>
                    <strong>{{ $server->operating_system ?? 'Onbekend' }}</strong>
                </div>

                <div class="detail-row">
                    <span>Agentversie</span>
                    <strong>{{ $server->agent_version ?? 'Onbekend' }}</strong>
                </div>

                <div class="detail-row">
                    <span>Laatste contact</span>
                    <strong>{{ $server->last_seen_at?->format('d-m-Y H:i:s') ?? 'Nog nooit' }}</strong>
                </div>

                <div class="detail-row">
                    <span>Laatste meting</span>
                    <strong>{{ $latestSample?->collected_at?->format('d-m-Y H:i:s') ?? 'Geen metingen' }}</strong>
                </div>

                <div class="detail-row">
                    <span>Metingen in geselecteerde periode</span>
                    <strong>{{ $sampleCount }}</strong>
                </div>
            </div>
        </section>

        <footer class="footer">
            <span>ServerScope · Infrastructure monitoring</span>
            <span>Ingelogd als {{ auth()->user()->email }}</span>
        </footer>
    </main>
</div>

<script>
    window.serverScopeChartData = {{ Illuminate\Support\Js::from($chartData) }};
</script>
</body>
</html>
