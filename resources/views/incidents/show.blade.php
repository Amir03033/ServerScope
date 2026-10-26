@php
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Time;

    $subject = $incident->subject;
    $isAdmin = auth()->user()->can('admin');
    $eventColor = fn (string $type) => match ($type) {
        'opened', 'reopened', 'escalated', 'severity_changed' => 'var(--red)',
        'recovered', 'resolved', 'resource_recovered' => 'var(--green)',
        'correlated', 'child_added', 'detached', 'resource_affected' => 'var(--purple)',
        'acknowledged', 'investigating' => 'var(--yellow)',
        'comment' => 'var(--cyan)',
        default => 'var(--muted)',
    };
@endphp

<x-layouts.app :title="'Incident #'.$incident->id">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">Incidenten</a> <span>›</span> #{{ $incident->id }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $incident->title }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ $incident->sourceLabel() }} · geopend {{ Time::format($incident->opened_at, 'd-m-Y H:i:s') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('incidents.index') }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Overzicht</a>
        @foreach (['acknowledged' => ['Bevestigen', 'button-warning'], 'investigating' => ['Onderzoek starten', ''], 'resolved' => ['Oplossen', 'button-primary']] as $target => [$label, $class])
            @if ($incident->canTransitionTo($target) && $target !== 'resolved')
                <form method="POST" action="{{ route('incidents.transition', $incident) }}" class="inline-form">
                    @csrf
                    <input type="hidden" name="status" value="{{ $target }}">
                    <button type="submit" class="button {{ $class }}">{{ $label }}</button>
                </form>
            @endif
        @endforeach
        @if ($isAdmin && $incident->canTransitionTo('open'))
            <form method="POST" action="{{ route('incidents.transition', $incident) }}" class="inline-form">
                @csrf <input type="hidden" name="status" value="open">
                <button type="submit" class="button">Heropenen</button>
            </form>
        @endif
    </x-slot:actions>

    <div class="grid-2 split">
        <div class="stack">
            <section class="panel">
                <div class="section-heading">
                    <div><h2>Samenvatting</h2></div>
                    <div class="actions"><x-severity :severity="$incident->severity" /><x-status :status="$incident->status" /></div>
                </div>
                @if ($incident->description)
                    <p style="margin:0 0 12px;white-space:pre-line">{{ $incident->description }}</p>
                @endif
                <dl class="dl">
                    <dt>Betreft</dt>
                    <dd>
                        @if ($subject)
                            @php $subjectUrl = \App\Services\Dependencies\ResourceCatalog::url($subject); @endphp
                            @if ($subjectUrl)<a href="{{ $subjectUrl }}">{{ \App\Services\Dependencies\ResourceCatalog::name($subject) }}</a>@else{{ \App\Services\Dependencies\ResourceCatalog::name($subject) }}@endif
                            <span class="muted">({{ mb_strtolower(\App\Services\Dependencies\ResourceCatalog::typeLabel($incident->subject_type)) }})</span>
                        @else
                            —
                        @endif
                    </dd>
                    @if ($incident->started_at && $incident->started_at->lt($incident->opened_at))
                        <dt>Probleem begonnen</dt><dd>{{ Time::format($incident->started_at, 'd-m-Y H:i:s') }} <span class="muted">(eerste mislukte controle of meting)</span></dd>
                    @endif
                    <dt>Gedetecteerd</dt><dd>{{ Time::format($incident->opened_at, 'd-m-Y H:i:s') }}@if ($incident->timeToDetect() !== null) <span class="muted">(detectietijd {{ IncidentMetrics::humanDuration($incident->timeToDetect()) }})</span>@endif</dd>
                    <dt>Laatst waargenomen</dt><dd>{{ Time::format($incident->last_occurrence_at, 'd-m-Y H:i:s') }} ({{ $incident->occurrence_count }}× in totaal)</dd>
                    <dt>Bevestigd</dt><dd>{{ $incident->acknowledged_at ? Time::format($incident->acknowledged_at, 'd-m-Y H:i:s').' door '.($incident->acknowledger?->name ?? 'onbekend').' (na '.IncidentMetrics::humanDuration($incident->timeToAcknowledge()).')' : '—' }}</dd>
                    <dt>Opgelost</dt><dd>{{ $incident->resolved_at ? Time::format($incident->resolved_at, 'd-m-Y H:i:s').' — '.($incident->resolver?->name ?? 'automatisch hersteld').' (na '.IncidentMetrics::humanDuration($incident->timeToResolve()).')' : '—' }}</dd>
                    @if ($incident->closed_at)<dt>Gesloten</dt><dd>{{ Time::format($incident->closed_at, 'd-m-Y H:i:s') }}</dd>@endif
                    @if ($incident->reopened_count)<dt>Heropend</dt><dd>{{ $incident->reopened_count }}×</dd>@endif
                    <dt>Herhaling</dt><dd>{{ $recurrence['last_30d'] }}× in 30 dagen · {{ $recurrence['total'] }}× in totaal voor dit probleem</dd>
                    @if ($incident->resolution_notes)<dt>Oplossing</dt><dd style="white-space:pre-line">{{ $incident->resolution_notes }}</dd>@endif
                    <dt>Fingerprint</dt><dd class="mono small">{{ $incident->fingerprint }}</dd>
                </dl>
            </section>

            @if ($incident->parent)
                <section class="panel">
                    <div class="section-heading">
                        <div><h2>Gegroepeerd</h2><p>Dit incident valt onder <a href="{{ route('incidents.show', $incident->parent) }}">incident #{{ $incident->parent->id }}: {{ $incident->parent->title }}</a> (<x-status :status="$incident->parent->status" />). Zolang dat incident actief is, gaan meldingen via dat incident.</p></div>
                        @if ($isAdmin)
                            <form method="POST" action="{{ route('incidents.detach', $incident) }}" class="inline-form" data-confirm="Groepering opheffen? Dit incident krijgt dan weer eigen meldingen.">
                                @csrf
                                <button class="button button-sm" type="submit">Loskoppelen</button>
                            </form>
                        @endif
                    </div>
                    @if ($incident->correlation['reason'] ?? null)
                        <p class="small" style="margin:0"><strong>Reden:</strong> {{ $incident->correlation['reason'] }}</p>
                    @endif
                    <p class="muted small" style="margin:8px 0 0">Groepering op basis van een bevestigde afhankelijkheid of dezelfde resource; het is geen bewijs dat de oorzaak vaststaat.</p>
                </section>
            @endif

            @if ($incident->children->isNotEmpty())
                <section class="panel">
                    <div class="section-heading"><div><h2>Gerelateerde incidenten ({{ $incident->children->count() }})</h2><p>Gegroepeerd onder dit incident op basis van bevestigde afhankelijkheden. Ze herstellen elk op basis van hun eigen controles.</p></div></div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Incident</th><th>Betreft</th><th>Status</th><th>Geopend</th></tr></thead>
                            <tbody>
                                @foreach ($incident->children as $child)
                                    <tr>
                                        <td><a class="row-link" href="{{ route('incidents.show', $child) }}">#{{ $child->id }} {{ $child->title }}</a><span class="cell-sub">{{ $child->correlation['reason'] ?? '' }}</span></td>
                                        <td>{{ $child->subjectName() }}</td>
                                        <td><x-status :status="$child->status" /></td>
                                        <td class="nowrap">{{ Time::format($child->opened_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($incident->impacts->isNotEmpty())
                @php $failingImpacts = $incident->impacts->where('status', 'failing')->count(); @endphp
                <section class="panel">
                    <div class="section-heading"><div><h2>Getroffen resources</h2><p>{{ $failingImpacts }} van {{ $incident->impacts->count() }} nog niet hersteld. Details per resource blijven bewaard.</p></div></div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Resource</th><th>Rol</th><th>Fouttype</th><th>Status</th><th>Eerst mislukt</th><th>Hersteld</th></tr></thead>
                            <tbody>
                                @foreach ($incident->impacts->sortByDesc(fn ($i) => $i->status === 'failing') as $impact)
                                    @php $impactUrl = \App\Services\Dependencies\ResourceCatalog::url($impact->resource); @endphp
                                    <tr>
                                        <td>
                                            @if ($impactUrl)<a class="row-link" href="{{ $impactUrl }}">{{ \App\Services\Dependencies\ResourceCatalog::name($impact->resource) }}</a>@else{{ \App\Services\Dependencies\ResourceCatalog::name($impact->resource) }}@endif
                                            <span class="cell-sub">{{ \App\Services\Dependencies\ResourceCatalog::typeLabel($impact->resource_type) }}@if ($impact->message) · {{ $impact->message }}@endif</span>
                                        </td>
                                        <td>{{ $impact->role === 'cause' ? 'Oorzaak' : 'Getroffen' }}</td>
                                        <td>{{ $impact->failure_type ? (['availability' => 'Beschikbaarheid', 'server_error' => 'Serverfout (5xx)', ...\App\Models\MonitoredPage::FAILURE_LABELS][$impact->failure_type] ?? str_replace('_', ' ', $impact->failure_type)) : '—' }}</td>
                                        <td><x-status :status="$impact->status === 'failing' ? 'down' : 'up'" :label="$impact->status === 'failing' ? 'Faalt' : 'Hersteld'" /></td>
                                        <td class="nowrap">{{ Time::format($impact->first_failed_at) }}</td>
                                        <td class="nowrap">{{ Time::format($impact->recovered_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="panel">
                <div class="section-heading"><div><h2>Tijdlijn</h2><p>Alle gebeurtenissen en notities, oudste eerst.</p></div></div>
                <ul class="timeline">
                    @foreach ($incident->events as $event)
                        <li style="--c: {{ $eventColor($event->type) }}">
                            <div class="when">{{ Time::format($event->created_at, 'd-m-Y H:i:s') }} · {{ $event->user?->name ?? 'Systeem' }}</div>
                            <div class="what"><strong>{{ $event->label() }}</strong></div>
                            @if ($event->message)<div class="body">{{ $event->message }}</div>@endif
                        </li>
                    @endforeach
                </ul>

                <form method="POST" action="{{ route('incidents.comment', $incident) }}" style="margin-top:18px">
                    @csrf
                    <div class="form-field">
                        <label for="message">Notitie of onderzoeksbevinding</label>
                        <textarea id="message" name="message" maxlength="5000" required placeholder="Wat heb je gevonden of gedaan?">{{ old('message') }}</textarea>
                    </div>
                    <div class="form-footer" style="margin-top:12px;padding-top:12px"><button class="button" type="submit"><svg class="ico"><use href="#i-message"/></svg> Notitie toevoegen</button></div>
                </form>
            </section>

            @if ($checks->isNotEmpty())
                <section class="panel">
                    <div class="section-heading"><div><h2>Mislukte controles</h2><p>Gerelateerde monitoringgebeurtenissen rond dit incident.</p></div></div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Tijdstip</th><th>Type</th><th>Resultaat</th><th>Melding</th></tr></thead>
                            <tbody>
                                @foreach ($checks as $check)
                                    <tr>
                                        <td class="nowrap">{{ Time::format($check->checked_at, 'd-m-Y H:i:s') }}</td>
                                        <td><span class="chip">{{ strtoupper($check->type) }}</span></td>
                                        <td>{{ str_replace('_', ' ', $check->result) }}@if ($check->http_status_code) ({{ $check->http_status_code }})@endif</td>
                                        <td class="small break">{{ $check->message }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($incident->alerts->isNotEmpty())
                <section class="panel">
                    <div class="section-heading"><div><h2>Gekoppelde alerts</h2></div></div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Server</th><th>Metric</th><th class="num">Waarde</th><th>Status</th><th>Geactiveerd</th></tr></thead>
                            <tbody>
                                @foreach ($incident->alerts as $alert)
                                    <tr>
                                        <td>{{ $alert->server?->name ?? '—' }}</td>
                                        <td>{{ \App\Models\AlertRule::metricLabel($alert->metric) }}@if ($alert->dimension) · {{ $alert->dimension }}@endif</td>
                                        <td class="num">{{ number_format($alert->observed_value, 1, ',', '.') }}{{ \App\Models\AlertRule::metricUnit($alert->metric) }}</td>
                                        <td>{{ $alert->status }}</td>
                                        <td class="nowrap">{{ Time::format($alert->triggered_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <div class="stack">
            <section class="panel">
                <div class="section-heading"><div><h2>Toewijzing</h2></div></div>
                <form method="POST" action="{{ route('incidents.assign', $incident) }}" style="display:flex;gap:8px;flex-wrap:wrap">
                    @csrf
                    <label class="sr-only" for="assigned_to">Toewijzen aan</label>
                    <select id="assigned_to" name="assigned_to" class="input" style="flex:1;min-width:160px">
                        <option value="">Niemand</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected($incident->assigned_to === $user->id)>{{ $user->name }}{{ $user->id === auth()->id() ? ' (ik)' : '' }}</option>
                        @endforeach
                    </select>
                    <button class="button" type="submit">Opslaan</button>
                </form>
            </section>

            @if ($incident->canTransitionTo('resolved'))
                <section class="panel">
                    <div class="section-heading"><div><h2>Oplossen</h2><p>Leg vast wat de oorzaak en oplossing was. Treedt het probleem binnen {{ config('serverscope.incidents.reopen_window_minutes') }} minuten opnieuw op, dan wordt dit incident automatisch heropend.</p></div></div>
                    <form method="POST" action="{{ route('incidents.transition', $incident) }}">
                        @csrf
                        <input type="hidden" name="status" value="resolved">
                        <div @class(['form-field', 'has-error' => $errors->has('notes')])><label for="notes">Oorzaak en oplossing *</label><textarea id="notes" name="notes" minlength="3" maxlength="5000" required placeholder="Wat was de oorzaak en wat is er gedaan?">{{ old('notes') }}</textarea>@error('notes')<span class="form-error">{{ $message }}</span>@enderror</div>
                        <div class="form-footer" style="margin-top:12px;padding-top:12px"><button class="button button-primary" type="submit">Incident oplossen</button></div>
                    </form>
                </section>
            @endif

            @if ($isAdmin && $incident->canTransitionTo('closed'))
                <section class="panel">
                    <div class="section-heading"><div><h2>Sluiten</h2><p>Een gesloten incident wordt niet meer heropend; een nieuw probleem krijgt een nieuw incident.</p></div></div>
                    <form method="POST" action="{{ route('incidents.transition', $incident) }}">
                        @csrf
                        <input type="hidden" name="status" value="closed">
                        <div class="form-field"><label for="close-notes">Afsluitende notitie (optioneel)</label><textarea id="close-notes" name="notes" maxlength="5000"></textarea></div>
                        <div class="form-footer" style="margin-top:12px;padding-top:12px"><button class="button" type="submit">Incident sluiten</button></div>
                    </form>
                </section>
            @endif

            @if ($isAdmin)
                <section class="panel">
                    <div class="section-heading"><div><h2>Ernst</h2></div></div>
                    <form method="POST" action="{{ route('incidents.severity', $incident) }}" style="display:flex;gap:8px">
                        @csrf
                        <select name="severity" class="input" aria-label="Ernst" style="flex:1">
                            @foreach (\App\Support\Severity::options() as $v => $l)<option value="{{ $v }}" @selected($incident->severity === $v)>{{ $l }}</option>@endforeach
                        </select>
                        <button class="button" type="submit">Wijzigen</button>
                    </form>
                </section>
            @endif

            @if ($related->isNotEmpty())
                <section class="panel">
                    <div class="section-heading"><div><h2>Eerdere incidenten</h2><p>Voor {{ $incident->subjectName() }}.</p></div></div>
                    <ul style="margin:0;padding:0;list-style:none" class="small">
                        @foreach ($related as $other)
                            <li style="padding:7px 0;border-bottom:1px solid #1a2840">
                                <a href="{{ route('incidents.show', $other) }}">{{ $other->title }}</a>
                                <div class="muted">{{ Time::format($other->opened_at) }} · {{ $other->status }}</div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
