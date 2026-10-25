<x-layouts.app title="Domein toevoegen">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.index') }}">Domeinen</a> <span>›</span> Toevoegen</x-slot:breadcrumb>
    <x-slot:subtitle>Bewaak beschikbaarheid, certificaat, DNS en registratie van een domein.</x-slot:subtitle>
    <x-slot:actions><a href="{{ route('domains.index') }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug</a></x-slot:actions>

    <section class="panel">
        <form method="POST" action="{{ route('domains.store') }}">
            @csrf
            @include('domains._form')
            <div class="form-footer">
                <button type="submit" class="button button-primary">Domein toevoegen</button>
                <a href="{{ route('domains.index') }}" class="button">Annuleren</a>
            </div>
        </form>
    </section>
</x-layouts.app>
