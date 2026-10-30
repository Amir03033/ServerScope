@php
    use App\Models\Server;
    use App\Support\Time;

    $tabs = [
        null => ['Alle', $counts['all']],
        'online' => ['Online', $counts['online']],
        'offline' => ['Offline', $counts['offline']],
        'pending' => ['In afwachting', $counts['pending']],
        'maintenance' => ['Onderhoud', $counts['maintenance']],
    ];

    if (auth()->user()->can('admin')) {
        $tabs['deleted'] = ['Verwijderd', $counts['deleted']];
    }
@endphp

<x-layouts.app title="Servers">
    <x-slot:subtitle>Alle gemonitorde servers, hun status en de meest recente metingen.</x-slot:subtitle>

    <x-slot:actions>
        @can('admin')
            @if ($latestRelease = \App\Models\AgentRelease::latestAvailable())
                <form method="POST" action="{{ route('servers.agent-update-all') }}" data-confirm="Alle servers met een actief updateprogramma en een oudere agent bijwerken naar {{ $latestRelease->version }}? De agents herstarten even.">
                    @csrf
                    <button class="button" type="submit">Alles bijwerken naar {{ $latestRelease->version }}</button>
                </form>
            @endif
            <a href="{{ route('servers.add') }}" class="button button-primary">
                <svg class="ico"><use href="#i-plus"/></svg> Server toevoegen
            </a>
        @endcan
    </x-slot:actions>

    <section class="panel">
        <nav class="filters" aria-label="Filter op status">
            @foreach ($tabs as $key => [$label, $count])
                <a
                    href="{{ route('servers.index', array_filter([...request()->only(['q', 'environment', 'sort']), 'status' => $key])) }}"
                    @class(['filter', 'active' => $status === $key])
                    @if ($status === $key) aria-current="page" @endif
                >
                    {{ $label }} <span class="filter-count">{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('servers.index') }}" class="toolbar" role="search">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <div class="form-field grow">
                <label for="q">Zoeken</label>
                <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Naam, hostname of IP-adres">
            </div>
            <div class="form-field">
                <label for="environment">Omgeving</label>
                <select id="environment" name="environment">
                    <option value="">Alle omgevingen</option>
                    @foreach (Server::ENVIRONMENTS as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['environment'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field">
                <label for="sort">Sorteren</label>
                <select id="sort" name="sort">
                    <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Naam</option>
                    <option value="last_seen" @selected(($filters['sort'] ?? null) === 'last_seen')>Laatste contact</option>
                    <option value="environment" @selected(($filters['sort'] ?? null) === 'environment')>Omgeving</option>
                </select>
            </div>
            <button class="button" type="submit"><svg class="ico"><use href="#i-search"/></svg> Filteren</button>
            @if (array_filter($filters))
                <a class="button" href="{{ route('servers.index') }}">Wissen</a>
            @endif
        </form>

        @if ($servers->isEmpty())
            @if ($counts['all'] === 0 && ! $status)
                <x-empty-state icon="i-server" title="Nog geen servers geregistreerd">
                    Registreer je eerste server en installeer de agent om metingen te ontvangen.
                    @can('admin')
                        <x-slot:actions>
                            <a href="{{ route('servers.add') }}" class="button button-primary">Eerste server toevoegen</a>
                        </x-slot:actions>
                    @endcan
                </x-empty-state>
            @else
                <x-empty-state icon="i-search" title="Geen servers gevonden">
                    Er zijn geen servers die aan de huidige filters voldoen.
                </x-empty-state>
            @endif
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Server</th>
                            <th>Omgeving</th>
                            <th>Status</th>
                            <th>Laatste contact</th>
                            <th class="num">CPU</th>
                            <th class="num">RAM</th>
                            <th><span class="sr-only">Acties</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            @php
                                $sample = $server->latestMetricSample;
                                $memory = $sample && $sample->memory_total_bytes > 0
                                    ? $sample->memory_used_bytes / $sample->memory_total_bytes * 100
                                    : null;
                                $state = $server->trashed() ? 'deleted' : $server->displayStatus();
                            @endphp
                            <tr>
                                <td>
                                    <a class="row-link" href="{{ route('servers.show', $server) }}">{{ $server->name }}</a>
                                    <span class="cell-sub">{{ $server->hostname }}@if ($server->ip_address) · {{ $server->ip_address }}@endif</span>
                                </td>
                                <td><span class="chip">{{ $server->environmentLabel() }}</span></td>
                                <td>
                                    @if ($state === 'deleted')
                                        <x-status status="closed" label="Verwijderd" />
                                    @else
                                        <x-status :status="$state" />
                                    @endif
                                </td>
                                <td class="nowrap" title="{{ Time::format($server->last_seen_at, 'd-m-Y H:i:s') }}">
                                    {{ Time::relative($server->last_seen_at) }}
                                </td>
                                <td class="num">{{ $sample?->cpu_percent !== null ? number_format($sample->cpu_percent, 1, ',', '.').'%' : '—' }}</td>
                                <td class="num">{{ $memory !== null ? number_format($memory, 1, ',', '.').'%' : '—' }}</td>
                                <td>
                                    <div class="row-actions">
                                        <a class="button button-sm" href="{{ route('servers.show', $server) }}">Details</a>
                                        @can('admin')
                                            @unless ($server->trashed())
                                                <a class="button button-sm" href="{{ route('servers.edit', $server) }}">Bewerken</a>
                                            @endunless
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$servers" />
        @endif
    </section>
</x-layouts.app>
