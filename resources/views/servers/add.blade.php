@php use App\Support\Time; @endphp

<x-layouts.app title="Server toevoegen">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('servers.index') }}">Servers</a> <span>›</span> Toevoegen
    </x-slot:breadcrumb>
    <x-slot:subtitle>Koppel een Linux-server met één commando, of registreer hem handmatig.</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.index') }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug</a>
    </x-slot:actions>

    <section class="panel">
        <h2>Linux (Ubuntu 22.04+ of Debian 12+, amd64 of arm64)</h2>

        @if ($urlProblem)
            <div class="error" role="alert">
                <strong>Koppelen met één commando is uitgeschakeld.</strong>
                <p class="small" style="margin:6px 0 0">{{ $urlProblem }}</p>
            </div>
        @elseif (! $isAdmin)
            <p class="muted">Alleen beheerders van dit team kunnen een server koppelen. Vraag een beheerder om een koppeltoken aan te maken.</p>
        @else
            <div class="callout warning" role="note" style="margin-bottom:14px">
                <strong>Dit commando installeert software als root op je server.</strong>
                Het maakt de gebruiker <code>serverscope</code> aan, installeert de agent in <code>/opt/serverscope-agent</code> en start de systemd-service <code>serverscope-agent</code>.
                <a href="{{ $scriptUrl }}" target="_blank" rel="noopener">Bekijk install.sh eerst</a>.
            </div>

            @if ($newToken)
                <ol class="steps">
                    <li>
                        <strong>Draai dit commando op de server</strong> (als gebruiker met sudo-rechten):
                        <div class="secret-row" style="margin-top:6px">
                            <input id="install-command" class="input mono" type="text" readonly value="{{ $command }}" aria-label="Installatiecommando">
                            <button class="button" type="button" onclick="navigator.clipboard.writeText(document.getElementById('install-command').value).then(()=>this.textContent='Gekopieerd',()=>document.getElementById('install-command').select())">Kopiëren</button>
                        </div>
                    </li>
                    <li>
                        <strong>Plak het koppeltoken als het script erom vraagt.</strong>
                        Het token staat bewust niet in het commando: zo komt het niet in je shell-geschiedenis of in de proceslijst.
                        <x-secret-token :token="$newToken" id="enrollment-token" title="Koppeltoken (eenmalig, {{ $ttl }} minuten geldig)" />
                    </li>
                </ol>

                <div class="enroll-status" data-status-url="{{ route('enrollment-tokens.status', $newTokenId) }}" role="status" aria-live="polite">
                    <span class="connection-dot" data-dot></span>
                    <span data-text>Wachten op agent…</span>
                    <a data-link hidden href="#">Naar de server</a>
                </div>
                <script>
                    (() => {
                        const box = document.querySelector('.enroll-status');
                        const text = box.querySelector('[data-text]');
                        const link = box.querySelector('[data-link]');
                        const labels = { waiting: 'Wachten op agent…', enrolled: 'Server aangemaakt, wachten op de eerste meting…', connected: 'Verbonden', expired: 'Het koppeltoken is verlopen. Maak een nieuw token aan.', revoked: 'Het koppeltoken is ingetrokken.' };
                        const poll = async () => {
                            try {
                                const response = await fetch(box.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                                if (!response.ok) throw new Error();
                                const data = await response.json();
                                text.textContent = labels[data.state] || data.state;
                                if (data.server_url) { link.href = data.server_url; link.textContent = 'Naar ' + data.server_name; link.hidden = false; }
                                if (['connected', 'expired', 'revoked'].includes(data.state)) return;
                            } catch (e) {
                                text.textContent = 'Status kon niet worden opgehaald; ververs de pagina.';
                            }
                            setTimeout(poll, 3000);
                        };
                        poll();
                    })();
                </script>
            @else
                <form method="POST" action="{{ route('enrollment-tokens.store') }}" class="inline-form">
                    @csrf
                    <label for="label" class="sr-only">Omschrijving (optioneel)</label>
                    <input id="label" class="input" type="text" name="label" maxlength="100" placeholder="Omschrijving (optioneel), bijv. web-01">
                    <button type="submit" class="button button-primary">Koppeltoken aanmaken</button>
                </form>
                <p class="small muted">Een koppeltoken is {{ $ttl }} minuten geldig en werkt één keer. De server verschijnt daarna automatisch in dit team.</p>
            @endif

            <p class="small muted" style="margin-top:14px">
                Het script controleert de SHA-256-checksum van het agentpakket. Die checksum komt van dezelfde server als het pakket: hij beschermt tegen een kapotte of afgebroken download, niet tegen een gehackte ServerScope-server. Ondertekende releases volgen later.
                Voor automatisering kan het token ook in de omgevingsvariabele <code>SERVERSCOPE_ENROLL_TOKEN</code> staan; zet die dan niet in een getypt commando.
            </p>
        @endif
    </section>

    @if ($isAdmin && $pending->isNotEmpty())
        <section class="panel">
            <h2>Openstaande koppeltokens</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Token</th><th>Omschrijving</th><th>Aangemaakt door</th><th>Verloopt</th><th><span class="sr-only">Acties</span></th></tr></thead>
                    <tbody>
                        @foreach ($pending as $token)
                            <tr>
                                <td class="mono">{{ $token->prefix }}…</td>
                                <td>{{ $token->label ?? '—' }}</td>
                                <td>{{ $token->creator?->name ?? '—' }}</td>
                                <td>{{ Time::format($token->expires_at, 'd-m-Y H:i') }}</td>
                                <td>
                                    <form method="POST" action="{{ route('enrollment-tokens.destroy', $token) }}" class="inline-form" data-confirm="Koppeltoken {{ $token->prefix }}… intrekken?">
                                        @csrf @method('DELETE')
                                        <button class="button button-sm button-danger" type="submit">Intrekken</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($isAdmin && $recent->isNotEmpty())
        <section class="panel">
            <h2>Recent gekoppeld</h2>
            <ul class="plain-list">
                @foreach ($recent as $token)
                    <li>
                        @if ($token->server)
                            <a href="{{ route('servers.show', $token->server) }}">{{ $token->server->name }}</a>
                        @else
                            Verwijderde server
                        @endif
                        <span class="muted small">· {{ Time::format($token->used_at, 'd-m-Y H:i') }} · {{ $token->server?->last_seen_at ? 'verbonden' : 'wacht op eerste meting' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="panel">
        <h2>Andere manieren</h2>
        <ul class="plain-list">
            @if ($isAdmin)
                <li><a href="{{ route('servers.create') }}">Handmatig registreren</a>: je krijgt een agenttoken en installeert de agent zelf (zie de installatie-instructie van de agent).</li>
            @endif
            <li>Containers zonder Python of systemd: de Bash-agent (zie de agentdocumentatie).</li>
        </ul>
    </section>
</x-layouts.app>
