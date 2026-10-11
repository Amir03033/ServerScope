<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Alerts — ServerScope</title>

    <style>
        :root {
            color-scheme: dark;
            --bg: #070c17;
            --panel: #0d1627;
            --border: #1f2e47;
            --text: #edf4ff;
            --muted: #8397b1;
            --cyan: #38bdf8;
            --purple: #a78bfa;
            --green: #4ade80;
            --yellow: #fbbf24;
            --red: #fb7185;
            --radius: 14px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; }
        button { font: inherit; }

        .ico {
            width: 1em; height: 1em;
            fill: none; stroke: currentColor;
            stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round;
            flex: 0 0 auto;
        }

        .layout {
            display: grid;
            grid-template-columns: 232px minmax(0, 1fr);
            min-height: 100vh;
        }

        /* ---------- sidebar ---------- */
        .sidebar {
            position: sticky; top: 0;
            display: flex; flex-direction: column;
            height: 100vh;
            padding: 22px 14px;
            border-right: 1px solid #18253b;
            background: linear-gradient(180deg, #08111f 0%, #0a1226 55%, #14122e 100%);
        }

        .brand { display: flex; align-items: center; gap: 11px; padding: 0 8px; margin-bottom: 28px; }
        .brand .ico { width: 30px; height: 30px; color: var(--cyan); stroke-width: 2.2; }
        .brand-name { font-size: 17px; font-weight: 700; letter-spacing: -.3px; line-height: 1.1; }
        .brand-caption { color: var(--muted); font-size: 10px; }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 12px; margin: 3px 0;
            border: 1px solid transparent; border-radius: 10px;
            color: #9db1ca; text-decoration: none;
            transition: background .15s, color .15s;
        }
        .nav-item .ico { font-size: 18px; }
        .nav-item:hover { background: #111d33; color: #fff; }
        .nav-item.active {
            border-color: #25477a;
            background: linear-gradient(100deg, #15386a, #112a50);
            color: #e0f2fe;
        }
        .nav-count {
            margin-left: auto; padding: 1px 7px; border-radius: 99px;
            background: #3a1420; color: var(--red); font-size: 11px; font-weight: 700;
        }

        .sidebar-bottom {
            margin-top: auto; padding: 14px;
            border: 1px solid var(--border); border-radius: 12px;
            background: rgba(13, 22, 39, .8);
        }
        .connection-dot {
            display: inline-block; width: 8px; height: 8px; margin-right: 7px;
            border-radius: 50%; background: var(--green); box-shadow: 0 0 10px #4ade8060;
        }

        .muted { color: var(--muted); }
        .small { font-size: 11px; }

        /* ---------- main + topbar ---------- */
        .main { min-width: 0; padding: 22px 28px 32px; }

        .topbar { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 22px; }
        .breadcrumb { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .page-title { margin: 0; font-size: clamp(26px, 2.4vw, 34px); font-weight: 700; letter-spacing: -.8px; line-height: 1.15; }
        .page-subtitle { margin: 4px 0 0; color: #9db1ca; font-size: 13px; }

        .actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }

        .button {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 40px; padding: 0 14px;
            border: 1px solid #2a3c58; border-radius: 10px;
            background: #111d33; color: var(--text); text-decoration: none; cursor: pointer;
            transition: background .15s;
        }
        .button:hover { background: #1a2b47; }

        .account { margin: -10px 0 18px; color: var(--muted); font-size: 11px; overflow-wrap: anywhere; }

        /* ---------- KPI cards ---------- */
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }

        .stat {
            position: relative; min-width: 0; overflow: hidden;
            padding: 18px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: linear-gradient(160deg, color-mix(in srgb, var(--tone) 9%, #0e1829), #0b1424 70%);
        }
        .tone-cyan { --tone: var(--cyan); }
        .tone-red { --tone: var(--red); }
        .tone-green { --tone: var(--green); }

        .stat-body { display: flex; align-items: center; gap: 14px; }

        .stat-icon {
            display: grid; place-items: center;
            width: 46px; height: 46px; flex: 0 0 46px;
            border: 1px solid color-mix(in srgb, var(--tone) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--tone) 14%, #0b1424);
            color: var(--tone); font-size: 23px;
        }

        .stat-label { color: #c3d1e4; font-size: 12px; }
        .stat-value { margin-top: 1px; font-size: 28px; font-weight: 700; letter-spacing: -.6px; line-height: 1.15; color: var(--tone); }
        .stat-value.plain { color: var(--text); }

        .red { color: var(--red); }
        .green { color: var(--green); }
        .cyan { color: var(--cyan); }

        /* ---------- panel ---------- */
        .panel {
            margin-top: 14px; padding: 18px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--panel);
        }

        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 0 0 16px; }
        .section-heading h2 { margin: 0; font-size: 16px; }
        .section-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }

        .filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }

        .filter {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 12px;
            border: 1px solid #2a3c58; border-radius: 10px;
            background: #0a1322; color: var(--muted); text-decoration: none;
            transition: border-color .15s, color .15s, background .15s;
        }
        .filter:hover { border-color: #23618a; color: var(--text); }
        .filter.active {
            border-color: #25477a;
            background: linear-gradient(100deg, #15386a, #112a50);
            color: #e0f2fe;
        }
        .filter-count { padding: 1px 7px; border-radius: 6px; background: #1a2840; font-size: 11px; }
        .filter.active .filter-count { background: #0b2447; }

        /* ---------- alert cards ---------- */
        .alert-list { display: grid; gap: 12px; }

        .alert-card {
            --accent: var(--green);
            position: relative; min-width: 0; padding: 18px 18px 18px 20px;
            border: 1px solid var(--border); border-radius: 12px;
            background: #0a1322; overflow: hidden;
        }
        .alert-card::before {
            content: ""; position: absolute; inset: 0 auto 0 0; width: 3px; background: var(--accent);
        }
        .alert-card.is-active {
            --accent: var(--red);
            background: linear-gradient(160deg, #1a1020, #0a1322 60%);
            border-color: #4a1d2c;
        }

        .alert-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .alert-main { display: flex; align-items: flex-start; gap: 13px; min-width: 0; }

        .alert-icon {
            display: grid; place-items: center;
            width: 38px; height: 38px; flex: 0 0 38px;
            border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
            border-radius: 11px;
            background: color-mix(in srgb, var(--accent) 14%, #0b1424);
            color: var(--accent); font-size: 19px;
        }

        .alert-title { margin: 0; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
        .alert-server { display: inline-block; margin-top: 3px; color: var(--muted); font-size: 12px; text-decoration: none; overflow-wrap: anywhere; }
        a.alert-server:hover { color: var(--cyan); }

        .status {
            display: inline-flex; flex-shrink: 0; align-items: center; gap: 7px;
            padding: 5px 10px;
            border: 1px solid #2c4058; border-radius: 99px; background: #152237;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .status::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .status-active { border-color: #7f1d1d; background: #2a121a; color: var(--red); }
        .status-resolved { border-color: #14583f; background: #0a2a24; color: var(--green); }

        .alert-metrics {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px;
            margin-top: 16px; padding-top: 14px; border-top: 1px solid #1a2840;
        }
        .metric-label { margin-bottom: 4px; color: var(--muted); font-size: 11px; }
        .metric-value { font-size: 15px; font-weight: 600; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        .dimension { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; font-weight: 400; overflow-wrap: anywhere; }

        .alert-times { display: flex; flex-wrap: wrap; gap: 8px 22px; margin-top: 14px; color: var(--muted); font-size: 11px; }
        .alert-times span { display: inline-flex; align-items: center; gap: 6px; }
        .alert-times .ico { font-size: 13px; }

        /* ---------- empty + pagination ---------- */
        .empty { padding: 48px 20px; border: 1px dashed #304158; border-radius: 12px; text-align: center; }
        .empty-icon {
            display: grid; place-items: center; width: 52px; height: 52px; margin: 0 auto 14px;
            border: 1px solid var(--border); border-radius: 14px; background: #17263a; color: var(--cyan); font-size: 24px;
        }
        .empty h3 { margin: 0; font-size: 15px; }
        .empty p { max-width: 400px; margin: 8px auto 0; color: var(--muted); font-size: 12px; }

        .pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 18px; color: var(--muted); font-size: 12px; }
        .pagination-links { display: flex; gap: 8px; }
        .pagination-links a, .pagination-links span {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 36px; padding: 0 12px;
            border: 1px solid #2a3c58; border-radius: 9px; background: #0a1322; text-decoration: none;
        }
        .pagination-links a:hover { border-color: #23618a; color: var(--cyan); }
        .pagination-links span { color: #4b5d78; cursor: not-allowed; }

        .footer {
            display: flex; justify-content: space-between; gap: 12px;
            margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border);
            color: #657b95; font-size: 10px;
        }

        :focus-visible { outline: 2px solid var(--cyan); outline-offset: 2px; }

        @media (max-width: 1100px) {
            .layout { grid-template-columns: 205px minmax(0, 1fr); }
            .main { padding: 20px; }
        }

        @media (max-width: 850px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 14px; border-right: 0; border-bottom: 1px solid var(--border); }
            .brand { margin-bottom: 12px; }
            .sidebar-bottom { display: none; }
            .sidebar nav { display: flex; gap: 5px; overflow-x: auto; }
            .nav-item { flex: 0 0 auto; }
            .main { padding: 18px 16px 30px; }
            .topbar { flex-direction: column; }
            .actions { justify-content: flex-start; }
        }

        @media (max-width: 600px) {
            .stats { grid-template-columns: 1fr; }
            .alert-metrics { grid-template-columns: 1fr; }
            .alert-heading { flex-direction: column; }
            .footer { flex-direction: column; }
        }
    </style>
</head>

<body>
{{-- SVG icon sprite --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="i-pulse" viewBox="0 0 24 24"><path d="M2 12h4l3-8 5 16 3-8h5"/></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"/></symbol>
    <symbol id="i-server" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/></symbol>
    <symbol id="i-metrics" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m7 15 3-4 3 2 4-5"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></symbol>
    <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/></symbol>
    <symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
    <symbol id="i-arrow-left" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>
    <symbol id="i-play" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m10 8.5 5 3.5-5 3.5z"/></symbol>
    <symbol id="i-flag" viewBox="0 0 24 24"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></symbol>
</svg>

<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <svg class="ico"><use href="#i-pulse"/></svg>
            <div>
                <div class="brand-name">ServerScope</div>
                <div class="brand-caption">Real-time server monitoring</div>
            </div>
        </div>

        <nav>
            <a href="{{ route('dashboard') }}" class="nav-item">
                <svg class="ico"><use href="#i-home"/></svg> Dashboard
            </a>
            <a href="{{ route('dashboard') }}#servers" class="nav-item">
                <svg class="ico"><use href="#i-server"/></svg> Servers
            </a>
            <a href="{{ route('dashboard') }}#metrics" class="nav-item">
                <svg class="ico"><use href="#i-metrics"/></svg> Metrics
            </a>
            <a href="{{ route('alerts.index') }}" class="nav-item active" aria-current="page">
                <svg class="ico"><use href="#i-bell"/></svg> Alerts
                @if ($activeCount > 0)
                    <span class="nav-count">{{ $activeCount }}</span>
                @endif
            </a>

            @if(auth()->user()->is_admin)
                <a href="{{ route('alert-rules.index') }}" class="nav-item">
                    <svg class="ico"><use href="#i-sliders"/></svg> Alertregels
                </a>
            @endif
        </nav>

        <div class="sidebar-bottom">
            <strong><span class="connection-dot"></span>Monitoring status</strong>
            <div class="muted small" style="margin-top:6px">Agentverbinding via HTTPS</div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="breadcrumb">
                    <svg class="ico"><use href="#i-home"/></svg> <span>›</span> Alerts
                </div>
                <h1 class="page-title">Alerts</h1>
                <p class="page-subtitle">Bekijk incidenten en controleer de status van je infrastructuur.</p>
            </div>

            <div class="actions">
                <a href="{{ route('dashboard') }}" class="button">
                    <svg class="ico"><use href="#i-arrow-left"/></svg> Dashboard
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button" type="submit">Uitloggen</button>
                </form>
            </div>
        </header>

        <p class="account">Ingelogd als {{ auth()->user()->email }}</p>

        <section class="stats" aria-label="Alertstatistieken">
            <article class="stat tone-red">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-alert"/></svg></span>
                    <div>
                        <div class="stat-label">Actieve alerts</div>
                        <div class="stat-value">{{ number_format($activeCount) }}</div>
                    </div>
                </div>
            </article>

            <article class="stat tone-green">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                    <div>
                        <div class="stat-label">Opgelost</div>
                        <div class="stat-value">{{ number_format($resolvedCount) }}</div>
                    </div>
                </div>
            </article>

            <article class="stat tone-cyan">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                    <div>
                        <div class="stat-label">Totaal geregistreerd</div>
                        <div class="stat-value plain">{{ number_format($totalCount) }}</div>
                    </div>
                </div>
            </article>
        </section>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>Alertgeschiedenis</h2>
                    <p>Bekijk huidige meldingen en eerder opgeloste incidenten.</p>
                </div>
                <span class="muted small">{{ $alerts->total() }} resultaten</span>
            </div>

            <nav class="filters" aria-label="Filter alerts">
                <a
                    href="{{ route('alerts.index', ['status' => 'active']) }}"
                    @class(['filter', 'active' => $status === 'active'])
                    @if ($status === 'active') aria-current="page" @endif
                >
                    Actief
                    <span class="filter-count">{{ $activeCount }}</span>
                </a>

                <a
                    href="{{ route('alerts.index', ['status' => 'resolved']) }}"
                    @class(['filter', 'active' => $status === 'resolved'])
                    @if ($status === 'resolved') aria-current="page" @endif
                >
                    Opgelost
                    <span class="filter-count">{{ $resolvedCount }}</span>
                </a>

                <a
                    href="{{ route('alerts.index', ['status' => 'all']) }}"
                    @class(['filter', 'active' => $status === 'all'])
                    @if ($status === 'all') aria-current="page" @endif
                >
                    Alles
                    <span class="filter-count">{{ $totalCount }}</span>
                </a>
            </nav>

            @if ($alerts->isEmpty())
                <div class="empty">
                    <div class="empty-icon">
                        @if ($status === 'active')
                            <svg class="ico"><use href="#i-check"/></svg>
                        @else
                            <svg class="ico"><use href="#i-clock"/></svg>
                        @endif
                    </div>

                    @if ($status === 'active')
                        <h3>Geen actieve alerts</h3>
                        <p>
                            Er zijn momenteel geen actieve alerts geregistreerd.
                            Nieuwe meldingen verschijnen hier wanneer een ingestelde regel wordt geactiveerd.
                        </p>
                    @elseif ($status === 'resolved')
                        <h3>Nog geen opgeloste alerts</h3>
                        <p>Wanneer een actieve alert wordt opgelost, verschijnt deze hier in de geschiedenis.</p>
                    @else
                        <h3>Nog geen alerts geregistreerd</h3>
                        <p>
                            Er zijn nog geen alerts om weer te geven.
                            Controleer of je alertregels zijn ingesteld en ingeschakeld.
                        </p>
                    @endif
                </div>
            @else
                <div class="alert-list">
                    @foreach ($alerts as $alert)
                        @php
                            $isActive = $alert->status === 'active';

                            $metricLabels = [
                                'cpu_percent' => 'CPU-gebruik',
                                'memory_percent' => 'RAM-gebruik',
                                'disk_percent' => 'Schijfgebruik',
                            ];

                            $metricKey = $alert->metric;
                            $metricLabel = $metricLabels[$metricKey]
                                ?? str_replace('_', ' ', $metricKey);

                            $unit = in_array(
                                $metricKey,
                                ['cpu_percent', 'memory_percent', 'disk_percent'],
                                true
                            ) ? '%' : '';

                            $operatorLabels = [
                                '>=' => '≥',
                                '>' => '>',
                                '<=' => '≤',
                                '<' => '<',
                            ];

                            $operator = $operatorLabels[$alert->alertRule?->operator ?? '']
                                ?? ($alert->alertRule?->operator ?? '≥');
                        @endphp

                        <article @class(['alert-card', 'is-active' => $isActive])>
                            <div class="alert-heading">
                                <div class="alert-main">
                                    <span class="alert-icon">
                                        <svg class="ico"><use href="#{{ $isActive ? 'i-alert' : 'i-check' }}"/></svg>
                                    </span>

                                    <div>
                                        <h3 class="alert-title">
                                            {{ $alert->alertRule?->name ?? 'Alertregel verwijderd' }}
                                        </h3>

                                        @if ($alert->server)
                                            <a
                                                class="alert-server"
                                                href="{{ route('servers.show', $alert->server) }}"
                                            >
                                                {{ $alert->server->name }}
                                                · {{ $alert->server->hostname }} →
                                            </a>
                                        @else
                                            <span class="alert-server">Server niet meer beschikbaar</span>
                                        @endif
                                    </div>
                                </div>

                                <span @class([
                                    'status',
                                    'status-active' => $isActive,
                                    'status-resolved' => ! $isActive,
                                ])>
                                    {{ $isActive ? 'Actief' : 'Opgelost' }}
                                </span>
                            </div>

                            <div class="alert-metrics">
                                <div>
                                    <div class="metric-label">Metric</div>
                                    <div class="metric-value">
                                        {{ $metricLabel }}

                                        @if ($metricKey === 'disk_percent' && $alert->dimension)
                                            <span class="dimension">Mountpoint: {{ $alert->dimension }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    <div class="metric-label">Waargenomen waarde</div>
                                    <div class="metric-value {{ $isActive ? 'red' : 'green' }}">
                                        {{ number_format($alert->observed_value, 1, ',', '.') }}{{ $unit }}
                                    </div>
                                </div>

                                <div>
                                    <div class="metric-label">Drempelwaarde</div>
                                    <div class="metric-value">
                                        {{ $operator }}
                                        {{ number_format($alert->threshold, 1, ',', '.') }}{{ $unit }}
                                    </div>
                                </div>
                            </div>

                            <div class="alert-times">
                                <span>
                                    <svg class="ico"><use href="#i-play"/></svg>
                                    Geactiveerd:
                                    {{ $alert->triggered_at?->format('d-m-Y H:i:s') ?? 'Onbekend' }}
                                </span>

                                @if ($alert->resolved_at)
                                    <span>
                                        <svg class="ico"><use href="#i-flag"/></svg>
                                        Opgelost:
                                        {{ $alert->resolved_at->format('d-m-Y H:i:s') }}
                                    </span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($alerts->hasPages())
                    <div class="pagination">
                        <span>
                            Resultaten {{ $alerts->firstItem() }}–{{ $alerts->lastItem() }}
                            van {{ $alerts->total() }}
                        </span>

                        <div class="pagination-links">
                            @if ($alerts->onFirstPage())
                                <span aria-disabled="true">← Vorige</span>
                            @else
                                <a href="{{ $alerts->previousPageUrl() }}">← Vorige</a>
                            @endif

                            @if ($alerts->hasMorePages())
                                <a href="{{ $alerts->nextPageUrl() }}">Volgende →</a>
                            @else
                                <span aria-disabled="true">Volgende →</span>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </section>

        <footer class="footer">
            <span>ServerScope · Infrastructure monitoring</span>
            <span>Ingelogd als {{ auth()->user()->email }}</span>
        </footer>
    </main>
</div>
</body>
</html>
