@php
    use App\Models\MonitoredPage;
    use App\Support\Time;

    $ms = fn (?float $v) => $v === null ? '—' : number_format($v, 0, ',', '.').' ms';
    $pct = fn (?float $v, int $d = 2) => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
    $isAdmin = auth()->user()->can('admin');
    $tabs = [
        null => ['Alle', $counts['total'] + $counts['pending']],
        'down' => ['Onbereikbaar', $counts['down']],
        'degraded' => ['Verminderd', $counts['degraded']],
        'up' => ['Gezond', $counts['up']],
        'unknown' => ['Onbekend', $counts['unknown']],
        'stale' => ['Niet recent gecontroleerd', $counts['stale']],
        'paused' => ['Gepauzeerd', $counts['paused']],
        'pending' => ['Wacht op goedkeuring', $counts['pending']],
        'rejected' => ['Afgewezen', $counts['rejected']],
    ];
    $sortLink = function (string $key, string $label) use ($filters) {
        $current = ($filters['sort'] ?? 'status') === $key;
        $direction = $current && ($filters['direction'] ?? null) === 'asc' ? 'desc' : 'asc';

        return '<a href="'.e(route('websites.pages', [request()->route('domain'), ...request()->except(['page', 'sort', 'direction']), 'sort' => $key, 'direction' => $direction])).'">'.e($label).($current ? ' ↕' : '').'</a>';
    };
@endphp

<x-layouts.app :title="'Website '.$domain->displayName()">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.index') }}">Domeinen</a> <span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> Website</x-slot:breadcrumb>
    <x-slot:subtitle>Elke gemonitorde pagina heeft een eigen status, drempels, meldingen en geschiedenis.</x-slot:subtitle>
    <x-slot:actions>
        @if ($isAdmin && ! $domain->trashed())
            <a href="{{ route('websites.discovery', $domain) }}" class="button"><svg class="ico"><use href="#i-search"/></svg> Pagina's ontdekken</a>
            <a href="{{ route('websites.pages.create', $domain) }}" class="button button-primary"><svg class="ico"><use href="#i-plus"/></svg> Pagina's toevoegen</a>
        @endif
    </x-slot:actions>

    @include('websites._tabs')

    @if ($counts['pending'] > 0)
        <div class="flash info" role="status"><svg class="ico"><use href="#i-search"/></svg><div><strong>{{ $counts['pending'] }} ontdekte {{ $counts['pending'] === 1 ? 'pagina wacht' : "pagina's wachten" }} op goedkeuring.</strong> Ze worden pas gecontroleerd na goedkeuring. <a href="{{ route('websites.pages', [$domain, 'status' => 'pending']) }}">Bekijken</a></div></div>
    @endif

    <section class="kpis" aria-label="Websitestatistieken">
        <div class="kpi tone-cyan"><span class="kpi-icon"><svg class="ico"><use href="#i-list"/></svg></span><div><div class="kpi-label">Gemonitorde pagina's</div><div class="kpi-value">{{ $counts['total'] }}</div><div class="kpi-caption">{{ $counts['paused'] }} gepauzeerd · {{ $counts['pending'] }} wachtend</div></div></div>
        <div class="kpi tone-green"><span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span><div><div class="kpi-label">Gezond</div><div class="kpi-value">{{ $counts['up'] }}</div><div class="kpi-caption">{{ $counts['unknown'] }} nog niet gecontroleerd</div></div></div>
        <div class="kpi tone-yellow"><span class="kpi-icon"><svg class="ico"><use href="#i-alert"/></svg></span><div><div class="kpi-label">Verminderd</div><div class="kpi-value">{{ $counts['degraded'] }}</div><div class="kpi-caption">traag of eerste mislukte controle</div></div></div>
        <div class="kpi tone-red"><span class="kpi-icon"><svg class="ico"><use href="#i-siren"/></svg></span><div><div class="kpi-label">Onbereikbaar</div><div class="kpi-value">{{ $counts['down'] }}</div><div class="kpi-caption">{{ $counts['stale'] }} niet recent gecontroleerd</div></div></div>
        <div class="kpi tone-purple"><span class="kpi-icon"><svg class="ico"><use href="#i-clock"/></svg></span><div><div class="kpi-label">Responstijd 24 uur</div><div class="kpi-value" style="font-size:20px">{{ $ms($stats24h['avg']) }}</div><div class="kpi-caption">@if ($stats24h['p95'] !== null)p50 {{ $ms($stats24h['p50']) }} · p95 {{ $ms($stats24h['p95']) }} · p99 {{ $ms($stats24h['p99']) }}@else Te weinig metingen voor percentielen @endif</div></div></div>
    </section>

    <div class="grid-2" style="margin-bottom:14px">
        <section class="panel">
            <div class="section-heading"><div><h2>Beschikbaarheid</h2><p>Geslaagde controles van alle pagina's per dag (14 dagen). Grijs = geen metingen.</p></div></div>
            <div class="strip" role="img" aria-label="Beschikbaarheid per dag">
                @foreach ($daily as $day)
                    <i @class(['up' => $day['uptime'] === 100.0, 'partial' => $day['uptime'] !== null && $day['uptime'] < 100 && $day['uptime'] >= 95, 'down' => $day['uptime'] !== null && $day['uptime'] < 95])
                       title="{{ $day['date'] }}: {{ $day['uptime'] === null ? 'geen metingen' : number_format($day['uptime'], 2, ',', '.').'% ('.$day['checks'].' controles)' }}"></i>
                @endforeach
            </div>
            <dl class="dl" style="margin-top:14px">
                <dt>Beschikbaarheid 24 uur / 7 dagen</dt><dd>{{ $pct($stats24h['availability']) }} / {{ $pct($stats7d['availability']) }}</dd>
                <dt>Controles 7 dagen</dt><dd>{{ $stats7d['checks'] }} ({{ $stats7d['failures'] }} mislukt)</dd>
                <dt>Responstijd 7 dagen</dt><dd>gem. {{ $ms($stats7d['avg']) }}@if ($stats7d['p95'] !== null) · p95 {{ $ms($stats7d['p95']) }}@endif</dd>
                <dt>Laatste ontdekking</dt><dd>@if ($latestRun)<a href="{{ route('websites.discovery', $domain) }}">{{ Time::format($latestRun->created_at) }}</a> · <x-status :status="['completed' => 'valid', 'partial' => 'warning', 'failed' => 'failed', 'queued' => 'queued', 'running' => 'investigating'][$latestRun->status] ?? 'unknown'" :label="['completed' => 'Voltooid', 'partial' => 'Gedeeltelijk', 'failed' => 'Mislukt', 'queued' => 'In wachtrij', 'running' => 'Bezig'][$latestRun->status] ?? $latestRun->status" />@else Nog niet uitgevoerd @endif</dd>
            </dl>
        </section>

        <div class="stack">
            <section class="panel">
                <div class="section-heading"><div><h2>Actieve incidenten</h2><p>Over de website of een van de pagina's.</p></div></div>
                @if ($incidents->isEmpty())
                    <x-empty-state icon="i-check" title="Geen actieve incidenten" />
                @else
                    <ul style="margin:0;padding:0;list-style:none" class="small">
                        @foreach ($incidents as $incident)
                            <li style="padding:8px 0;border-bottom:1px solid var(--ss-line);display:flex;gap:8px;align-items:flex-start">
                                <x-severity :severity="$incident->severity" />
                                <div><a href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a>
                                    <div class="muted">{{ Time::relative($incident->opened_at) }}@if ($incident->failing_pages_count) · {{ $incident->failing_pages_count }} pagina('s) getroffen @endif @if ($incident->parent_id) · gegroepeerd onder #{{ $incident->parent_id }} @endif</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="panel">
                <div class="section-heading"><div><h2>Recente wijzigingen</h2><p>Inhoud, headers, DNS en certificaten.</p></div></div>
                @if ($changes->isEmpty())
                    <x-empty-state icon="i-history" title="Geen wijzigingen geregistreerd">Zet inhoudsmonitoring aan op een pagina om wijzigingen te volgen.</x-empty-state>
                @else
                    <ul style="margin:0;padding:0;list-style:none" class="small">
                        @foreach ($changes as $change)
                            <li style="padding:7px 0;border-bottom:1px solid #1a2840">
                                <span class="chip">{{ ['content' => 'Inhoud', 'headers' => 'Headers', 'dns' => 'DNS', 'ssl' => 'SSL'][$change['type']] }}</span>
                                <a href="{{ $change['url'] }}" class="break">{{ $change['title'] }}</a>
                                <div class="muted">{{ Time::format($change['at']) }}@if ($change['detail']) · {{ $change['detail'] }}@endif</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    <section class="panel">
        <nav class="filters" aria-label="Filter op status">
            @foreach ($tabs as $key => [$label, $count])
                <a href="{{ route('websites.pages', [$domain, ...array_filter([...request()->only(['q', 'sort', 'direction', 'source']), 'status' => $key])]) }}"
                   @class(['filter', 'active' => $status === $key]) @if ($status === $key) aria-current="page" @endif>{{ $label }} <span class="filter-count">{{ $count }}</span></a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('websites.pages', $domain) }}" class="toolbar" role="search">
            @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <div class="form-field grow"><label for="q">Zoeken</label><input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="URL of paginatitel"></div>
            <div class="form-field"><label for="source">Bron</label>
                <select id="source" name="source"><option value="">Alle</option>@foreach (MonitoredPage::SOURCES as $v => $l)<option value="{{ $v }}" @selected(($filters['source'] ?? null) === $v)>{{ $l }}</option>@endforeach</select>
            </div>
            <div class="form-field"><label for="sort">Sorteren</label>
                <select id="sort" name="sort">
                    @foreach (['status' => 'Status', 'url' => 'URL', 'response' => 'Responstijd', 'checked' => 'Laatste controle', 'failure' => 'Laatste fout', 'changed' => 'Laatste wijziging'] as $v => $l)<option value="{{ $v }}" @selected(($filters['sort'] ?? 'status') === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <button class="button" type="submit"><svg class="ico"><use href="#i-search"/></svg> Filteren</button>
        </form>

        @if ($pages->isEmpty())
            @if ($counts['total'] + $counts['pending'] + $counts['rejected'] === 0)
                <x-empty-state icon="i-globe" title="Nog geen pagina's">
                    Voeg pagina's handmatig toe (bijv. /prijzen, /login of /api/health) of laat ServerScope ze ontdekken via sitemaps en interne links.
                    @if ($isAdmin)
                        <x-slot:actions>
                            <a href="{{ route('websites.pages.create', $domain) }}" class="button button-primary">Pagina's toevoegen</a>
                            <a href="{{ route('websites.discovery', $domain) }}" class="button">Pagina's ontdekken</a>
                        </x-slot:actions>
                    @endif
                </x-empty-state>
            @else
                <x-empty-state icon="i-search" title="Geen pagina's gevonden">Er zijn geen pagina's die aan de filters voldoen.</x-empty-state>
            @endif
        @else
            <form method="POST" action="{{ route('websites.pages.bulk', $domain) }}" id="bulk-form">
                @csrf
                @if ($isAdmin)
                    <div class="toolbar" style="align-items:flex-end">
                        <div class="form-field"><label for="bulk-action">Bulkactie voor geselecteerde pagina's</label>
                            <select id="bulk-action" name="action" required>
                                <option value="">Kies een actie…</option>
                                <option value="approve">Goedkeuren en monitoren</option>
                                <option value="reject">Afwijzen</option>
                                <option value="pause">Pauzeren</option>
                                <option value="resume">Hervatten</option>
                                <option value="check">Nu controleren</option>
                                <option value="interval">Interval wijzigen</option>
                                <option value="delete">Verwijderen (incl. geschiedenis)</option>
                            </select>
                        </div>
                        <div class="form-field"><label for="bulk-interval">Interval (min)</label><input id="bulk-interval" type="number" name="interval" min="1" max="1440" placeholder="bijv. 15"></div>
                        <button class="button" type="submit" data-bulk-submit>Uitvoeren</button>
                        <span class="muted small" data-selected-count>0 geselecteerd</span>
                    </div>
                @endif

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                @if ($isAdmin)<th><label class="sr-only" for="select-all">Alles selecteren</label><input id="select-all" type="checkbox" data-select-all></th>@endif
                                <th style="min-width:200px">{!! $sortLink('url', 'Pagina') !!}</th>
                                <th>{!! $sortLink('status', 'Status') !!}</th>
                                <th class="num">HTTP</th>
                                <th class="num">{!! $sortLink('response', 'Responstijd') !!}</th>
                                <th>{!! $sortLink('checked', 'Laatste controle') !!}</th>
                                <th>{!! $sortLink('failure', 'Laatste fout') !!}</th>
                                <th>SSL</th>
                                <th>{!! $sortLink('changed', 'Inhoud gewijzigd') !!}</th>
                                <th>Volgende</th>
                                <th>Meldingen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pages as $page)
                                @php $display = $page->displayStatus(); @endphp
                                <tr>
                                    @if ($isAdmin)<td><label class="sr-only" for="page-{{ $page->id }}">Selecteer {{ $page->url }}</label><input id="page-{{ $page->id }}" type="checkbox" name="ids[]" value="{{ $page->id }}" data-row-select></td>@endif
                                    <td>
                                        <a class="row-link break" href="{{ route('pages.show', $page) }}">{{ $page->displayName() }}</a>
                                        <span class="cell-sub">{{ $page->title ?? '—' }} · {{ MonitoredPage::SOURCES[$page->source] ?? $page->source }} · elke {{ $page->check_interval_minutes }} min</span>
                                    </td>
                                    <td>
                                        <x-status :status="['pending' => 'queued', 'rejected' => 'closed'][$display] ?? $display" :label="['pending' => 'Wacht op goedkeuring', 'rejected' => 'Afgewezen', 'paused' => 'Gepauzeerd'][$display] ?? (MonitoredPage::STATUS_LABELS[$display] ?? null)" />
                                        @if ($page->last_failure_type && $page->isMonitored())<span class="cell-sub">{{ MonitoredPage::FAILURE_LABELS[$page->last_failure_type] ?? $page->last_failure_type }}@if ($page->consecutive_failures) · {{ $page->consecutive_failures }}×@endif</span>@endif
                                    </td>
                                    <td class="num">{{ $page->last_status_code ?? '—' }}</td>
                                    <td class="num">{{ $ms($page->last_response_ms) }}</td>
                                    <td class="nowrap">@if ($page->last_checked_at){{ Time::relative($page->last_checked_at) }}<span class="cell-sub">OK {{ Time::relative($page->last_success_at) }}</span>@else<span class="muted">—</span>@endif</td>
                                    <td class="nowrap">{{ $page->last_failure_at ? Time::relative($page->last_failure_at) : '—' }}</td>
                                    <td>@if (! $page->isHttps())<span class="muted small">n.v.t.</span>@elseif ($page->tls_valid === null)<span class="muted small">—</span>@else<x-status :status="$page->tls_valid ? 'valid' : 'invalid'" />@endif</td>
                                    <td class="nowrap small">{{ $page->content_monitoring ? ($page->content_changed_at ? Time::relative($page->content_changed_at) : 'geen') : 'uit' }}</td>
                                    <td class="nowrap small">{{ $page->isMonitored() && $page->next_check_at ? Time::format($page->next_check_at, 'H:i') : '—' }}</td>
                                    <td class="small">@if ($page->alerts_enabled)<x-severity :severity="$page->alert_severity" />@else<span class="muted">uit</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
            <x-pagination :paginator="$pages" />
        @endif
    </section>

    <x-slot:scripts>
<script>
    (() => {
        const form = document.getElementById('bulk-form');
        if (!form) return;
        const all = form.querySelector('[data-select-all]');
        const rows = () => [...form.querySelectorAll('[data-row-select]')];
        const counter = form.querySelector('[data-selected-count]');
        const update = () => { if (counter) counter.textContent = rows().filter((r) => r.checked).length + ' geselecteerd'; };
        all?.addEventListener('change', () => { rows().forEach((r) => { r.checked = all.checked; }); update(); });
        form.addEventListener('change', update);
        form.addEventListener('submit', (event) => {
            const action = form.querySelector('[name=action]').value;
            if (action === 'delete' && !window.confirm('Geselecteerde pagina\'s en hun controlegeschiedenis definitief verwijderen?')) {
                event.preventDefault();
            }
        });
    })();
</script>
    </x-slot:scripts>
</x-layouts.app>
