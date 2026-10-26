@php
    use App\Models\Domain;
    use App\Support\Time;

    $status = $domain->trashed() ? 'closed' : $domain->displayStatus();
    $sslDays = $domain->sslDaysRemaining();
    $expDays = $domain->expiry_status === 'verified' ? $domain->expiryDaysRemaining() : null;
    $pct = fn (?float $v, int $d = 2) => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
    $ms = fn (?float $v) => $v === null ? '—' : number_format($v, 0, ',', '.').' ms';

    $chartPoints = array_map(fn ($p) => [
        'label' => Time::format(\Carbon\Carbon::parse($p['at']), 'H:i'),
        'value' => $p['avg_ms'],
        'flag' => $p['failures'] > 0,
    ], $series);

    $expiryLabels = [
        'verified' => 'Geverifieerd via RDAP',
        'unavailable' => 'Niet beschikbaar',
        'unsupported' => 'Niet ondersteund',
        'not_found' => 'Niet gevonden',
        'rate_limited' => 'Tijdelijk beperkt',
        'error' => 'Opvragen mislukt',
        'unknown' => 'Nog niet opgevraagd',
    ];

    $sslLabels = [
        'valid' => 'Geldig', 'expiring' => 'Verloopt binnenkort', 'expired' => 'Verlopen',
        'not_yet_valid' => 'Nog niet geldig', 'hostname_mismatch' => 'Hostname komt niet overeen',
        'untrusted' => 'Keten niet vertrouwd', 'self_signed' => 'Zelfondertekend',
        'handshake_failed' => 'Handshake mislukt', 'connection_failed' => 'Geen verbinding', 'unknown' => 'Nog niet gecontroleerd',
    ];

    $sslTone = match (true) {
        in_array($domain->ssl_status, Domain::SSL_INVALID_STATES, true) => 'critical',
        $domain->ssl_status === 'expiring' => 'warning',
        $domain->ssl_status === 'valid' => 'valid',
        default => 'unknown',
    };
@endphp

<x-layouts.app :title="$domain->displayName()">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('domains.index') }}">Domeinen</a> <span>›</span> {{ $domain->displayName() }}
    </x-slot:breadcrumb>
    <x-slot:subtitle>
        <a href="{{ $domain->url() }}" target="_blank" rel="noopener noreferrer nofollow" class="break">{{ $domain->url() }}</a>
        @if ($domain->displayName() !== $domain->name) · <span class="mono">{{ $domain->name }}</span> @endif
    </x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('domains.index') }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Overzicht</a>
        @can('admin')
            @unless ($domain->trashed())
                @if ($domain->is_active)
                    <form method="POST" action="{{ route('domains.check', $domain) }}" class="inline-form">
                        @csrf
                        <button class="button" type="submit"><svg class="ico"><use href="#i-refresh"/></svg> Nu controleren</button>
                    </form>
                    <form method="POST" action="{{ route('domains.pause', $domain) }}" class="inline-form">
                        @csrf
                        <button class="button" type="submit"><svg class="ico"><use href="#i-pause"/></svg> Pauzeren</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('domains.resume', $domain) }}" class="inline-form">
                        @csrf
                        <button class="button button-primary" type="submit"><svg class="ico"><use href="#i-play"/></svg> Hervatten</button>
                    </form>
                @endif
                <a href="{{ route('domains.edit', $domain) }}" class="button"><svg class="ico"><use href="#i-edit"/></svg> Bewerken</a>
            @endunless
        @endcan
    </x-slot:actions>

    @if ($domain->trashed())
        <div class="flash warning" role="status">
            <svg class="ico"><use href="#i-trash"/></svg>
            <div>
                <strong>Dit domein is verwijderd op {{ Time::format($domain->deleted_at) }}.</strong> Er worden geen controles meer uitgevoerd.
                @can('admin')
                    <div class="actions" style="justify-content:flex-start;margin-top:10px">
                        <form method="POST" action="{{ route('domains.restore', $domain) }}" class="inline-form">@csrf<button class="button button-sm" type="submit">Herstellen</button></form>
                        <form method="POST" action="{{ route('domains.force-destroy', $domain) }}" class="inline-form" data-confirm="Domein en alle controlegeschiedenis permanent verwijderen?">
                            @csrf @method('DELETE')
                            <input name="confirm_name" class="input" style="width:auto;min-height:32px" placeholder="Typ '{{ $domain->name }}'" required aria-label="Bevestig domeinnaam">
                            <button class="button button-sm button-danger" type="submit">Permanent verwijderen</button>
                        </form>
                    </div>
                @endcan
            </div>
        </div>
    @elseif (! $domain->is_active)
        <div class="flash info" role="status"><svg class="ico"><use href="#i-pause"/></svg><div>Monitoring is gepauzeerd sinds {{ Time::format($domain->paused_at) }}. Er worden geen controles uitgevoerd.</div></div>
    @elseif ($domain->isInMaintenance())
        <div class="flash info" role="status"><svg class="ico"><use href="#i-wrench"/></svg><div><strong>Onderhoud</strong> {{ $domain->maintenance_ends_at ? 'tot '.Time::format($domain->maintenance_ends_at) : 'zonder eindtijd' }}. Controles lopen door, maar er worden geen incidenten aangemaakt. {{ $domain->maintenance_reason }}</div></div>
    @endif

    <section class="kpis">
        <div class="kpi tone-{{ ['healthy' => 'green', 'warning' => 'yellow', 'critical' => 'red', 'paused' => 'purple', 'maintenance' => 'purple'][$status] ?? 'muted' }}">
            <span class="kpi-icon"><svg class="ico"><use href="#i-pulse"/></svg></span>
            <div>
                <div class="kpi-label">Status</div>
                <div class="kpi-value" style="font-size:20px">{{ $domain->trashed() ? 'Verwijderd' : (Domain::STATUS_LABELS[$status] ?? $status) }}</div>
                <div class="kpi-caption">Laatste controle {{ Time::relative($domain->http_last_checked_at ?? $domain->dns_last_checked_at) }}</div>
            </div>
        </div>
        <div class="kpi tone-cyan">
            <span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span>
            <div>
                <div class="kpi-label">Uptime 30 dagen</div>
                <div class="kpi-value">{{ $pct($stats['30d']['uptime']) }}</div>
                <div class="kpi-caption">Dekking {{ $pct($stats['30d']['coverage'], 1) }} · {{ $stats['30d']['checks'] }} controles</div>
            </div>
        </div>
        <div class="kpi tone-purple">
            <span class="kpi-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
            <div>
                <div class="kpi-label">Responstijd (24 uur)</div>
                <div class="kpi-value">{{ $ms($stats['24h']['avg_ms']) }}</div>
                <div class="kpi-caption">Laatste {{ $ms($domain->http_last_response_ms) }} · max {{ $ms($stats['24h']['max_ms']) }}</div>
            </div>
        </div>
        <div class="kpi tone-{{ $sslTone === 'critical' ? 'red' : ($sslTone === 'warning' ? 'yellow' : 'green') }}">
            <span class="kpi-icon"><svg class="ico"><use href="#i-lock"/></svg></span>
            <div>
                <div class="kpi-label">Certificaat</div>
                <div class="kpi-value">{{ $sslDays === null ? '—' : ($sslDays < 0 ? 'Verlopen' : $sslDays.' d') }}</div>
                <div class="kpi-caption">{{ $domain->ssl_enabled ? ($sslLabels[$domain->ssl_status] ?? $domain->ssl_status) : 'Niet gecontroleerd' }}</div>
            </div>
        </div>
        <div class="kpi tone-{{ $expDays !== null && $expDays < 0 ? 'red' : ($expDays !== null && $expDays <= max($domain->expiryWarningDays()) ? 'yellow' : 'cyan') }}">
            <span class="kpi-icon"><svg class="ico"><use href="#i-calendar"/></svg></span>
            <div>
                <div class="kpi-label">Registratie</div>
                <div class="kpi-value">{{ $expDays === null ? '—' : ($expDays < 0 ? 'Verlopen' : $expDays.' d') }}</div>
                <div class="kpi-caption">{{ $domain->expiry_enabled ? ($expiryLabels[$domain->expiry_status] ?? $domain->expiry_status) : 'Niet gecontroleerd' }}</div>
            </div>
        </div>
    </section>

    <div class="grid-2">
        <section class="panel">
            <div class="section-heading">
                <div><h2>HTTP-beschikbaarheid</h2><p>Gemiddelde responstijd per half uur, afgelopen 24 uur. Rode banden: mislukte controles.</p></div>
                @if ($domain->http_enabled)<x-status :status="$domain->http_status" />@else<span class="chip">Uit</span>@endif
            </div>
            <x-line-chart :points="$chartPoints" unit=" ms" label="Responstijd afgelopen 24 uur" />

            <h3 class="small muted" style="margin:16px 0 6px;font-weight:600">Uptime per dag (30 dagen)</h3>
            <div class="strip" role="img" aria-label="Uptime per dag over 30 dagen">
                @foreach ($daily as $day)
                    <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
                       title="{{ $day['date'] }}: {{ $day['uptime'] === null ? 'geen metingen' : number_format($day['uptime'], 2, ',', '.').'% ('.$day['checks'].' controles)' }}"></i>
                @endforeach
            </div>
            <p class="small muted" style="margin:6px 0 0">Grijs = geen metingen (telt niet als beschikbaar).</p>

            <dl class="dl" style="margin-top:14px">
                <dt>Uptime 24 uur / 7 dagen</dt><dd>{{ $pct($stats['24h']['uptime']) }} / {{ $pct($stats['7d']['uptime']) }}</dd>
                <dt>Waargenomen downtime (30 d)</dt><dd>{{ $stats['30d']['downtime_minutes'] === null ? '—' : $stats['30d']['downtime_minutes'].' min' }}</dd>
                <dt>Laatste statuscode</dt><dd>{{ $domain->http_last_status_code ?? '—' }}</dd>
                <dt>Opeenvolgende fouten</dt><dd>{{ $domain->http_consecutive_failures }} (drempel {{ $domain->failure_threshold }})</dd>
                <dt>Laatste fout</dt><dd>{{ $domain->http_last_error ?? '—' }}</dd>
                <dt>Interval / time-out</dt><dd>elke {{ $domain->check_interval_minutes }} min / {{ $domain->timeout_seconds }} s</dd>
                <dt>Verwacht</dt><dd>status {{ $domain->expected_status_codes ?: '200-399' }}@if ($domain->expected_content), inhoud “{{ $domain->expected_content }}”@endif</dd>
            </dl>
        </section>

        <div class="stack">
            <section class="panel">
                <div class="section-heading">
                    <div><h2>SSL/TLS-certificaat</h2><p>Laatst gecontroleerd {{ Time::relative($domain->ssl_last_checked_at) }}.</p></div>
                    @if ($domain->ssl_enabled)<x-status :status="$sslTone" :label="$sslLabels[$domain->ssl_status] ?? $domain->ssl_status" />@else<span class="chip">Uit</span>@endif
                </div>
                @if ($domain->ssl_message)<p class="small {{ $sslTone === 'critical' ? 'red' : 'muted' }}" style="margin:-6px 0 10px">{{ $domain->ssl_message }}</p>@endif
                <dl class="dl">
                    <dt>Onderwerp</dt><dd>{{ $domain->ssl_subject ?? '—' }}</dd>
                    <dt>Uitgever</dt><dd>{{ $domain->ssl_issuer ?? '—' }}</dd>
                    <dt>Geldig</dt><dd>{{ $domain->ssl_valid_from ? Time::format($domain->ssl_valid_from, 'd-m-Y').' t/m '.Time::format($domain->ssl_valid_to, 'd-m-Y H:i') : '—' }}</dd>
                    @php $sslDetails = $latestSsl?->details ?? []; @endphp
                    <dt>SAN</dt><dd>{{ implode(', ', array_slice($sslDetails['san'] ?? [], 0, 10)) ?: '—' }}</dd>
                    <dt>Protocol / cipher</dt><dd>{{ ($sslDetails['protocol'] ?? '—').' / '.($sslDetails['cipher'] ?? '—') }}</dd>
                    <dt>SHA-256</dt><dd class="mono" style="font-size:10px">{{ $domain->ssl_fingerprint ?? '—' }}</dd>
                    <dt>Waarschuwingen</dt><dd>{{ implode(', ', $domain->sslWarningDays()) }} dagen vooraf</dd>
                </dl>
            </section>

            <section class="panel">
                <div class="section-heading">
                    <div><h2>Domeinregistratie</h2><p>Bron en tijdstip van de laatste opvraging staan hieronder.</p></div>
                    @if ($domain->expiry_enabled)
                        <x-status :status="$domain->expiry_status === 'verified' ? ($expDays < 0 ? 'expired' : ($expDays <= max($domain->expiryWarningDays()) ? 'expiring' : 'valid')) : 'unknown'" :label="$expiryLabels[$domain->expiry_status] ?? $domain->expiry_status" />
                    @else<span class="chip">Uit</span>@endif
                </div>
                @if ($domain->expiry_status !== 'verified' && $domain->expiry_message)
                    <p class="small muted" style="margin:-6px 0 10px">{{ $domain->expiry_message }}</p>
                @endif
                <dl class="dl">
                    <dt>Vervaldatum</dt><dd>{{ $domain->registration_expires_at ? Time::format($domain->registration_expires_at, 'd-m-Y') : 'Onbekend — er wordt geen datum geschat' }}</dd>
                    <dt>Geregistreerd</dt><dd>{{ $domain->registered_at ? Time::format($domain->registered_at, 'd-m-Y') : '—' }}</dd>
                    <dt>Registrar</dt><dd>{{ $domain->registrar ?? '—' }}</dd>
                    <dt>Geregistreerd domein</dt><dd>{{ $domain->registrable_domain ?? '—' }}</dd>
                    <dt>Bron</dt><dd class="break">{{ $domain->expiry_source ?? '—' }}</dd>
                    <dt>Opgevraagd</dt><dd>{{ $domain->expiry_checked_at ? Time::format($domain->expiry_checked_at) : 'Nog niet' }}@if ($domain->expiry_next_check_at) · volgende {{ Time::format($domain->expiry_next_check_at) }}@endif</dd>
                </dl>
            </section>
        </div>
    </div>

    <div class="grid-2" style="margin-top:14px">
        <section class="panel">
            <div class="section-heading">
                <div><h2>DNS-records</h2><p>Laatste resultaat {{ Time::relative($domain->dns_last_checked_at) }}@if ($domain->dns_last_changed_at) · laatst gewijzigd {{ Time::format($domain->dns_last_changed_at) }}@endif.</p></div>
                @if ($domain->dns_enabled)<x-status :status="['ok' => 'healthy', 'failed' => 'critical'][$domain->dns_status] ?? 'unknown'" :label="['ok' => 'Resolutie OK', 'failed' => 'Resolutie mislukt'][$domain->dns_status] ?? 'Onbekend'" />@else<span class="chip">Uit</span>@endif
            </div>
            @if ($domain->dns_message)<p class="small {{ $domain->dns_status === 'failed' ? 'red' : 'muted' }}" style="margin:-6px 0 10px">{{ $domain->dns_message }}</p>@endif
            @if (collect($dnsRecords)->filter()->isEmpty())
                <x-empty-state icon="i-dns" title="Nog geen DNS-gegevens">De eerste DNS-controle is nog niet uitgevoerd.</x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Type</th><th>Waarden</th><th>Sinds</th></tr></thead>
                        <tbody>
                            @foreach ($dnsRecords as $recordType => $snapshot)
                                <tr>
                                    <td><span class="chip">{{ $recordType }}</span></td>
                                    <td class="mono break">
                                        @forelse ($snapshot?->records ?? [] as $value)
                                            <div>{{ \Illuminate\Support\Str::limit($value, 180) }}</div>
                                        @empty
                                            <span class="muted">—</span>
                                        @endforelse
                                    </td>
                                    <td class="nowrap small muted">{{ $snapshot ? Time::format($snapshot->first_seen_at, 'd-m-Y') : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="section-heading"><div><h2>DNS-wijzigingen</h2><p>Gedetecteerde wijzigingen ten opzichte van de vorige controle.</p></div></div>
            @if ($dnsChanges->isEmpty())
                <x-empty-state icon="i-history" title="Geen wijzigingen">Er zijn nog geen DNS-wijzigingen gedetecteerd. De eerste controle geldt als basislijn.</x-empty-state>
            @else
                <ul class="timeline">
                    @foreach ($dnsChanges as $change)
                        <li style="--c: {{ in_array($change->record_type, ['NS', 'A', 'AAAA', 'MX'], true) ? 'var(--yellow)' : 'var(--cyan)' }}">
                            <div class="when">{{ Time::format($change->detected_at) }}</div>
                            <div class="what"><span class="chip">{{ $change->record_type }}</span> @if ($change->record_type === 'NS')<strong class="yellow">Nameservers gewijzigd</strong>@endif</div>
                            <div class="mono small break" style="margin-top:4px">
                                @foreach ($change->added as $value)<div class="green">+ {{ \Illuminate\Support\Str::limit($value, 160) }}</div>@endforeach
                                @foreach ($change->removed as $value)<div class="red">− {{ \Illuminate\Support\Str::limit($value, 160) }}</div>@endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="panel" style="margin-top:14px">
        <div class="section-heading">
            <div><h2>Incidenten</h2><p>De laatste incidenten voor dit domein.</p></div>
            @if (Route::has('incidents.index'))<a class="button button-sm" href="{{ route('incidents.index', ['domain' => $domain->id]) }}">Alle incidenten</a>@endif
        </div>
        @if ($incidents->isEmpty())
            <x-empty-state icon="i-check" title="Geen incidenten">Er zijn nog geen incidenten voor dit domein geregistreerd.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Incident</th><th>Ernst</th><th>Status</th><th>Geopend</th><th>Duur</th></tr></thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td>
                                    @if (Route::has('incidents.show'))<a class="row-link" href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>@else{{ $incident->title }}@endif
                                    <span class="cell-sub">{{ $incident->sourceLabel() }}</span>
                                </td>
                                <td><x-severity :severity="$incident->severity" /></td>
                                <td><x-status :status="$incident->status" /></td>
                                <td class="nowrap">{{ Time::format($incident->opened_at) }}</td>
                                <td class="nowrap">{{ $incident->resolved_at ? \Carbon\CarbonInterval::seconds($incident->timeToResolve())->cascade()->locale('nl')->forHumans(['short' => true, 'parts' => 2]) : 'loopt' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel" style="margin-top:14px" id="checks">
        <div class="section-heading">
            <div><h2>Controlegeschiedenis</h2><p>Alle uitgevoerde controles, nieuwste eerst.</p></div>
        </div>
        <nav class="filters" aria-label="Filter op type">
            @foreach ([null => 'Alle', 'http' => 'HTTP', 'ssl' => 'SSL', 'dns' => 'DNS', 'expiry' => 'Registratie'] as $key => $label)
                <a href="{{ route('domains.show', ['domain' => $domain, 'type' => $key]) }}#checks" @class(['filter', 'active' => $type === $key])>{{ $label }}</a>
            @endforeach
        </nav>
        @if ($checks->isEmpty())
            <x-empty-state icon="i-clock" title="Nog geen controles">Controles verschijnen hier zodra de scheduler en queue-worker draaien.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Tijdstip</th><th>Type</th><th>Resultaat</th><th class="num">Tijd</th><th>Code</th><th>Melding</th></tr></thead>
                    <tbody>
                        @foreach ($checks as $check)
                            <tr>
                                <td class="nowrap">{{ Time::format($check->checked_at, 'd-m-Y H:i:s') }}</td>
                                <td><span class="chip">{{ strtoupper($check->type) }}</span></td>
                                <td><x-status :status="$check->success ? 'up' : 'down'" :label="str_replace('_', ' ', $check->result)" /></td>
                                <td class="num">{{ $ms($check->response_time_ms) }}</td>
                                <td>{{ $check->http_status_code ?? '' }}</td>
                                <td class="small break">{{ $check->message ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$checks" />
        @endif
    </section>

    <x-dependencies :resource="$domain" style="margin-top:14px" />
</x-layouts.app>
