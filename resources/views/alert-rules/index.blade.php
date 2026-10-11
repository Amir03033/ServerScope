<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Alertregels — ServerScope</title>

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
        a, button { font: inherit; }

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
        .actions form { margin: 0; }

        .button {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 40px; padding: 0 14px;
            border: 1px solid #2a3c58; border-radius: 10px;
            background: #111d33; color: var(--text); text-decoration: none; cursor: pointer;
            transition: background .15s;
        }
        .button:hover { background: #1a2b47; }
        .button-primary { border-color: #0a7fb0; background: #075985; color: #ecfeff; }
        .button-primary:hover { background: #036b8b; }
        .button-danger { border-color: #7f1d1d; background: #2a121a; color: var(--red); }
        .button-danger:hover { background: #451a24; }
        .button-sm { min-height: 36px; padding: 0 12px; font-size: 13px; }

        .account { margin: -10px 0 18px; color: var(--muted); font-size: 11px; overflow-wrap: anywhere; }

        .notice {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 16px; padding: 12px 15px;
            border: 1px solid #14583f; border-radius: 10px;
            background: #0a2a24; color: var(--green);
        }
        .error-notice { border-color: #7f1d1d; background: #2a121a; color: var(--red); }

        /* ---------- KPI cards ---------- */
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }

        .stat {
            min-width: 0; padding: 18px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: linear-gradient(160deg, color-mix(in srgb, var(--tone) 9%, #0e1829), #0b1424 70%);
        }
        .tone-cyan { --tone: var(--cyan); }
        .tone-green { --tone: var(--green); }
        .tone-muted { --tone: #94a3b8; }

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

        /* ---------- panel ---------- */
        .panel { margin-top: 14px; padding: 18px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--panel); }

        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 0 0 16px; }
        .section-heading h2 { margin: 0; font-size: 16px; }
        .section-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }

        /* ---------- rule cards ---------- */
        .rule-list { display: grid; gap: 12px; }

        .rule-card {
            --accent: #64748b;
            position: relative; min-width: 0; overflow: hidden;
            padding: 18px 18px 18px 20px;
            border: 1px solid var(--border); border-radius: 12px; background: #0a1322;
        }
        .rule-card::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 3px; background: var(--accent); }
        .rule-card.is-enabled { --accent: var(--cyan); }

        .rule-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .rule-main { display: flex; align-items: flex-start; gap: 13px; min-width: 0; }

        .rule-icon {
            display: grid; place-items: center;
            width: 38px; height: 38px; flex: 0 0 38px;
            border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
            border-radius: 11px;
            background: color-mix(in srgb, var(--accent) 14%, #0b1424);
            color: var(--accent); font-size: 19px;
        }

        .rule-name { margin: 0; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
        .rule-description { margin-top: 3px; color: var(--muted); font-size: 12px; }

        .badge {
            display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0;
            padding: 5px 10px;
            border: 1px solid #2c4058; border-radius: 99px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .badge::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .badge-enabled { border-color: #14583f; background: #0a2a24; color: var(--green); }
        .badge-disabled { background: #152237; color: var(--muted); }

        .rule-details {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px;
            margin-top: 16px; padding-top: 14px; border-top: 1px solid #1a2840;
        }
        .detail-label { margin-bottom: 4px; color: var(--muted); font-size: 11px; }
        .detail-value { font-size: 15px; font-weight: 600; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }

        .rule-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
        .rule-actions form { margin: 0; }

        /* ---------- empty + pagination ---------- */
        .empty { padding: 48px 20px; border: 1px dashed #304158; border-radius: 12px; text-align: center; }
        .empty-icon {
            display: grid; place-items: center; width: 52px; height: 52px; margin: 0 auto 14px;
            border: 1px solid var(--border); border-radius: 14px; background: #17263a; color: var(--cyan); font-size: 24px;
        }
        .empty h3 { margin: 0; font-size: 15px; }
        .empty p { max-width: 400px; margin: 8px auto 18px; color: var(--muted); font-size: 12px; }

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
            .rule-details { grid-template-columns: 1fr; }
            .rule-top { flex-direction: column; }
            .footer { flex-direction: column; }
        }
    </style>
</head>

<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="i-pulse" viewBox="0 0 24 24"><path d="M2 12h4l3-8 5 16 3-8h5"/></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"/></symbol>
    <symbol id="i-server" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/></symbol>
    <symbol id="i-metrics" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m7 15 3-4 3 2 4-5"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></symbol>
    <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/></symbol>
    <symbol id="i-cpu" viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6" rx="1"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/></symbol>
    <symbol id="i-ram" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="11" rx="2"/><path d="M6 10v3M10 10v3M14 10v3M18 10v3M6 17v3M18 17v3"/></symbol>
    <symbol id="i-disk" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 14h18M7 17h.01"/></symbol>
    <symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/></symbol>
    <symbol id="i-pause" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
    <symbol id="i-arrow-left" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>
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
            <a href="{{ route('alerts.index') }}" class="nav-item">
                <svg class="ico"><use href="#i-bell"/></svg> Alerts
                @if (($sidebarActiveAlerts ?? 0) > 0)
                    <span class="nav-count">{{ $sidebarActiveAlerts }}</span>
                @endif
            </a>
            <a href="{{ route('alert-rules.index') }}" class="nav-item active" aria-current="page">
                <svg class="ico"><use href="#i-sliders"/></svg> Alertregels
            </a>
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
                    <svg class="ico"><use href="#i-home"/></svg> <span>›</span> Alertregels
                </div>
                <h1 class="page-title">Alertregels</h1>
                <p class="page-subtitle">Configureer de drempelwaarden waarmee ServerScope incidenten detecteert.</p>
            </div>

            <div class="actions">
                <a href="{{ route('dashboard') }}" class="button">
                    <svg class="ico"><use href="#i-arrow-left"/></svg> Dashboard
                </a>
                <a href="{{ route('alert-rules.create') }}" class="button button-primary">
                    <svg class="ico"><use href="#i-plus"/></svg> Nieuwe regel
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button" type="submit">Uitloggen</button>
                </form>
            </div>
        </header>

        <p class="account">Ingelogd als {{ auth()->user()->email }}</p>

        @if (session('success'))
            <div class="notice" role="status">
                <svg class="ico"><use href="#i-check"/></svg> {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="notice error-notice" role="alert">
                <svg class="ico"><use href="#i-alert"/></svg> Er is iets misgegaan. Controleer je invoer.
            </div>
        @endif

        <section class="stats" aria-label="Statistieken alertregels">
            <article class="stat tone-cyan">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-list"/></svg></span>
                    <div>
                        <div class="stat-label">Totaal aantal regels</div>
                        <div class="stat-value">{{ number_format($rules->total()) }}</div>
                    </div>
                </div>
            </article>

            <article class="stat tone-green">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-check"/></svg></span>
                    <div>
                        <div class="stat-label">Ingeschakeld op deze pagina</div>
                        <div class="stat-value">
                            {{ number_format($rules->getCollection()->where('is_enabled', true)->count()) }}
                        </div>
                    </div>
                </div>
            </article>

            <article class="stat tone-muted">
                <div class="stat-body">
                    <span class="stat-icon"><svg class="ico"><use href="#i-pause"/></svg></span>
                    <div>
                        <div class="stat-label">Uitgeschakeld op deze pagina</div>
                        <div class="stat-value">
                            {{ number_format($rules->getCollection()->where('is_enabled', false)->count()) }}
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>Configuratie</h2>
                    <p>Beheer de metrics, drempelwaarden en evaluatieduur.</p>
                </div>
                <span class="muted small">{{ $rules->total() }} regels</span>
            </div>

            @if ($rules->isEmpty())
                <div class="empty">
                    <div class="empty-icon"><svg class="ico"><use href="#i-sliders"/></svg></div>
                    <h3>Er zijn nog geen alertregels</h3>
                    <p>Maak je eerste regel aan om CPU-, RAM- of schijfgebruik te bewaken.</p>
                    <a href="{{ route('alert-rules.create') }}" class="button button-primary">
                        <svg class="ico"><use href="#i-plus"/></svg> Alertregel aanmaken
                    </a>
                </div>
            @else
                <div class="rule-list">
                    @foreach ($rules as $rule)
                        @php
                            $metricLabels = [
                                'cpu_percent' => 'CPU-gebruik',
                                'memory_percent' => 'RAM-gebruik',
                                'disk_percent' => 'Schijfgebruik',
                            ];

                            $metricIcons = [
                                'cpu_percent' => 'i-cpu',
                                'memory_percent' => 'i-ram',
                                'disk_percent' => 'i-disk',
                            ];

                            $operatorLabels = [
                                '>=' => '≥',
                                '>' => '>',
                                '<=' => '≤',
                                '<' => '<',
                            ];

                            $metricLabel = $metricLabels[$rule->metric]
                                ?? str_replace('_', ' ', $rule->metric);

                            $metricIcon = $metricIcons[$rule->metric] ?? 'i-sliders';

                            $operator = $operatorLabels[$rule->operator]
                                ?? $rule->operator;

                            $unit = '%';
                        @endphp

                        <article @class(['rule-card', 'is-enabled' => $rule->is_enabled])>
                            <div class="rule-top">
                                <div class="rule-main">
                                    <span class="rule-icon">
                                        <svg class="ico"><use href="#{{ $metricIcon }}"/></svg>
                                    </span>

                                    <div>
                                        <h3 class="rule-name">{{ $rule->name }}</h3>
                                        <div class="rule-description">
                                            {{ $metricLabel }}
                                            · {{ $rule->alerts_count }} geregistreerde alerts
                                        </div>
                                    </div>
                                </div>

                                <span @class([
                                    'badge',
                                    'badge-enabled' => $rule->is_enabled,
                                    'badge-disabled' => ! $rule->is_enabled,
                                ])>
                                    {{ $rule->is_enabled ? 'Ingeschakeld' : 'Uitgeschakeld' }}
                                </span>
                            </div>

                            <div class="rule-details">
                                <div>
                                    <div class="detail-label">Voorwaarde</div>
                                    <div class="detail-value">
                                        {{ $operator }}
                                        {{ number_format($rule->threshold, 1, ',', '.') }}{{ $unit }}
                                    </div>
                                </div>

                                <div>
                                    <div class="detail-label">Vereiste duur</div>
                                    <div class="detail-value">{{ $rule->duration_minutes }} minuten</div>
                                </div>

                                <div>
                                    <div class="detail-label">Metric</div>
                                    <div class="detail-value">{{ $metricLabel }}</div>
                                </div>
                            </div>

                            <div class="rule-actions">
                                <a href="{{ route('alert-rules.edit', $rule) }}" class="button button-sm">
                                    <svg class="ico"><use href="#i-edit"/></svg> Bewerken
                                </a>

                                <form
                                    method="POST"
                                    action="{{ route('alert-rules.destroy', $rule) }}"
                                    onsubmit="return confirm('Weet je zeker dat je deze alertregel en de bijbehorende alertgeschiedenis wilt verwijderen?');"
                                >
                                    @csrf
                                    @method('DELETE')

                                    <button class="button button-sm button-danger" type="submit">
                                        <svg class="ico"><use href="#i-trash"/></svg> Verwijderen
                                    </button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($rules->hasPages())
                    <div class="pagination">
                        <span>
                            Resultaten {{ $rules->firstItem() }}–{{ $rules->lastItem() }}
                            van {{ $rules->total() }}
                        </span>

                        <div class="pagination-links">
                            @if ($rules->onFirstPage())
                                <span aria-disabled="true">← Vorige</span>
                            @else
                                <a href="{{ $rules->previousPageUrl() }}">← Vorige</a>
                            @endif

                            @if ($rules->hasMorePages())
                                <a href="{{ $rules->nextPageUrl() }}">Volgende →</a>
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
            <span>Alertregelbeheer</span>
        </footer>
    </main>
</div>
</body>
</html>
