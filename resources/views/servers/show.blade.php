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

        a { color: inherit; text-decoration: none; }
        button { font: inherit; }

        .ico {
            width: 1em; height: 1em;
            fill: none; stroke: currentColor;
            stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round;
            flex: 0 0 auto;
        }

        .layout { display: grid; grid-template-columns: 232px minmax(0, 1fr); min-height: 100vh; }

        /* ---------- sidebar ---------- */
        .sidebar {
            position: sticky; top: 0;
            display: flex; flex-direction: column;
            height: 100vh; padding: 22px 14px;
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
            color: #9db1ca;
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
        .breadcrumb a:hover { color: var(--cyan); }
        .page-title { margin: 0; font-size: clamp(26px, 2.4vw, 34px); font-weight: 700; letter-spacing: -.8px; line-height: 1.15; overflow-wrap: anywhere; }
        .page-subtitle { margin: 4px 0 0; color: #9db1ca; font-size: 13px; }

        .actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .actions form { margin: 0; }

        .button {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 40px; padding: 0 14px;
            border: 1px solid #2a3c58; border-radius: 10px;
            background: #111d33; color: var(--text); cursor: pointer;
            transition: background .15s;
        }
        .button:hover { background: #1a2b47; }
        .button-primary { border-color: #0a7fb0; background: #075985; color: #ecfeff; }
        .button-primary:hover { background: #036b8b; }

        .account { margin: -10px 0 18px; color: var(--muted); font-size: 11px; overflow-wrap: anywhere; }

        /* ---------- server overview ---------- */
        .server-overview {
            display: flex; align-items: center; justify-content: space-between; gap: 18px;
            padding: 20px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background:
                radial-gradient(ellipse at top right, #0e4a6a30, transparent 55%),
                var(--panel);
        }
        .server-identity { display: flex; align-items: center; gap: 16px; min-width: 0; }
        .server-title { margin: 0; font-size: 18px; font-weight: 600; overflow-wrap: anywhere; }
        .server-host { margin-top: 2px; color: var(--muted); overflow-wrap: anywhere; }

        .badge-icon {
            display: grid; place-items: center;
            width: 34px; height: 34px; flex: 0 0 34px;
            border: 1px solid color-mix(in srgb, var(--tone, var(--cyan)) 40%, transparent);
            border-radius: 10px;
            background: color-mix(in srgb, var(--tone, var(--cyan)) 14%, #0b1424);
            color: var(--tone, var(--cyan)); font-size: 17px;
        }
        .badge-icon.lg { width: 56px; height: 56px; flex-basis: 56px; border-radius: 15px; font-size: 27px; }

        .badge {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 11px;
            border: 1px solid #2c4058; border-radius: 99px; background: #152237;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .badge::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .online { color: var(--green); }
        .offline { color: var(--red); }
        .pending { color: var(--yellow); }

        /* ---------- KPI cards ---------- */
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }

        .stat {
            min-width: 0; padding: 16px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: linear-gradient(160deg, color-mix(in srgb, var(--tone) 9%, #0e1829), #0b1424 70%);
        }
        .tone-cyan { --tone: var(--cyan); }
        .tone-purple { --tone: var(--purple); }
        .tone-green { --tone: var(--green); }
        .tone-yellow { --tone: var(--yellow); }

        .stat-body { display: flex; align-items: center; gap: 12px; }
        .stat-icon {
            display: grid; place-items: center;
            width: 44px; height: 44px; flex: 0 0 44px;
            border: 1px solid color-mix(in srgb, var(--tone) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--tone) 14%, #0b1424);
            color: var(--tone); font-size: 22px;
        }
        .stat-text { min-width: 0; }
        .stat-label { color: #c3d1e4; font-size: 12px; }
        .stat-value { margin-top: 1px; font-size: clamp(21px, 1.8vw, 27px); font-weight: 700; letter-spacing: -.6px; line-height: 1.15; color: var(--tone); overflow-wrap: anywhere; }
        .stat-description { margin-top: 2px; color: var(--muted); font-size: 11px; }

        /* ---------- sections ---------- */
        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 26px 0 14px; }
        .section-heading h2 { margin: 0; font-size: 16px; }
        .section-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }

        .range-form {
            display: flex; flex-wrap: wrap; gap: 4px; padding: 4px;
            border: 1px solid #2a3c58; border-radius: 11px; background: #0a1322;
        }
        .range-option {
            padding: 7px 12px; border: 0; border-radius: 8px;
            background: transparent; color: var(--muted); font-size: 12px; cursor: pointer;
            transition: background .15s, color .15s;
        }
        .range-option:hover { color: var(--text); }
        .range-option.active { background: linear-gradient(100deg, #15386a, #112a50); color: #e0f2fe; }

        .chart-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }

        .panel { min-width: 0; padding: 18px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--panel); }

        .chart-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .chart-title { display: flex; align-items: center; gap: 11px; min-width: 0; }
        .chart-title h3 { margin: 0; font-size: 14px; font-weight: 600; }
        .chart-title p { margin: 1px 0 0; color: var(--muted); font-size: 11px; }

        .pill { padding: 4px 10px; border: 1px solid #2a3c58; border-radius: 8px; background: #121f36; color: #b9c8dc; font-size: 11px; white-space: nowrap; }

        .legend { display: flex; flex-wrap: wrap; gap: 14px; margin: -4px 0 8px; font-size: 11px; color: #b9c8dc; }
        .legend i { display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; }

        .chart-wrap { position: relative; height: 215px; }

        .empty-chart {
            display: grid; place-items: center; min-height: 215px; padding: 20px;
            border: 1px dashed #2c3c53; border-radius: 10px;
            color: var(--muted); text-align: center; font-size: 12px;
        }
        .empty-chart strong { display: block; margin-bottom: 6px; color: var(--text); font-size: 13px; }

        /* network */
        .network-list { min-height: 215px; display: grid; align-content: center; }
        .network-item { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 14px 0; border-bottom: 1px solid #1a2840; }
        .network-item:last-child { border-bottom: 0; }
        .network-label { display: flex; align-items: center; gap: 6px; color: #c3d1e4; font-size: 12px; }
        .network-label .ico { font-size: 16px; }
        .network-value { margin-top: 2px; font-size: 22px; font-weight: 700; letter-spacing: -.4px; font-variant-numeric: tabular-nums; }
        .network-total { text-align: right; color: var(--muted); font-size: 11px; }
        .network-total strong { display: block; margin-top: 2px; color: var(--text); font-size: 14px; font-weight: 600; }

        /* disks */
        .disk-list { display: grid; gap: 20px; }
        .disk-item { min-width: 0; }
        .disk-heading { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; font-size: 12px; }
        .disk-heading strong { overflow-wrap: anywhere; }
        .disk-heading span { color: var(--purple); font-weight: 600; white-space: nowrap; }
        .track { height: 7px; overflow: hidden; border-radius: 99px; background: #25364b; }
        .fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0891b2, var(--cyan)); }
        .disk-meta { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 14px; margin-top: 7px; color: var(--muted); font-size: 11px; }

        /* details */
        .details-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 28px; }
        .detail-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid #1a2840; font-size: 12px;
        }
        .detail-row span { display: flex; align-items: center; gap: 9px; color: var(--muted); }
        .detail-row span .ico { font-size: 15px; color: #9db1ca; }
        .detail-row strong { font-weight: 500; text-align: right; overflow-wrap: anywhere; color: #e3edf9; }

        .empty { padding: 34px 20px; border: 1px dashed #304158; border-radius: 12px; color: var(--muted); text-align: center; }

        .footer {
            display: flex; justify-content: space-between; gap: 12px;
            margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border);
            color: #657b95; font-size: 10px;
        }

        :focus-visible { outline: 2px solid var(--cyan); outline-offset: 2px; }

        @media (max-width: 1280px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

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
            .chart-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .stats { grid-template-columns: 1fr; }
            .server-overview { flex-direction: column; align-items: flex-start; padding: 16px; }
            .section-heading { flex-direction: column; align-items: flex-start; }
            .range-form { width: 100%; }
            .range-option { flex: 1; text-align: center; }
            .details-list { grid-template-columns: 1fr; }
            .panel { padding: 15px; }
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
    <symbol id="i-cpu" viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6" rx="1"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/></symbol>
    <symbol id="i-ram" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="11" rx="2"/><path d="M6 10v3M10 10v3M14 10v3M18 10v3M6 17v3M18 17v3"/></symbol>
    <symbol id="i-bars" viewBox="0 0 24 24"><path d="M5 20v-7M12 20V4M19 20V9"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-disk" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 14h18M7 17h.01"/></symbol>
    <symbol id="i-swap" viewBox="0 0 24 24"><path d="m7 4-4 4 4 4M3 8h14M17 20l4-4-4-4M21 16H7"/></symbol>
    <symbol id="i-down" viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></symbol>
    <symbol id="i-up" viewBox="0 0 24 24"><path d="M12 19V5M6 11l6-6 6 6"/></symbol>
    <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></symbol>
    <symbol id="i-os" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/></symbol>
    <symbol id="i-tag" viewBox="0 0 24 24"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><path d="M8 8h.01"/></symbol>
    <symbol id="i-arrow-left" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>
</svg>

<div class="layout">
    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="brand">
            <svg class="ico"><use href="#i-pulse"/></svg>
            <div>
                <div class="brand-name">ServerScope</div>
                <div class="brand-caption">Real-time server monitoring</div>
            </div>
        </a>

        <nav>
            <a href="{{ route('dashboard') }}" class="nav-item">
                <svg class="ico"><use href="#i-home"/></svg> Dashboard
            </a>
            <a href="{{ route('dashboard') }}#servers" class="nav-item active" aria-current="page">
                <svg class="ico"><use href="#i-server"/></svg> Servers
            </a>
            <a href="{{ route('dashboard') }}#metrics" class="nav-item">
                <svg class="ico"><use href="#i-metrics"/></svg> Metrics
            </a>
            <a href="{{ route('alerts.index') }}" class="nav-item">
                <svg class="ico"><use href="#i-bell"/></svg> Alerts
                @if (($sidebarActiveAlerts ?? 0) > 0)
                    <span class="nav-count">{{ $sidebarActiveAlerts }}</span>
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
                    <svg class="ico"><use href="#i-home"/></svg>
                    <span>›</span>
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <span>›</span>
                    <span>{{ $server->name }}</span>
                </div>

                <h1 class="page-title">{{ $server->name }}</h1>
                <p class="page-subtitle">Serverdetails en historische prestaties.</p>
            </div>

            <div class="actions">
                <a href="{{ route('dashboard') }}" class="button">
                    <svg class="ico"><use href="#i-arrow-left"/></svg> Terug naar overzicht
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button" type="submit">Uitloggen</button>
                </form>
            </div>
        </header>

        <p class="account">Ingelogd als {{ auth()->user()->email }}</p>

        <section class="server-overview">
            <div class="server-identity">
                <span class="badge-icon lg"><svg class="ico"><use href="#i-server"/></svg></span>
                <div>
                    <h2 class="server-title">{{ $server->name }}</h2>
                    <div class="server-host">{{ $server->hostname }}</div>
                    <div class="muted small" style="margin-top:5px;">
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
            <article class="stat tone-cyan">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-cpu"/></svg></span>
                    <div class="stat-text">
                        <div class="stat-label">CPU-gebruik</div>
                        <div class="stat-value">
                            {{ $latestSample?->cpu_percent !== null
                                ? number_format($latestSample->cpu_percent, 1, ',', '.') . '%'
                                : '—' }}
                        </div>
                        <div class="stat-description">Laatste meting</div>
                    </div>
                </div>
            </article>

            <article class="stat tone-purple">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-ram"/></svg></span>
                    <div class="stat-text">
                        <div class="stat-label">RAM-gebruik</div>
                        <div class="stat-value">
                            {{ $memoryPercent !== null
                                ? number_format($memoryPercent, 1, ',', '.') . '%'
                                : '—' }}
                        </div>
                        <div class="stat-description">
                            {{ $memoryTotal > 0 ? $formatBytes($memoryUsed) . ' van ' . $formatBytes($memoryTotal) : 'Geen geheugendata' }}
                        </div>
                    </div>
                </div>
            </article>

            <article class="stat tone-green">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-bars"/></svg></span>
                    <div class="stat-text">
                        <div class="stat-label">Load average (1m)</div>
                        <div class="stat-value">
                            {{ $latestSample?->load_1 !== null
                                ? number_format($latestSample->load_1, 2, ',', '.')
                                : '—' }}
                        </div>
                        <div class="stat-description">Systeemgemiddelde over 1 minuut</div>
                    </div>
                </div>
            </article>

            <article class="stat tone-yellow">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
                    <div class="stat-text">
                        <div class="stat-label">Uptime</div>
                        <div class="stat-value">{{ $formatUptime($latestSample?->uptime_seconds) }}</div>
                        <div class="stat-description">Sinds de laatste systeemstart</div>
                    </div>
                </div>
            </article>
        </section>

        <div class="section-heading" id="metrics">
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
            <section class="panel">
                <div class="chart-heading">
                    <div class="chart-title">
                        <span class="badge-icon tone-cyan" style="--tone:var(--cyan)"><svg class="ico"><use href="#i-cpu"/></svg></span>
                        <div>
                            <h3>CPU-gebruik</h3>
                            <p>Percentage processorbelasting</p>
                        </div>
                    </div>
                    <span class="pill">{{ $sampleCount }} meetpunten</span>
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

            <section class="panel">
                <div class="chart-heading">
                    <div class="chart-title">
                        <span class="badge-icon" style="--tone:var(--purple)"><svg class="ico"><use href="#i-ram"/></svg></span>
                        <div>
                            <h3>Geheugengebruik</h3>
                            <p>Gebruikt RAM als percentage van totaal</p>
                        </div>
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

            <section class="panel">
                <div class="chart-heading">
                    <div class="chart-title">
                        <span class="badge-icon" style="--tone:var(--green)"><svg class="ico"><use href="#i-bars"/></svg></span>
                        <div>
                            <h3>Load average</h3>
                            <p>Systeembelasting over 1, 5 en 15 minuten</p>
                        </div>
                    </div>
                </div>

                <div class="legend">
                    <span><i style="background:#38bdf8"></i>1 minuut</span>
                    <span><i style="background:#a78bfa"></i>5 minuten</span>
                    <span><i style="background:#4ade80"></i>15 minuten</span>
                </div>

                @if ($samples->contains(fn ($sample) =>
                    $sample->load_1 !== null ||
                    $sample->load_5 !== null ||
                    $sample->load_15 !== null
                ))
                    <div class="chart-wrap" style="height:190px">
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

            <section class="panel">
                <div class="chart-heading">
                    <div class="chart-title">
                        <span class="badge-icon" style="--tone:var(--green)"><svg class="ico"><use href="#i-swap"/></svg></span>
                        <div>
                            <h3>Netwerkverkeer</h3>
                            <p>Data per seconde, op basis van opeenvolgende metingen</p>
                        </div>
                    </div>
                </div>

                <div class="network-list">
                    <div class="network-item">
                        <div>
                            <div class="network-label cyan" style="color:var(--cyan)">
                                <svg class="ico"><use href="#i-down"/></svg>
                                <span style="color:#c3d1e4">Inkomend</span>
                            </div>
                            <div class="network-value">
                                {{ $networkRates['rx'] !== null ? $formatBytes((int) $networkRates['rx']) . '/s' : '—' }}
                            </div>
                            @if ($networkRates['rx'] === null)
                                <div class="muted small">Onvoldoende geldige meetpunten</div>
                            @endif
                        </div>
                        <div class="network-total">
                            Ontvangen totaal
                            <strong>{{ $network['rx'] !== null ? $formatBytes($network['rx']) : '—' }}</strong>
                        </div>
                    </div>

                    <div class="network-item">
                        <div>
                            <div class="network-label" style="color:var(--purple)">
                                <svg class="ico"><use href="#i-up"/></svg>
                                <span style="color:#c3d1e4">Uitgaand</span>
                            </div>
                            <div class="network-value">
                                {{ $networkRates['tx'] !== null ? $formatBytes((int) $networkRates['tx']) . '/s' : '—' }}
                            </div>
                            @if ($networkRates['tx'] === null)
                                <div class="muted small">Onvoldoende geldige meetpunten</div>
                            @endif
                        </div>
                        <div class="network-total">
                            Verzonden totaal
                            <strong>{{ $network['tx'] !== null ? $formatBytes($network['tx']) : '—' }}</strong>
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
                                <div class="fill" style="width: {{ $disk['percent'] ?? 0 }}%"></div>
                            </div>

                            <div class="disk-meta">
                                <span>Gebruikt: {{ $formatBytes($disk['used_bytes']) }}</span>
                                <span>Vrij: {{ $formatBytes($disk['available_bytes']) }}</span>
                                <span>Totaal: {{ $formatBytes($disk['total_bytes']) }}</span>
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
                    <span><svg class="ico"><use href="#i-server"/></svg> Servernaam</span>
                    <strong>{{ $server->name }}</strong>
                </div>

                <div class="detail-row">
                    <span><svg class="ico"><use href="#i-globe"/></svg> Hostname</span>
                    <strong>{{ $server->hostname }}</strong>
                </div>

                <div class="detail-row">
                    <span><svg class="ico"><use href="#i-swap"/></svg> IP-adres</span>
                    <strong>{{ $server->ip_address ?? 'Niet ingesteld' }}</strong>
                </div>

                <div class="detail-row">
                    <span><svg class="ico"><use href="#i-os"/></svg> Besturingssysteem</span>
                    <strong>{{ $server->operating_system ?? 'Onbekend' }}</strong>
                </div>

                <div class="detail-row">
                    <span><svg class="ico"><use href="#i-tag"/></svg> Agentversie</span>
                    <strong>{{ $server->agent_version ?? 'Onbekend' }}</strong>
                </div>

                <div class="detail-row">
                    <span><svg class="ico"><use href="#i-clock"/></svg> Laatste contact</span>
                    <strong>{{ $server->last_seen_at?->format('d-m-Y H:i:s') ?? 'Nog nooit' }}</strong>
                </div>

                <div class="detail-row">
                    <span><svg class="ico"><use href="#i-metrics"/></svg> Laatste meting</span>
                    <strong>{{ $latestSample?->collected_at?->format('d-m-Y H:i:s') ?? 'Geen metingen' }}</strong>
                </div>

                <div class="detail-row">
                    <span><svg class="ico"><use href="#i-bars"/></svg> Metingen in periode</span>
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
