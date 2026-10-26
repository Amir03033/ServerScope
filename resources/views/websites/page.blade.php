@php
    use App\Http\Requests\PageRequest;
    use App\Models\MonitoredPage;
    use App\Support\Time;

    $ms = fn (?float $v) => $v === null ? '—' : number_format($v, 0, ',', '.').' ms';
    $pct = fn (?float $v, int $d = 2) => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
    $display = $page->displayStatus();
    $isAdmin = auth()->user()->can('admin');

    // Average per 30-minute bucket for the chart; failures flagged.
    $buckets = [];
    $start = now()->subDay();
    for ($i = 0; $i < 48; $i++) { $buckets[$i] = ['sum' => 0, 'n' => 0, 'fail' => false]; }
    foreach ($series as $check) {
        $i = min(47, max(0, (int) floor($start->diffInSeconds($check->checked_at, false) / 1800)));
        if ($check->success && $check->response_time_ms !== null) { $buckets[$i]['sum'] += $check->response_time_ms; $buckets[$i]['n']++; }
        if (! $check->success) { $buckets[$i]['fail'] = true; }
    }
    $chartPoints = array_map(fn ($b, $i) => ['label' => Time::format($start->copy()->addMinutes($i * 30), 'H:i'), 'value' => $b['n'] ? round($b['sum'] / $b['n'], 1) : null, 'flag' => $b['fail']], $buckets, array_keys($buckets));
@endphp

<x-layouts.app :title="$page->displayName().' — '.$domain->displayName()">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> <a href="{{ route('websites.pages', $domain) }}">Pagina's</a> <span>›</span> {{ $page->displayName() }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $page->title ?: $page->displayName() }}</x-slot:pageTitle>
    <x-slot:subtitle><a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer nofollow" class="break">{{ $page->url }}</a></x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('websites.pages', $domain) }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Pagina's</a>
        @if ($isAdmin)
            @if ($page->isMonitored())
                <form method="POST" action="{{ route('pages.check', $page) }}" class="inline-form">@csrf<button class="button" type="submit"><svg class="ico"><use href="#i-refresh"/></svg> Nu controleren</button></form>
                <form method="POST" action="{{ route('pages.pause', $page) }}" class="inline-form">@csrf<button class="button" type="submit"><svg class="ico"><use href="#i-pause"/></svg> Pauzeren</button></form>
            @elseif ($page->approval_status === 'approved')
                <form method="POST" action="{{ route('pages.resume', $page) }}" class="inline-form">@csrf<button class="button button-primary" type="submit"><svg class="ico"><use href="#i-play"/></svg> Hervatten</button></form>
            @else
                <form method="POST" action="{{ route('websites.pages.bulk', $domain) }}" class="inline-form">@csrf<input type="hidden" name="action" value="approve"><input type="hidden" name="ids[]" value="{{ $page->id }}"><button class="button button-primary" type="submit">Goedkeuren en monitoren</button></form>
            @endif
            <a href="{{ route('pages.edit', $page) }}" class="button"><svg class="ico"><use href="#i-edit"/></svg> Bewerken</a>
        @endif
    </x-slot:actions>

    @if ($page->approval_status === 'pending')
        <div class="flash info" role="status"><svg class="ico"><use href="#i-search"/></svg><div>Deze pagina is ontdekt via {{ MonitoredPage::SOURCES[$page->source] ?? $page->source }} en wordt pas gecontroleerd na goedkeuring.</div></div>
    @elseif ($page->approval_status === 'rejected')
        <div class="flash info" role="status"><svg class="ico"><use href="#i-x"/></svg><div>Deze pagina is afgewezen en wordt niet gecontroleerd.</div></div>
    @elseif (! $page->is_active)
        <div class="flash info" role="status"><svg class="ico"><use href="#i-pause"/></svg><div>Monitoring is gepauzeerd sinds {{ Time::format($page->paused_at) }}. De geschiedenis blijft bewaard.</div></div>
    @endif

    <section class="kpis">
        <div class="kpi tone-{{ ['up' => 'green', 'degraded' => 'yellow', 'down' => 'red', 'paused' => 'purple'][$display] ?? 'muted' }}">
            <span class="kpi-icon"><svg class="ico"><use href="#i-pulse"/></svg></span>
            <div><div class="kpi-label">Status</div><div class="kpi-value" style="font-size:20px">{{ MonitoredPage::STATUS_LABELS[$display] ?? ['pending' => 'Wacht op goedkeuring', 'rejected' => 'Afgewezen', 'paused' => 'Gepauzeerd'][$display] ?? $display }}</div>
                <div class="kpi-caption">@if ($page->last_failure_type){{ MonitoredPage::FAILURE_LABELS[$page->last_failure_type] ?? $page->last_failure_type }} · @endif laatste controle {{ Time::relative($page->last_checked_at) }}</div></div>
        </div>
        <div class="kpi tone-cyan"><span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span><div><div class="kpi-label">Beschikbaarheid 30 dagen</div><div class="kpi-value">{{ $pct($stats30d['availability']) }}</div><div class="kpi-caption">{{ $stats30d['checks'] }} controles · doel {{ $pct($page->sla_target ?? $domain->sla_target ?? (float) config('serverscope.reports.default_target'), 2) }}</div></div></div>
        <div class="kpi tone-purple"><span class="kpi-icon"><svg class="ico"><use href="#i-clock"/></svg></span><div><div class="kpi-label">Responstijd 24 uur</div><div class="kpi-value">{{ $ms($stats24h['avg']) }}</div><div class="kpi-caption">@if ($stats24h['p95'] !== null)p95 {{ $ms($stats24h['p95']) }} · @endif laatste {{ $ms($page->last_response_ms) }}</div></div></div>
        <div class="kpi tone-{{ $page->tls_valid === false ? 'red' : 'green' }}"><span class="kpi-icon"><svg class="ico"><use href="#i-lock"/></svg></span><div><div class="kpi-label">TLS</div><div class="kpi-value" style="font-size:20px">{{ ! $page->isHttps() ? 'n.v.t.' : ($page->tls_valid === null ? '—' : ($page->tls_valid ? 'Geldig' : 'Fout')) }}</div><div class="kpi-caption">Certificaat: zie <a href="{{ route('domains.show', $domain) }}">domein</a></div></div></div>
    </section>

    <div class="grid-2 split">
        <div class="stack">
            <section class="panel">
                <div class="section-heading"><div><h2>Responstijd (24 uur)</h2><p>Gemiddelde per half uur; rode markeringen zijn mislukte controles.</p></div></div>
                <x-line-chart :points="$chartPoints" unit=" ms" label="Responstijd afgelopen 24 uur" />
                <h3 class="small muted" style="margin:16px 0 6px;font-weight:600">Beschikbaarheid per dag (30 dagen)</h3>
                <div class="strip" role="img" aria-label="Beschikbaarheid per dag">
                    @foreach ($daily as $day)
                        <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95]) title="{{ $day['date'] }}: {{ $day['uptime'] === null ? 'geen metingen' : number_format($day['uptime'], 2, ',', '.').'%' }}"></i>
                    @endforeach
                </div>
            </section>

            <section class="panel" id="checks">
                <div class="section-heading"><div><h2>Controlegeschiedenis</h2></div></div>
                <nav class="filters" aria-label="Filter op resultaat">
                    @foreach ([null => 'Alle', 'down' => 'Mislukt', 'degraded' => 'Traag', 'up' => 'Geslaagd'] as $key => $label)
                        <a href="{{ route('pages.show', [$page, 'result' => $key]) }}#checks" @class(['filter', 'active' => $result === $key])>{{ $label }}</a>
                    @endforeach
                </nav>
                @if ($checks->isEmpty())
                    <x-empty-state icon="i-clock" title="Nog geen controles">{{ $page->isMonitored() ? 'De eerste controle verschijnt hier zodra de scheduler en queue-worker draaien.' : 'Deze pagina wordt niet gecontroleerd.' }}</x-empty-state>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Tijdstip</th><th>Resultaat</th><th class="num">HTTP</th><th class="num">Tijd</th><th>Melding</th></tr></thead>
                            <tbody>
                                @foreach ($checks as $check)
                                    <tr>
                                        <td class="nowrap">{{ Time::format($check->checked_at, 'd-m-Y H:i:s') }}</td>
                                        <td><x-status :status="$check->result" :label="$check->failure_type ? (MonitoredPage::FAILURE_LABELS[$check->failure_type] ?? $check->failure_type) : 'OK'" />@if ($check->content_changed)<span class="cell-sub">inhoud gewijzigd</span>@endif</td>
                                        <td class="num">{{ $check->http_status_code ?? '—' }}</td>
                                        <td class="num">{{ $ms($check->response_time_ms) }}</td>
                                        <td class="small break">{{ $check->message }}@if (! empty($check->details['redirects']))<span class="cell-sub">Redirects: @foreach ($check->details['redirects'] as $hop){{ $hop['status'] }} {{ $hop['url'] }} → @endforeach{{ $check->details['final_url'] ?? '' }}</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-pagination :paginator="$checks" />
                @endif
            </section>

            <section class="panel">
                <div class="section-heading"><div><h2>Inhouds- en headerwijzigingen</h2><p>Wijzigingen zijn nooit automatisch een incident. Bevestig verwachte wijzigingen.</p></div></div>
                @if ($changes->isEmpty())
                    <x-empty-state icon="i-history" title="Geen wijzigingen">{{ $page->content_monitoring || $page->monitored_headers ? 'Er zijn nog geen wijzigingen gedetecteerd.' : 'Inhouds- en headermonitoring staan uit voor deze pagina.' }}</x-empty-state>
                @else
                    <ul class="timeline">
                        @foreach ($changes as $change)
                            <li style="--c: {{ $change->significance === 'significant' ? 'var(--yellow)' : 'var(--muted)' }}">
                                <div class="when">{{ Time::format($change->detected_at, 'd-m-Y H:i:s') }} · {{ $change->kind === 'headers' ? 'Headers' : ($change->significance === 'significant' ? 'Significante wijziging' : 'Kleine wijziging') }}</div>
                                <div class="what"><strong>{{ $change->summary }}</strong></div>
                                <div class="body small">
                                    @foreach ($change->diff['added'] ?? [] as $line)<div class="green">+ {{ $line }}</div>@endforeach
                                    @foreach ($change->diff['removed'] ?? [] as $line)<div class="red">− {{ $line }}</div>@endforeach
                                    @foreach ($change->diff['headers'] ?? [] as $name => $values)<div><span class="mono">{{ $name }}</span>: {{ $values['from'] ?? '(ontbrak)' }} → {{ $values['to'] ?? '(ontbreekt)' }}</div>@endforeach
                                    @if ($change->acknowledged_at)
                                        <div class="muted">Bevestigd als verwacht door {{ $change->acknowledger?->name ?? 'onbekend' }} op {{ Time::format($change->acknowledged_at) }}</div>
                                    @elseif ($isAdmin)
                                        <form method="POST" action="{{ route('page-changes.acknowledge', $change) }}" style="margin-top:6px">@csrf<button class="button button-sm" type="submit">Bevestigen als verwacht</button></form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="stack">
            <section class="panel">
                <div class="section-heading"><div><h2>Controles</h2></div></div>
                <dl class="dl">
                    <dt>Interval / time-out</dt><dd>elke {{ $page->check_interval_minutes }} min / {{ $page->timeout_seconds }} s</dd>
                    <dt>Drempels</dt><dd>onbereikbaar na {{ $page->failure_threshold }}×, hersteld na {{ $page->recovery_threshold }}×</dd>
                    <dt>Statuscodes</dt><dd>{{ $page->expected_status_codes ?: '200-399' }}</dd>
                    <dt>Max. responstijd</dt><dd>{{ $page->max_response_ms ? $page->max_response_ms.' ms (daarboven: verminderd)' : '—' }}</dd>
                    <dt>Redirects</dt><dd>{{ $page->follow_redirects ? 'volgen' : 'niet volgen' }}@if ($page->expected_final_url)<br>verwacht eindadres: <span class="break">{{ $page->expected_final_url }}</span>@endif @if ($page->last_final_url && $page->last_final_url !== $page->url)<br>laatst: <span class="break">{{ $page->last_final_url }}</span>@endif</dd>
                    <dt>Inhoud</dt><dd>@if ($page->keyword_present)moet bevatten “{{ $page->keyword_present }}”<br>@endif @if ($page->keyword_absent)mag niet bevatten “{{ $page->keyword_absent }}”@endif @if (! $page->keyword_present && ! $page->keyword_absent)—@endif</dd>
                    <dt>Headercontroles</dt><dd class="mono small" style="white-space:pre-line">{{ PageRequest::headerAssertionsText($page->header_assertions) ?: '—' }}</dd>
                    <dt>Gevolgde headers</dt><dd class="mono small">{{ implode(', ', $page->monitored_headers ?? []) ?: '—' }}</dd>
                    <dt>Inhoudsmonitoring</dt><dd>{{ $page->content_monitoring ? 'aan'.($page->content_selector ? ' (selector '.$page->content_selector.')' : ' (hele pagina)') : 'uit' }}@if ($page->content_exclusions)<br>uitgesloten: {{ implode(', ', $page->content_exclusions) }}@endif</dd>
                    <dt>Meldingen</dt><dd>{{ $page->alerts_enabled ? \App\Support\Severity::label($page->alert_severity) : 'uit' }}{{ $page->notify_content_changes ? ' · ook bij significante inhoudswijziging' : '' }}</dd>
                    <dt>Bron</dt><dd>{{ MonitoredPage::SOURCES[$page->source] ?? $page->source }}@if ($page->discovered_at), ontdekt {{ Time::format($page->discovered_at) }}@endif</dd>
                    <dt>Volgende controle</dt><dd>{{ $page->isMonitored() ? Time::format($page->next_check_at) : '—' }}</dd>
                </dl>
            </section>

            <section class="panel">
                <div class="section-heading"><div><h2>Incidenten</h2></div></div>
                @php $allIncidents = $incidents->concat($groupIncidents)->unique('id')->sortByDesc('opened_at'); @endphp
                @if ($allIncidents->isEmpty())
                    <x-empty-state icon="i-check" title="Geen incidenten" />
                @else
                    <ul style="margin:0;padding:0;list-style:none" class="small">
                        @foreach ($allIncidents as $incident)
                            <li style="padding:7px 0;border-bottom:1px solid #1a2840">
                                <a href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>
                                <div class="muted">{{ Time::format($incident->opened_at) }} · <x-status :status="$incident->status" />@if ($incident->subject_type === 'domain') · websitebreed @endif</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($isAdmin)
                <section class="panel">
                    <div class="section-heading"><div><h2>Verwijderen</h2><p>Verwijdert de pagina en de volledige controlegeschiedenis. Pauzeer de pagina om de geschiedenis te bewaren.</p></div></div>
                    <form method="POST" action="{{ route('pages.destroy', $page) }}" data-confirm="Pagina en controlegeschiedenis definitief verwijderen?">
                        @csrf @method('DELETE')
                        <button class="button button-danger" type="submit"><svg class="ico"><use href="#i-trash"/></svg> Pagina verwijderen</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
