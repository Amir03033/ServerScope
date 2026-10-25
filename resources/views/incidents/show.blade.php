@php
    use App\Models\Domain;
    use App\Models\Server;
    use App\Services\Incidents\IncidentMetrics;
    use App\Support\Time;

    $subject = $incident->subject;
    $isAdmin = auth()->user()->can('admin');
    $eventColor = fn (string $type) => match ($type) {
        'opened', 'reopened', 'escalated', 'severity_changed' => 'var(--red)',
        'recovered', 'resolved' => 'var(--green)',
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
                    <div class="actions"><x-status :status="$incident->severity" /><x-status :status="$incident->status" /></div>
                </div>
                @if ($incident->description)
                    <p style="margin:0 0 12px;white-space:pre-line">{{ $incident->description }}</p>
                @endif
                <dl class="dl">
                    <dt>Betreft</dt>
                    <dd>
                        @if ($subject instanceof Server)
                            <a href="{{ route('servers.show', $subject) }}">{{ $subject->name }}</a> <span class="muted">(server)</span>
                        @elseif ($subject instanceof Domain)
                            <a href="{{ route('domains.show', $subject) }}">{{ $subject->displayName() }}</a> <span class="muted">(domein)</span>
                        @else
                            —
                        @endif
                    </dd>
                    <dt>Gedetecteerd</dt><dd>{{ Time::format($incident->opened_at, 'd-m-Y H:i:s') }}</dd>
                    <dt>Laatst waargenomen</dt><dd>{{ Time::format($incident->last_occurrence_at, 'd-m-Y H:i:s') }} ({{ $incident->occurrence_count }}× in totaal)</dd>
                    <dt>Bevestigd</dt><dd>{{ $incident->acknowledged_at ? Time::format($incident->acknowledged_at, 'd-m-Y H:i:s').' door '.($incident->acknowledger?->name ?? 'onbekend').' (na '.IncidentMetrics::humanDuration($incident->timeToAcknowledge()).')' : '—' }}</dd>
                    <dt>Opgelost</dt><dd>{{ $incident->resolved_at ? Time::format($incident->resolved_at, 'd-m-Y H:i:s').' — '.($incident->resolver?->name ?? 'automatisch hersteld').' (na '.IncidentMetrics::humanDuration($incident->timeToResolve()).')' : '—' }}</dd>
                    @if ($incident->closed_at)<dt>Gesloten</dt><dd>{{ Time::format($incident->closed_at, 'd-m-Y H:i:s') }}</dd>@endif
                    @if ($incident->reopened_count)<dt>Heropend</dt><dd>{{ $incident->reopened_count }}×</dd>@endif
                    @if ($incident->resolution_notes)<dt>Oplossing</dt><dd style="white-space:pre-line">{{ $incident->resolution_notes }}</dd>@endif
                    <dt>Fingerprint</dt><dd class="mono small">{{ $incident->fingerprint }}</dd>
                </dl>
            </section>

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
                        <div class="form-field"><label for="notes">Oplossing</label><textarea id="notes" name="notes" maxlength="5000"></textarea></div>
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
                            @foreach (['info' => 'Info', 'warning' => 'Waarschuwing', 'critical' => 'Kritiek'] as $v => $l)<option value="{{ $v }}" @selected($incident->severity === $v)>{{ $l }}</option>@endforeach
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
