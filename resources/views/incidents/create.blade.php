<x-layouts.app title="Incident melden">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">Incidenten</a> <span>›</span> Nieuw</x-slot:breadcrumb>
    <x-slot:subtitle>Registreer een storing die niet automatisch is gedetecteerd.</x-slot:subtitle>
    <x-slot:actions><a href="{{ route('incidents.index') }}" class="button"><svg class="ico"><use href="#i-arrow-left"/></svg> Terug</a></x-slot:actions>

    <section class="panel">
        <form method="POST" action="{{ route('incidents.store') }}">
            @csrf
            <div class="form-cols">
                <div @class(['form-field full', 'has-error' => $errors->has('title')])>
                    <label for="title">Titel *</label>
                    <input id="title" name="title" type="text" maxlength="255" required value="{{ old('title') }}">
                    @error('title')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="severity">Ernst *</label>
                    <select id="severity" name="severity" required>
                        @foreach (\App\Support\Severity::options() as $v => $l)<option value="{{ $v }}" @selected(old('severity') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div class="form-field">
                    <label for="subject">Betreft</label>
                    <select id="subject" name="subject">
                        <option value="">Niet gekoppeld</option>
                        <optgroup label="Servers">@foreach ($servers as $s)<option value="server:{{ $s->id }}" @selected(old('subject') === 'server:'.$s->id)>{{ $s->name }}</option>@endforeach</optgroup>
                        <optgroup label="Domeinen">@foreach ($domains as $d)<option value="domain:{{ $d->id }}" @selected(old('subject') === 'domain:'.$d->id)>{{ $d->displayName() }}</option>@endforeach</optgroup>
                    </select>
                </div>
                <div class="form-field">
                    <label for="assigned_to">Toewijzen aan</label>
                    <select id="assigned_to" name="assigned_to"><option value="">Niemand</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                </div>
                <div class="form-field full">
                    <label for="description">Beschrijving</label>
                    <textarea id="description" name="description" maxlength="5000">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="form-footer"><button class="button button-primary" type="submit">Incident aanmaken</button><a class="button" href="{{ route('incidents.index') }}">Annuleren</a></div>
        </form>
    </section>
</x-layouts.app>
