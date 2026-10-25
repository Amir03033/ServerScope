@php use App\Support\Time; @endphp

<x-layouts.app title="Auditlog">
    <x-slot:subtitle>Wie heeft wat gewijzigd. Geheimen (tokens, wachtwoorden) worden nooit vastgelegd.</x-slot:subtitle>

    <section class="panel">
        <form method="GET" action="{{ route('audit-logs.index') }}" class="toolbar" role="search">
            <div class="form-field grow"><label for="q">Zoeken</label><input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Omschrijving"></div>
            <div class="form-field"><label for="action">Onderdeel</label>
                <select id="action" name="action"><option value="">Alle</option>
                    @foreach ($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ $action }}</option>@endforeach
                </select>
            </div>
            <div class="form-field"><label for="user">Gebruiker</label>
                <select id="user" name="user"><option value="">Iedereen</option>
                    @foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) ($filters['user'] ?? 0) === $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            </div>
            <button class="button" type="submit"><svg class="ico"><use href="#i-search"/></svg> Filteren</button>
        </form>

        @if ($logs->isEmpty())
            <x-empty-state icon="i-history" title="Geen auditregels">Beheeracties verschijnen hier.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Tijdstip</th><th>Gebruiker</th><th>Actie</th><th>Omschrijving</th><th>IP</th></tr></thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="nowrap">{{ Time::format($log->created_at, 'd-m-Y H:i:s') }}</td>
                                <td class="small">{{ $log->user?->name ?? 'Systeem' }}</td>
                                <td><span class="chip mono">{{ $log->action }}</span></td>
                                <td class="small break">
                                    {{ $log->description }}
                                    @if (! empty($log->properties['changes']))
                                        <details style="margin-top:4px"><summary class="muted">{{ count($log->properties['changes']) }} wijziging(en)</summary>
                                            <div class="mono" style="margin-top:4px">
                                                @foreach ($log->properties['changes'] as $field => $change)
                                                    <div>{{ $field }}: {{ \Illuminate\Support\Str::limit(json_encode($change['old'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 60) }} → {{ \Illuminate\Support\Str::limit(json_encode($change['new'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 60) }}</div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </td>
                                <td class="small mono">{{ $log->ip_address ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$logs" />
        @endif
    </section>
</x-layouts.app>
