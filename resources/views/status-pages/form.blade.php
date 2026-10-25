@php
    $editing = $page->exists;
    $components = old('components', $page->components?->map(fn ($c) => ['subject' => $c->subject_type.':'.$c->subject_id, 'display_name' => $c->display_name, 'description' => $c->description])->all() ?? []);
    $components = array_values($components ?: [['subject' => '', 'display_name' => '', 'description' => '']]);
@endphp

<x-layouts.app :title="$editing ? 'Statuspagina bewerken' : 'Statuspagina maken'">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('status-pages.index') }}">Statuspagina's</a> <span>›</span> {{ $editing ? $page->name : 'Nieuw' }}</x-slot:breadcrumb>
    <x-slot:actions>
        @if ($editing)<a class="button" href="{{ route('status.show', $page->slug) }}" target="_blank" rel="noopener">Bekijken</a>@endif
        <a class="button" href="{{ route('status-pages.index') }}"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug</a>
    </x-slot:actions>

    <div class="flash info"><svg class="ico"><use href="#i-shield"/></svg><div>Op de pagina verschijnen alleen de namen die je hieronder kiest, de afgeleide status en de beschikbaarheid. Hostnames, IP-adressen, URL's en interne notities worden nooit getoond.</div></div>

    <form method="POST" action="{{ $editing ? route('status-pages.update', $page) : route('status-pages.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="stack">
            <section class="panel">
                <div class="form-cols">
                    <div @class(['form-field', 'has-error' => $errors->has('name')])><label for="name">Naam</label><input id="name" name="name" maxlength="100" required value="{{ old('name', $page->name) }}" placeholder="Status van Example B.V.">@error('name')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <div @class(['form-field', 'has-error' => $errors->has('slug')])><label for="slug">URL-deel</label><input id="slug" name="slug" maxlength="60" value="{{ old('slug', $page->slug) }}" placeholder="automatisch uit de naam"><span class="form-help">{{ url('/status') }}/<em>url-deel</em></span>@error('slug')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field full"><label for="description">Omschrijving</label><textarea id="description" name="description" maxlength="1000">{{ old('description', $page->description) }}</textarea></div>
                    <div class="form-field"><label for="accent_color">Accentkleur</label><input id="accent_color" name="accent_color" type="text" value="{{ old('accent_color', $page->accent_color) }}" pattern="#[0-9a-fA-F]{6}"></div>
                    <div class="form-field">
                        @foreach (['is_public' => 'Publiek (zonder inloggen zichtbaar)', 'show_uptime' => 'Beschikbaarheid per dag tonen (90 dagen)', 'show_incident_titles' => 'Incidenttitels tonen (kunnen interne namen bevatten); anders een algemene omschrijving'] as $key => $label)
                            <label class="check"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $page->{$key}))> {{ $label }}</label>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="section-heading"><div><h2>Onderdelen</h2><p>Lege rijen worden genegeerd. De volgorde bepaalt de weergave.</p></div></div>
                <div id="component-rows" class="stack">
                    @foreach ($components as $i => $component)
                        <div class="form-cols component-row">
                            <div class="form-field"><label>Monitor</label>
                                <select name="components[{{ $i }}][subject]">
                                    <option value="">— kies —</option>
                                    <optgroup label="Domeinen">@foreach ($domains as $d)<option value="domain:{{ $d->id }}" @selected(($component['subject'] ?? '') === 'domain:'.$d->id)>{{ $d->displayName() }}</option>@endforeach</optgroup>
                                    <optgroup label="Servers">@foreach ($servers as $s)<option value="server:{{ $s->id }}" @selected(($component['subject'] ?? '') === 'server:'.$s->id)>{{ $s->name }}</option>@endforeach</optgroup>
                                </select>
                                @error("components.$i.subject")<span class="form-error">{{ $message }}</span>@enderror
                            </div>
                            <div class="form-field"><label>Publieke naam</label><input name="components[{{ $i }}][display_name]" maxlength="100" value="{{ $component['display_name'] ?? '' }}" placeholder="Website">@error("components.$i.display_name")<span class="form-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field"><label>Omschrijving</label><input name="components[{{ $i }}][description]" maxlength="255" value="{{ $component['description'] ?? '' }}"></div>
                            <button type="button" class="button button-sm" onclick="this.closest('.component-row').remove()" aria-label="Verwijderen"><svg class="ico"><use href="#i-x"/></svg></button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="button button-sm" style="margin-top:12px" onclick="const rows=document.getElementById('component-rows');const tpl=rows.querySelector('.component-row').cloneNode(true);const n=Date.now();tpl.querySelectorAll('[name]').forEach(el=>{el.name=el.name.replace(/components\[\d+\]/,'components['+n+']');el.value='';});tpl.querySelectorAll('.form-error').forEach(e=>e.remove());rows.appendChild(tpl);"><svg class="ico"><use href="#i-plus"/></svg> Onderdeel toevoegen</button>
            </section>
        </div>
        <div class="form-footer"><button class="button button-primary" type="submit">{{ $editing ? 'Opslaan' : 'Statuspagina maken' }}</button></div>
    </form>

    @if ($editing)
        <form method="POST" action="{{ route('status-pages.destroy', $page) }}" style="margin-top:14px" data-confirm="Statuspagina verwijderen? De publieke URL werkt daarna niet meer.">
            @csrf @method('DELETE')
            <button class="button button-danger" type="submit">Statuspagina verwijderen</button>
        </form>
    @endif
</x-layouts.app>
