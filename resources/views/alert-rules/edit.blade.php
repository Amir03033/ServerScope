<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Alertregel bewerken — ServerScope</title>

    <style>
        :root {
            color-scheme: dark;
            --bg: #070c17;
            --panel: #0d1627;
            --border: #1f2e47;
            --text: #edf4ff;
            --muted: #8397b1;
            --cyan: #38bdf8;
            --green: #4ade80;
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

        .account { margin: -10px 0 18px; color: var(--muted); font-size: 11px; overflow-wrap: anywhere; }

        .notice {
            margin-bottom: 16px; padding: 14px 16px;
            border: 1px solid #7f1d1d; border-radius: 10px;
            background: #2a121a; color: var(--red);
        }
        .notice ul { margin: 8px 0 0; padding-left: 18px; }

        .panel {
            max-width: 850px; padding: 22px;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--panel);
        }

        .panel-head { display: flex; align-items: center; gap: 13px; margin-bottom: 22px; }
        .panel-icon {
            display: grid; place-items: center;
            width: 44px; height: 44px; flex: 0 0 44px;
            border: 1px solid color-mix(in srgb, var(--cyan) 45%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, var(--cyan) 14%, #0b1424);
            color: var(--cyan); font-size: 22px;
        }
        .panel-head h2 { margin: 0; font-size: 17px; overflow-wrap: anywhere; }
        .panel-head p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }

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
            .panel { padding: 16px; }
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
    <symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></symbol>
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
            <a href="{{ route('alert-rules.index') }}" class="nav-item active">
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
                    <svg class="ico"><use href="#i-home"/></svg> <span>›</span> Alertregels <span>›</span> Bewerken
                </div>
                <h1 class="page-title">Alertregel bewerken</h1>
                <p class="page-subtitle">Pas de configuratie van deze alertregel aan.</p>
            </div>

            <div class="actions">
                <a href="{{ route('alert-rules.index') }}" class="button">
                    <svg class="ico"><use href="#i-arrow-left"/></svg> Terug naar regels
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button" type="submit">Uitloggen</button>
                </form>
            </div>
        </header>

        <p class="account">Ingelogd als {{ auth()->user()->email }}</p>

        @if ($errors->any())
            <div class="notice" role="alert">
                <strong>Controleer de ingevoerde gegevens.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="panel">
            <div class="panel-head">
                <span class="panel-icon"><svg class="ico"><use href="#i-edit"/></svg></span>
                <div>
                    <h2>{{ $rule->name }}</h2>
                    <p>Werk de voorwaarden en de status van de regel bij.</p>
                </div>
            </div>

            @include('alert-rules.form', ['rule' => $rule])
        </section>

        <footer class="footer">
            <span>ServerScope · Infrastructure monitoring</span>
            <span>Alertregel bewerken</span>
        </footer>
    </main>
</div>
</body>
</html>
