<x-layouts.app title="Server toevoegen">
    <x-slot:breadcrumb>
        <span>›</span> <a href="{{ route('servers.index') }}">Servers</a> <span>›</span> Toevoegen
    </x-slot:breadcrumb>
    <x-slot:subtitle>Registreer een server en ontvang een token voor de monitoringagent.@if (config('serverscope.docs_url')) <a href="{{ config('serverscope.docs_url') }}/server-monitoring" target="_blank" rel="noopener">Hoe werkt de agent?</a>@endif</x-slot:subtitle>

    <x-slot:actions>
        <a href="{{ route('servers.index') }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug</a>
    </x-slot:actions>

    <section class="panel">
        <form method="POST" action="{{ route('servers.store') }}">
            @csrf
            @include('servers._form')

            <div class="form-footer">
                <button type="submit" class="button button-primary">Server registreren</button>
                <a href="{{ route('servers.index') }}" class="button">Annuleren</a>
            </div>
        </form>
    </section>
</x-layouts.app>
