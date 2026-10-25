<x-layouts.app title="Statuspagina's">
    <x-slot:subtitle>Publieke (of besloten) statusoverzichten met alleen de onderdelen die je zelf publiceert.</x-slot:subtitle>
    <x-slot:actions><a class="button button-primary" href="{{ route('status-pages.create') }}"><svg class="ico"><use href="#i-plus"/></svg> Statuspagina maken</a></x-slot:actions>

    <section class="panel">
        @if ($pages->isEmpty())
            <x-empty-state icon="i-status" title="Nog geen statuspagina's">Maak een pagina en kies welke servers en domeinen erop verschijnen, onder een naam die je zelf bepaalt.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Naam</th><th>URL</th><th>Zichtbaarheid</th><th class="num">Onderdelen</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($pages as $page)
                            <tr>
                                <td><strong>{{ $page->name }}</strong></td>
                                <td><a href="{{ route('status.show', $page->slug) }}" target="_blank" rel="noopener">{{ route('status.show', $page->slug) }}</a></td>
                                <td><x-status :status="$page->is_public ? 'enabled' : 'paused'" :label="$page->is_public ? 'Publiek' : 'Besloten (inloggen)'" /></td>
                                <td class="num">{{ $page->components_count }}</td>
                                <td><div class="row-actions"><a class="button button-sm" href="{{ route('status-pages.edit', $page) }}">Bewerken</a></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
