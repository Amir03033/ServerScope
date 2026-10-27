@php use App\Services\Dependencies\ResourceCatalog; use App\Support\Time; @endphp

<x-layouts.app title="Afhankelijkheden">
    <x-slot:subtitle>Welke resources van elkaar afhangen, bijvoorbeeld server → Nginx → website → MySQL. Bevestigde afhankelijkheden worden gebruikt om gerelateerde incidenten te groeperen.</x-slot:subtitle>

    @can('admin')
        <section class="panel" style="margin-bottom:14px">
            <div class="section-heading"><div><h2>Afhankelijkheid toevoegen</h2><p>Lees als: <em>resource</em> werkt alleen als <em>afhankelijkheid</em> werkt. Pagina's horen automatisch bij hun website en services bij hun server.</p></div></div>
            @if ($options === [])
                <x-empty-state icon="i-link" title="Nog geen resources">Voeg eerst servers of domeinen toe.</x-empty-state>
            @else
                <form method="POST" action="{{ route('dependencies.store') }}" class="form-cols">
                    @csrf
                    <div @class(['form-field', 'has-error' => $errors->has('dependent')])>
                        <label for="dependent">Resource *</label>
                        <select id="dependent" name="dependent" required>
                            <option value="">Kies…</option>
                            @foreach ($options as $group => $items)
                                <optgroup label="{{ $group }}">
                                    @foreach ($items as $key => $name)<option value="{{ $key }}" @selected(old('dependent', request('dependent')) === $key)>{{ $name }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('dependent')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['form-field', 'has-error' => $errors->has('dependency')])>
                        <label for="dependency">Hangt af van *</label>
                        <select id="dependency" name="dependency" required>
                            <option value="">Kies…</option>
                            @foreach ($options as $group => $items)
                                <optgroup label="{{ $group }}">
                                    @foreach ($items as $key => $name)<option value="{{ $key }}" @selected(old('dependency', request('dependency')) === $key)>{{ $name }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('dependency')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-field">
                        <label for="label">Omschrijving</label>
                        <input id="label" type="text" name="label" maxlength="100" value="{{ old('label') }}" placeholder="bijv. draait op, gebruikt database">
                    </div>
                    <div class="form-field">
                        <label for="notes">Notities</label>
                        <input id="notes" type="text" name="notes" maxlength="2000" value="{{ old('notes') }}">
                    </div>
                    <div class="full form-footer"><button class="button button-primary" type="submit"><svg class="ico"><use href="#i-plus"/></svg> Vastleggen</button></div>
                </form>
            @endif
        </section>
    @endcan

    <section class="panel">
        <div class="section-heading"><div><h2>Bevestigde afhankelijkheden</h2></div></div>
        @if ($dependencies->isEmpty())
            <x-empty-state icon="i-link" title="Nog geen afhankelijkheden">Zonder afhankelijkheden worden incidenten van verschillende resources nooit gegroepeerd, ook niet als ze tegelijk optreden. Op de detailpagina van een domein of server zie je voorgestelde relaties (bijv. via het IP-adres).</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Resource</th><th></th><th>Hangt af van</th><th>Omschrijving</th><th>Vastgelegd</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($dependencies as $dependency)
                            <tr>
                                <td>
                                    @if ($url = ResourceCatalog::url($dependency->dependent))<a class="row-link" href="{{ $url }}">{{ ResourceCatalog::name($dependency->dependent) }}</a>@else{{ ResourceCatalog::name($dependency->dependent) }}@endif
                                    <span class="cell-sub">{{ ResourceCatalog::typeLabel($dependency->dependent_type) }}</span>
                                </td>
                                <td class="muted">→</td>
                                <td>
                                    @if ($url = ResourceCatalog::url($dependency->dependency))<a class="row-link" href="{{ $url }}">{{ ResourceCatalog::name($dependency->dependency) }}</a>@else{{ ResourceCatalog::name($dependency->dependency) }}@endif
                                    <span class="cell-sub">{{ ResourceCatalog::typeLabel($dependency->dependency_type) }}</span>
                                </td>
                                <td class="small">{{ $dependency->label ?? '—' }}@if ($dependency->notes)<span class="cell-sub">{{ $dependency->notes }}</span>@endif</td>
                                <td class="small nowrap">{{ Time::format($dependency->created_at) }}<span class="cell-sub">{{ $dependency->creator?->name ?? '—' }}</span></td>
                                <td>
                                    @can('admin')
                                        <form method="POST" action="{{ route('dependencies.destroy', $dependency) }}" class="inline-form" data-confirm="Afhankelijkheid verwijderen?">
                                            @csrf @method('DELETE')
                                            <button class="button button-sm button-danger" type="submit">Verwijderen</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$dependencies" />
        @endif
    </section>
</x-layouts.app>
