@php
    use App\Support\Time;

    $tabs = [
        null => ['Alle', $counts['all']],
        'critical' => ['Kritiek', $counts['critical']],
        'warning' => ['Waarschuwing', $counts['warning']],
        'healthy' => ['Gezond', $counts['healthy']],
        'unknown' => ['Onbekend', $counts['unknown']],
        'paused' => ['Gepauzeerd', $counts['paused']],
    ];

    if (auth()->user()->can('admin')) {
        $tabs['deleted'] = ['Verwijderd', $counts['deleted']];
    }

    $days = fn (?int $d) => $d === null ? null : ($d < 0 ? 'verlopen' : $d.' d');
@endphp

<x-layouts.app title="Domeinen">
    <x-slot:subtitle>Beschikbaarheid, certificaten, DNS en registratie van je domeinen.</x-slot:subtitle>

    <x-slot:actions>
        @can('admin')
            <a href="{{ route('domains.create') }}" class="button button-primary"><svg class="ico"><use href="#i-plus"/></svg> Domein toevoegen</a>
        @endcan
    </x-slot:actions>

    <section class="kpis" aria-label="Domeinstatistieken">
        <div class="kpi tone-cyan"><span class="kpi-icon"><svg class="ico"><use href="#i-globe"/></svg></span><div><div class="kpi-label">Gemonitord</div><div class="kpi-value">{{ $counts['all'] - $counts['paused'] }}</div></div></div>
        <div class="kpi tone-green"><span class="kpi-icon"><svg class="ico"><use href="#i-check"/></svg></span><div><div class="kpi-label">Gezond</div><div class="kpi-value">{{ $counts['healthy'] }}</div></div></div>
        <div class="kpi tone-yellow"><span class="kpi-icon"><svg class="ico"><use href="#i-alert"/></svg></span><div><div class="kpi-label">Waarschuwing</div><div class="kpi-value">{{ $counts['warning'] }}</div></div></div>
        <div class="kpi tone-red"><span class="kpi-icon"><svg class="ico"><use href="#i-siren"/></svg></span><div><div class="kpi-label">Kritiek</div><div class="kpi-value">{{ $counts['critical'] }}</div></div></div>
    </section>

    <section class="panel">
        <nav class="filters" aria-label="Filter op status">
            @foreach ($tabs as $key => [$label, $count])
                <a href="{{ route('domains.index', array_filter([...request()->only(['q', 'sort']), 'status' => $key])) }}"
                   @class(['filter', 'active' => $status === $key]) @if ($status === $key) aria-current="page" @endif>
                    {{ $label }} <span class="filter-count">{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('domains.index') }}" class="toolbar" role="search">
            @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <div class="form-field grow">
                <label for="q">Zoeken</label>
                <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Domeinnaam">
            </div>
            <div class="form-field">
                <label for="sort">Sorteren</label>
                <select id="sort" name="sort">
                    @foreach (['status' => 'Status', 'name' => 'Naam', 'ssl' => 'Certificaat verloopt', 'expiry' => 'Registratie verloopt', 'response' => 'Responstijd'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['sort'] ?? 'status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="button" type="submit"><svg class="ico"><use href="#i-search"/></svg> Filteren</button>
        </form>

        @if ($domains->isEmpty())
            @if ($counts['all'] === 0 && ! $status)
                <x-empty-state icon="i-globe" title="Nog geen domeinen">
                    Voeg een domein toe om HTTP-beschikbaarheid, certificaten, DNS en de registratie te bewaken.
                    @can('admin')
                        <x-slot:actions><a href="{{ route('domains.create') }}" class="button button-primary">Eerste domein toevoegen</a></x-slot:actions>
                    @endcan
                </x-empty-state>
            @else
                <x-empty-state icon="i-search" title="Geen domeinen gevonden">Er zijn geen domeinen die aan de filters voldoen.</x-empty-state>
            @endif
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Domein</th>
                            <th>Status</th>
                            <th>HTTP</th>
                            <th class="num">Responstijd</th>
                            <th>Certificaat</th>
                            <th>Registratie</th>
                            <th>Laatste controle</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($domains as $domain)
                            @php
                                $sslDays = $domain->sslDaysRemaining();
                                $expDays = $domain->expiry_status === 'verified' ? $domain->expiryDaysRemaining() : null;
                            @endphp
                            <tr>
                                <td>
                                    <a class="row-link" href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a>
                                    <span class="cell-sub">{{ $domain->url() }}</span>
                                </td>
                                <td>
                                    @if ($domain->trashed())
                                        <x-status status="closed" label="Verwijderd" />
                                    @else
                                        <x-status :status="$domain->displayStatus()" :label="\App\Models\Domain::STATUS_LABELS[$domain->displayStatus()] ?? null" />
                                    @endif
                                </td>
                                <td>
                                    @if (! $domain->http_enabled)
                                        <span class="muted small">Uit</span>
                                    @else
                                        <x-status :status="$domain->http_status" />
                                        @if ($domain->http_consecutive_failures > 0 && $domain->http_status !== 'down')
                                            <span class="cell-sub">{{ $domain->http_consecutive_failures }}× mislukt</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="num">{{ $domain->http_last_response_ms !== null ? number_format($domain->http_last_response_ms, 0, ',', '.').' ms' : '—' }}</td>
                                <td>
                                    @if (! $domain->ssl_enabled)
                                        <span class="muted small">Uit</span>
                                    @elseif ($sslDays !== null)
                                        <span @class(['red' => $sslDays < 0 || in_array($domain->ssl_status, \App\Models\Domain::SSL_INVALID_STATES, true), 'yellow' => $domain->ssl_status === 'expiring'])>{{ $days($sslDays) }}</span>
                                        @if ($domain->ssl_status !== 'valid')<span class="cell-sub">{{ str_replace('_', ' ', $domain->ssl_status) }}</span>@endif
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if (! $domain->expiry_enabled)
                                        <span class="muted small">Uit</span>
                                    @elseif ($expDays !== null)
                                        <span @class(['red' => $expDays < 0, 'yellow' => $expDays >= 0 && $expDays <= max($domain->expiryWarningDays())])>{{ $days($expDays) }}</span>
                                    @else
                                        <span class="muted small" title="{{ $domain->expiry_message }}">{{ ['unknown' => 'Nog niet opgevraagd', 'unavailable' => 'Niet beschikbaar', 'unsupported' => 'Niet ondersteund', 'error' => 'Fout', 'rate_limited' => 'Uitgesteld', 'not_found' => 'Niet gevonden'][$domain->expiry_status] ?? $domain->expiry_status }}</span>
                                    @endif
                                </td>
                                <td class="nowrap">{{ Time::relative($domain->http_last_checked_at ?? $domain->dns_last_checked_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$domains" />
        @endif
    </section>
</x-layouts.app>
