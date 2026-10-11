<!DOCTYPE html>
<html lang="nl">
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ServerScope — Overview</title>

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

        button, input { font: inherit; }
        button { cursor: pointer; }

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
            color: var(--text);
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
            text-decoration: none;
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

        .main { min-width: 0; padding: 28px 34px 40px; }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .page-title {
            margin: 0;
            font-size: 26px;
            letter-spacing: -.7px;
        }

        .page-subtitle { margin: 5px 0 0; color: var(--muted); }

        .actions {
            display: flex;
            align-items: center;
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
            text-decoration: none;
            transition: background .15s ease;
        }

        .button:hover { background: #22314a; }

        .button-primary {
            border-color: #0891b2;
            background: #0e7490;
            color: #ecfeff;
        }

        .button-primary:hover { background: #155e75; }
        .button:disabled { cursor: wait; opacity: .65; }

        .account {
            margin: 20px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .section-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin: 30px 0 16px;
        }

        .section-heading h2 { margin: 0; font-size: 17px; }
        .section-heading p { margin: 4px 0 0; color: var(--muted); }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .stat {
            min-width: 0;
            padding: 19px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--panel);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
        }

        .stat-icon {
            display: grid;
            place-items: center;
            width: 31px;
            height: 31px;
            border-radius: 9px;
            background: #1e293b;
            color: var(--cyan);
        }

        .stat-value {
            margin-top: 14px;
            font-size: 29px;
            font-weight: 750;
            line-height: 1.1;
        }

        .online { color: var(--green); }
        .offline { color: var(--red); }
        .pending { color: var(--yellow); }

        .panel {
            margin-top: 24px;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--panel);
        }

        .register-panel[hidden] { display: none; }

        .registration-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin: 20px 0;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            color: #cbd5e1;
            font-size: 12px;
        }

        .field input, .token-input {
            width: 100%;
            min-width: 0;
            padding: 11px 12px;
            border: 1px solid #334155;
            border-radius: 8px;
            outline: none;
            background: #080d18;
            color: var(--text);
        }

        .field input:focus, .token-input:focus {
            border-color: #0891b2;
            box-shadow: 0 0 0 3px #0891b222;
        }

        .full-width { grid-column: 1 / -1; }

        .message {
            margin-top: 14px;
            color: #fca5a5;
            white-space: pre-line;
        }

        .token-area {
            margin-top: 18px;
            padding: 16px;
            border: 1px solid #854d0e;
            border-radius: 10px;
            background: #29200d;
        }

        .token-row {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .token-row .token-input { flex: 1; }
        .token-area[hidden] { display: none; }

        .server-list { display: grid; gap: 12px; }

        .server-card {
            padding: 19px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: #0c1422;
        }

        .server-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .server-name { margin: 0; font-size: 15px; }
        .server-host { margin-top: 3px; color: var(--muted); font-size: 12px; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 9px;
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

        .server-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 20px;
            margin-top: 13px;
            color: var(--muted);
            font-size: 12px;
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .metric-label {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .track {
            height: 6px;
            overflow: hidden;
            border-radius: 99px;
            background: #263246;
        }

        .fill {
            height: 100%;
            border-radius: inherit;
            background: var(--cyan);
        }

        .empty {
            padding: 48px 20px;
            border: 1px dashed #334155;
            border-radius: 11px;
            text-align: center;
        }

        .empty-icon {
            display: grid;
            place-items: center;
            width: 54px;
            height: 54px;
            margin: 0 auto 15px;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: #172235;
            color: var(--cyan);
            font-size: 24px;
        }

        .empty h3 { margin: 0; font-size: 16px; }
        .empty p { max-width: 390px; margin: 8px auto 18px; color: var(--muted); }

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

        @media (max-width: 1050px) {
            .layout { grid-template-columns: 190px minmax(0, 1fr); }
            .main { padding: 24px; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 700px) {
            .layout { display: block; }
            .sidebar { display: none; }
            .main { padding: 20px 14px 30px; }
            .topbar { align-items: flex-start; }
            .page-title { font-size: 22px; }
            .actions { flex-direction: column; align-items: stretch; }
            .actions .button { font-size: 12px; }
            .stats { gap: 9px; }
            .stat { padding: 14px; }
            .stat-value { font-size: 25px; }
            .registration-grid, .metrics { grid-template-columns: 1fr; }
            .full-width { grid-column: auto; }
            .panel { padding: 15px; }
            .token-row { flex-direction: column; }
            .footer { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">⌁</span>
            <span>ServerScope</span>
        </div>

        <div class="eyebrow">Workspace</div>
        <a href="{{ route('dashboard') }}" class="nav-item active">
            <span>▦</span> Overview
        </a>

        <div class="eyebrow">Infrastructure</div>
        <a href="#servers" class="nav-item">
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
                <h1 class="page-title">Overview</h1>
                <p class="page-subtitle">Je infrastructuur in één oogopslag.</p>
            </div>

            <div class="actions">
                @if (auth()->user()->is_admin)
                    <button class="button button-primary" id="open-register" type="button">
                        <span>＋</span> Server toevoegen
                    </button>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button" type="submit">Uitloggen</button>
                </form>
            </div>
        </header>

        <p class="account">
            Ingelogd als {{ auth()->user()->email }}
        </p>

        @if (auth()->user()->is_admin)
            <section class="panel register-panel" id="register-panel" hidden>
                <div class="section-heading" style="margin-top:0">
                    <div>
                        <h2>Nieuwe server registreren</h2>
                        <p>Voeg je Ubuntu VPS of een andere Linux-server toe.</p>
                    </div>
                    <button class="button" id="close-register" type="button">Sluiten</button>
                </div>

                <form id="register-server-form">
                    <div class="registration-grid">
                        <label class="field">
                            Servernaam *
                            <input name="name" maxlength="100" required placeholder="Production VPS">
                        </label>

                        <label class="field">
                            Hostname *
                            <input name="hostname" maxlength="255" required placeholder="server.example.com">
                        </label>

                        <label class="field">
                            IP-adres
                            <input name="ip_address" maxlength="45" placeholder="203.0.113.10">
                        </label>

                        <label class="field">
                            Besturingssysteem
                            <input name="operating_system" maxlength="100" placeholder="Ubuntu 24.04">
                        </label>

                        <label class="field full-width">
                            Naam agentcredential
                            <input name="credential_name" maxlength="100" required value="Primary agent">
                        </label>
                    </div>

                    <button class="button button-primary" id="register-server-button" type="submit">
                        Server registreren
                    </button>

                    <p id="registration-error" class="message" role="alert" hidden></p>
                </form>

                <div id="registration-success" class="token-area" hidden>
                    <strong>Server geregistreerd — bewaar het agenttoken</strong>
                    <p id="registered-server-details" class="muted small"></p>
                    <p class="small">
                        Dit token wordt maar één keer getoond. Bewaar het veilig en deel het niet.
                    </p>

                    <div class="token-row">
                        <input id="registered-agent-token" class="token-input"
                               type="password" readonly autocomplete="off"
                               aria-label="Eenmalig agenttoken">

                        <button class="button" id="toggle-token" type="button">Tonen</button>
                        <button class="button" id="copy-token" type="button">Kopiëren</button>
                    </div>

                    <p id="copy-status" class="muted small" role="status"></p>

                    <button class="button button-primary" id="finish-registration" type="button">
                        Token veilig opgeslagen — klaar
                    </button>
                </div>
            </section>
        @endif

        <div class="section-heading">
            <div>
                <h2>Infrastructure overview</h2>
                <p>Actuele status van je geregistreerde servers.</p>
            </div>
        </div>

        <section class="stats" aria-label="Serverstatistieken">
            <article class="stat">
                <div class="stat-top">
                    <span>Totaal servers</span>
                    <span class="stat-icon">▤</span>
                </div>
                <div class="stat-value">{{ $serverCount }}</div>
            </article>

            <article class="stat">
                <div class="stat-top">
                    <span>Online</span>
                    <span class="stat-icon online">●</span>
                </div>
                <div class="stat-value online">{{ $onlineCount }}</div>
            </article>

            <article class="stat">
                <div class="stat-top">
                    <span>Offline</span>
                    <span class="stat-icon offline">●</span>
                </div>
                <div class="stat-value offline">{{ $offlineCount }}</div>
            </article>

            <article class="stat">
                <div class="stat-top">
                    <span>In afwachting</span>
                    <span class="stat-icon pending">◷</span>
                </div>
                <div class="stat-value pending">{{ $pendingCount }}</div>
            </article>
        </section>

        <section class="panel" id="servers">
            <div class="section-heading" style="margin-top:0">
                <div>
                    <h2>Servers</h2>
                    <p>Beheer en bekijk je geregistreerde infrastructuur.</p>
                </div>
                <span class="muted small">{{ $serverCount }} totaal</span>
            </div>

            @if ($servers->isEmpty())
                <div class="empty">
                    <div class="empty-icon">▤</div>
                    <h3>Nog geen servers geregistreerd</h3>
                    <p>
                        Voeg je Ubuntu VPS toe om CPU, geheugen, schijven en uptime te monitoren.
                    </p>

                    @if (auth()->user()->is_admin)
                        <button class="button button-primary" id="empty-add-server" type="button">
                            ＋ Eerste server toevoegen
                        </button>
                    @endif
                </div>
            @else
                <div class="server-list">
                    @foreach ($servers as $server)
                        @php
                            $sample = $server->latestMetricSample;
                            $memoryTotal = $sample?->memory_total_bytes ?? 0;
                            $memoryUsed = $sample?->memory_used_bytes ?? 0;
                            $memoryPercent = $memoryTotal > 0
                                ? min(100, max(0, $memoryUsed / $memoryTotal * 100))
                                : null;
                            $cpuPercent = $sample?->cpu_percent;
                        @endphp

                        <article class="server-card">
                            <div class="server-heading">
                                <div>
                                    <h3 class="server-name">{{ $server->name }}</h3>
                                    <div class="server-host">{{ $server->hostname }}</div>

                                    <div class="server-meta">
                                        <span>Laatste contact: {{ $server->last_seen_at?->diffForHumans() ?? 'Nog nooit' }}</span>
                                        <span>Agent: {{ $server->agent_version ?? 'Onbekend' }}</span>
                                        @if ($server->operating_system)
                                            <span>{{ $server->operating_system }}</span>
                                        @endif
                                    </div>
                                </div>

                                <span class="badge {{ $server->display_status }}">
                                    @switch($server->display_status)
                                        @case('online')
                                            Online
                                            @break
                                        @case('offline')
                                            Offline
                                            @break
                                        @default
                                            Pending
                                    @endswitch
                                </span>
                            </div>

                            @if ($sample)
                                <div class="metrics">
                                    <div>
                                        <div class="metric-label">
                                            <span>CPU-gebruik</span>
                                            <strong>
                                                {{ $cpuPercent !== null ? number_format($cpuPercent, 1) . '%' : 'Geen data' }}
                                            </strong>
                                        </div>
                                        <div class="track">
                                            <div class="fill" style="width: {{ min(100, max(0, $cpuPercent ?? 0)) }}%"></div>
                                        </div>
                                    </div>

                                    <div>
                                        <div class="metric-label">
                                            <span>RAM-gebruik</span>
                                            <strong>
                                                {{ $memoryPercent !== null ? number_format($memoryPercent, 1) . '%' : 'Geen data' }}
                                            </strong>
                                        </div>
                                        <div class="track">
                                            <div class="fill" style="width: {{ $memoryPercent ?? 0 }}%"></div>
                                        </div>
                                    </div>
                                </div>

                                <p class="muted small">
                                    Meting ontvangen {{ $sample->collected_at?->diffForHumans() ?? 'onbekend tijdstip' }}.
                                </p>
                            @else
                                <p class="muted small">Er zijn nog geen meetgegevens voor deze server.</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <footer class="footer">
            <span>ServerScope · Infrastructure monitoring</span>
            <span>Ingelogd als {{ auth()->user()->email }}</span>
        </footer>
    </main>
</div>

@if (auth()->user()->is_admin)
    <script>
        (() => {
            const panel = document.getElementById('register-panel');
            const openButton = document.getElementById('open-register');
            const emptyButton = document.getElementById('empty-add-server');
            const closeButton = document.getElementById('close-register');
            const form = document.getElementById('register-server-form');
            const errorBox = document.getElementById('registration-error');
            const successBox = document.getElementById('registration-success');
            const submitButton = document.getElementById('register-server-button');
            const tokenInput = document.getElementById('registered-agent-token');
            const copyStatus = document.getElementById('copy-status');

            if (!panel || !form) return;

            function openPanel() {
                panel.hidden = false;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            openButton?.addEventListener('click', openPanel);
            emptyButton?.addEventListener('click', openPanel);

            closeButton?.addEventListener('click', () => {
                panel.hidden = true;
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                errorBox.hidden = true;
                errorBox.textContent = '';
                submitButton.disabled = true;
                submitButton.textContent = 'Registreren...';

                const data = Object.fromEntries(new FormData(form).entries());

                for (const key of ['ip_address', 'operating_system']) {
                    if (!data[key]) delete data[key];
                }

                try {
                    const response = await fetch(@json(route('servers.register')), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(data),
                    });

                    const result = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        const validationMessages = Object.values(result.errors ?? {}).flat().join('\n');
                        throw new Error(
                            validationMessages ||
                            result.message ||
                            `Registratie mislukt (HTTP ${response.status}).`
                        );
                    }

                    tokenInput.value = result.agent_token;
                    tokenInput.type = 'password';

                    document.getElementById('registered-server-details').textContent =
                        `${result.server.name} · ${result.server.hostname} · Pending`;

                    form.hidden = true;
                    successBox.hidden = false;
                    copyStatus.textContent = '';
                } catch (error) {
                    errorBox.textContent = error.message || 'Registreren is mislukt.';
                    errorBox.hidden = false;
                } finally {
                    submitButton.disabled = false;
                    submitButton.textContent = 'Server registreren';
                }
            });

            document.getElementById('toggle-token').addEventListener('click', (event) => {
                const showing = tokenInput.type === 'text';
                tokenInput.type = showing ? 'password' : 'text';
                event.currentTarget.textContent = showing ? 'Tonen' : 'Verbergen';
            });

            document.getElementById('copy-token').addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(tokenInput.value);
                    copyStatus.textContent = 'Token gekopieerd.';
                } catch {
                    tokenInput.type = 'text';
                    tokenInput.select();
                    copyStatus.textContent = 'Kopiëren lukte niet automatisch. Kopieer het token handmatig.';
                }
            });

            document.getElementById('finish-registration').addEventListener('click', () => {
                tokenInput.value = '';
                window.location.reload();
            });
        })();
    </script>
@endif
</body>
</html>
