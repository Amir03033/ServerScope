@php
    use App\Models\ServiceCheck;
    use App\Support\Time;

    $toneColor = ['green' => 'var(--green)', 'yellow' => 'var(--yellow)', 'red' => 'var(--red)', 'purple' => 'var(--purple)', 'muted' => 'var(--muted)'][$diagnosis['tone']] ?? 'var(--muted)';
    $agentOffline = in_array($displayStatus, ['offline', 'pending'], true);
    $integrityLabels = ['verified' => 'Bekende build', 'not_verified' => 'Niet geverifieerd (geen bekende controlesommen ingesteld)', 'not_reported' => 'Niet gerapporteerd (agent ouder dan 0.2.0)', 'unknown_build' => 'Onbekende build'];
@endphp

<div class="grid-2" style="margin-bottom:14px">
    <section class="panel" style="border-left:3px solid {{ $toneColor }}">
        <div class="section-heading"><div><h2>Diagnose: {{ $diagnosis['title'] }}</h2><p>{{ $diagnosis['explanation'] }}</p></div></div>
        @if ($diagnosis['evidence'])
            <ul class="small" style="margin:0;padding-left:18px">@foreach ($diagnosis['evidence'] as $line)<li>{{ $line }}</li>@endforeach</ul>
        @endif
        <dl class="dl" style="margin-top:10px">
            <dt>Agent</dt><dd>{{ $server->agent_version ?? 'onbekend' }}@if ($quality['outdated']) <span class="yellow">(verouderd, huidige versie {{ $quality['latest_version'] }})</span>@endif · laatst gezien {{ Time::relative($server->last_seen_at) }}</dd>
            <dt>Integriteit</dt><dd>{{ $integrityLabels[$quality['integrity']] ?? $quality['integrity'] }}@if ($server->agent_checksum)<span class="cell-sub mono">{{ substr($server->agent_checksum, 0, 16) }}…</span>@endif</dd>
            <dt>Metingen laatste uur</dt><dd>{{ $quality['received'] }}@if ($quality['expected']) van ~{{ $quality['expected'] }} verwacht ({{ number_format($quality['coverage'], 1, ',', '.') }}%, interval ~{{ $quality['interval_seconds'] }} s)@endif</dd>
            @if ($quality['missing_fields'])<dt>Ontbrekend</dt><dd class="yellow">{{ implode(', ', $quality['missing_fields']) }}</dd>@endif
        </dl>
    </section>

    <section class="panel">
        <div class="section-heading"><div><h2>Services</h2><p>Alleen services die in de agent zijn ingesteld (<code>SERVERSCOPE_SERVICES</code>). Een gestopte service is iets anders dan een onbereikbare server.</p></div></div>
        @if ($services->isEmpty())
            <x-empty-state icon="i-list" title="Geen services gerapporteerd">Stel op de server bijvoorbeeld <code>SERVERSCOPE_SERVICES=nginx,mysql,php8.3-fpm</code> in en herstart de agent (versie 0.2.0 of nieuwer).</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Service</th><th>Status</th><th>Laatst actief</th><th>Meldingen</th>@can('admin')<th><span class="sr-only">Acties</span></th>@endcan</tr></thead>
                    <tbody>
                        @foreach ($services as $service)
                            @php $shown = $agentOffline && $service->status !== 'stale' ? 'stale' : $service->status; @endphp
                            <tr>
                                <td><strong>{{ $service->display_name ?: $service->name }}</strong><span class="cell-sub">{{ ServiceCheck::KINDS[$service->kind] ?? $service->kind }} · {{ $service->name }}</span></td>
                                <td>
                                    @if (! $service->is_enabled)
                                        <x-status status="paused" label="Uitgeschakeld" />
                                    @else
                                        <x-status :status="['running' => 'up', 'starting' => 'warning', 'stopped' => 'down', 'failed' => 'down', 'not_found' => 'down'][$shown] ?? 'unknown'" :label="ServiceCheck::STATUS_LABELS[$shown] ?? $shown" />
                                        @if ($shown !== 'stale' && $service->state_detail)<span class="cell-sub">{{ $service->state_detail }}</span>@endif
                                        @if ($agentOffline)<span class="cell-sub">Agent offline: status onbekend</span>@endif
                                    @endif
                                </td>
                                <td class="nowrap">{{ Time::relative($service->last_running_at) }}</td>
                                <td class="small">@if ($service->alerts_enabled)<x-severity :severity="$service->severity" /> na {{ $service->failure_threshold }}×@else<span class="muted">uit</span>@endif</td>
                                @can('admin')
                                    <td>
                                        <form method="POST" action="{{ route('services.update', $service) }}" class="inline-form" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="is_enabled" value="{{ $service->is_enabled ? 0 : 1 }}">
                                            <input type="hidden" name="alerts_enabled" value="{{ $service->alerts_enabled ? 1 : 0 }}">
                                            <input type="hidden" name="severity" value="{{ $service->severity }}">
                                            <input type="hidden" name="failure_threshold" value="{{ $service->failure_threshold }}">
                                            <button class="button button-sm" type="submit">{{ $service->is_enabled ? 'Uitschakelen' : 'Inschakelen' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('services.update', $service) }}" class="inline-form">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="is_enabled" value="{{ $service->is_enabled ? 1 : 0 }}">
                                            <input type="hidden" name="alerts_enabled" value="{{ $service->alerts_enabled ? 0 : 1 }}">
                                            <input type="hidden" name="severity" value="{{ $service->severity }}">
                                            <input type="hidden" name="failure_threshold" value="{{ $service->failure_threshold }}">
                                            <button class="button button-sm" type="submit">{{ $service->alerts_enabled ? 'Meldingen uit' : 'Meldingen aan' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('services.destroy', $service) }}" class="inline-form" data-confirm="Service {{ $service->name }} en de geschiedenis verwijderen? Als de agent hem blijft melden, verschijnt hij opnieuw.">
                                            @csrf @method('DELETE')
                                            <button class="button button-sm button-danger" type="submit">Verwijderen</button>
                                        </form>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

@if ($predictions->isNotEmpty())
    <section class="panel" style="margin-bottom:14px">
        <div class="section-heading"><div><h2>Afwijkingen en prognoses</h2><p>Statistische analyse van de eigen historie van deze server. Elke uitkomst vermeldt het venster, de trend en de onzekerheid.</p></div></div>
        @include('servers._predictions', ['predictions' => $predictions])
    </section>
@endif
