@php use App\Support\Time; @endphp

<x-layouts.app title="Zoeken">
    <x-slot:subtitle>Servers, domeinen en incidenten.</x-slot:subtitle>

    <section class="panel">
        <form method="GET" action="{{ route('search') }}" class="toolbar" role="search">
            <div class="form-field grow"><label for="search-q">Zoekterm</label><input id="search-q" type="search" name="q" value="{{ $q }}" autofocus placeholder="Naam, hostname, IP, domein of #incidentnummer"></div>
            <button class="button button-primary" type="submit"><svg class="ico"><use href="#i-search"/></svg> Zoeken</button>
        </form>

        @if (mb_strlen($q) < 2)
            <x-empty-state icon="i-search" title="Waar ben je naar op zoek?">Typ minimaal twee tekens.</x-empty-state>
        @elseif (collect($results)->every->isEmpty())
            <x-empty-state icon="i-search" title="Niets gevonden">Geen servers, domeinen of incidenten gevonden voor “{{ $q }}”.</x-empty-state>
        @else
            <div class="grid-3">
                <div>
                    <h2 class="small muted" style="margin:0 0 8px">Servers ({{ $results['servers']->count() }})</h2>
                    @foreach ($results['servers'] as $server)
                        <a class="ov-row" href="{{ route('servers.show', $server) }}"><x-status :status="$server->displayStatus()" /><span class="ov-main"><strong>{{ $server->name }}</strong><span class="cell-sub">{{ $server->hostname }}</span></span></a>
                    @endforeach
                </div>
                <div>
                    <h2 class="small muted" style="margin:0 0 8px">Domeinen ({{ $results['domains']->count() }})</h2>
                    @foreach ($results['domains'] as $domain)
                        <a class="ov-row" href="{{ route('domains.show', $domain) }}"><x-status :status="$domain->displayStatus()" :label="\App\Models\Domain::STATUS_LABELS[$domain->displayStatus()] ?? null" /><span class="ov-main"><strong>{{ $domain->displayName() }}</strong><span class="cell-sub">{{ $domain->url() }}</span></span></a>
                    @endforeach
                </div>
                <div>
                    <h2 class="small muted" style="margin:0 0 8px">Incidenten ({{ $results['incidents']->count() }})</h2>
                    @foreach ($results['incidents'] as $incident)
                        <a class="ov-row" href="{{ route('incidents.show', $incident) }}"><x-status :status="$incident->status" /><span class="ov-main"><strong>#{{ $incident->id }} {{ $incident->title }}</strong><span class="cell-sub">{{ Time::format($incident->opened_at) }}</span></span></a>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
</x-layouts.app>
