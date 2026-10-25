<x-layouts.app :title="'Bewerken: '.$domain->displayName()">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('domains.index') }}">Domeinen</a>
        <span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> Bewerken
    </x-slot:breadcrumb>
    <x-slot:pageTitle>Domein bewerken</x-slot:pageTitle>
    <x-slot:subtitle>{{ $domain->url() }}</x-slot:subtitle>
    <x-slot:actions><a href="{{ route('domains.show', $domain) }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug naar domein</a></x-slot:actions>

    <div class="stack">
        <section class="panel">
            <form method="POST" action="{{ route('domains.update', $domain) }}">
                @csrf
                @method('PUT')
                @include('domains._form')
                <div class="form-footer">
                    <button type="submit" class="button button-primary">Wijzigingen opslaan</button>
                    <a href="{{ route('domains.show', $domain) }}" class="button">Annuleren</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>Domein verwijderen</h2>
                    <p>Monitoring stopt. Controlegeschiedenis, DNS-historie en incidenten blijven bewaard; het domein kan worden hersteld.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('domains.destroy', $domain) }}" data-confirm="Domein {{ $domain->displayName() }} verwijderen?">
                @csrf
                @method('DELETE')
                <button type="submit" class="button button-danger"><svg class="ico"><use href="#i-trash"/></svg> Domein verwijderen</button>
            </form>
        </section>
    </div>
</x-layouts.app>
