<x-layouts.app :title="'Bewerken: '.$server->name">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('servers.index') }}">Servers</a>
        <span>›</span> <a href="{{ route('servers.show', $server) }}">{{ $server->name }}</a>
        <span>›</span> Bewerken
    </x-slot:breadcrumb>
    <x-slot:pageTitle>Server bewerken</x-slot:pageTitle>
    <x-slot:subtitle>{{ $server->name }} · {{ $server->hostname }}</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.show', $server) }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug naar server</a>
    </x-slot:actions>

    <div class="stack">
        <section class="panel">
            <form method="POST" action="{{ route('servers.update', $server) }}">
                @csrf
                @method('PUT')
                @include('servers._form')

                <div class="form-footer">
                    <button type="submit" class="button button-primary">Wijzigingen opslaan</button>
                    <a href="{{ route('servers.show', $server) }}" class="button">Annuleren</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>Server verwijderen</h2>
                    <p>
                        De server verdwijnt uit het overzicht en alle agentcredentials worden ingetrokken.
                        Metingen, alerts en incidenten blijven bewaard; de server kan worden hersteld.
                    </p>
                </div>
            </div>
            <form method="POST" action="{{ route('servers.destroy', $server) }}"
                  data-confirm="Server {{ $server->name }} verwijderen? De agent kan daarna geen metingen meer versturen.">
                @csrf
                @method('DELETE')
                <button type="submit" class="button button-danger"><svg class="ico"><use href="#i-trash"/></svg> Server verwijderen</button>
            </form>
        </section>
    </div>
</x-layouts.app>
