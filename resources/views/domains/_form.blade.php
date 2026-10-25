@php
    use App\Models\Domain;
    use App\Support\Time;

    $isEditing = $domain->exists;
    $days = fn ($value) => implode(', ', Domain::normaliseDays($value));
    $checked = fn (string $key) => (bool) old($key, $isEditing ? $domain->{$key} : true);
    // Old input holds the parsed array after a failed validation.
    $oldDays = fn (string $key) => is_array(old($key)) ? implode(', ', old($key)) : old($key, $days($domain->{$key}));
@endphp

<div class="form-cols">
    <div @class(['form-field', 'has-error' => $errors->has('name')])>
        <label for="name">Domeinnaam *</label>
        <input id="name" name="name" type="text" maxlength="253" required value="{{ old('name_input', old('name', $domain->exists ? $domain->displayName() : '')) }}" placeholder="example.com of www.example.nl" autocomplete="off">
        <span class="form-help">Een URL mag ook; alleen de hostname wordt bewaard. Internationale domeinnamen worden ondersteund.</span>
        @error('name')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div class="form-field" style="grid-template-columns:110px 1fr;column-gap:10px">
        <label for="scheme" style="grid-column:1/-1">Te controleren URL</label>
        <select id="scheme" name="scheme">
            <option value="https" @selected(old('scheme', $domain->scheme ?? 'https') === 'https')>https://</option>
            <option value="http" @selected(old('scheme', $domain->scheme) === 'http')>http://</option>
        </select>
        <input id="path" name="path" type="text" maxlength="255" value="{{ old('path', $domain->path ?? '/') }}" aria-label="Pad" placeholder="/">
        @error('path')<span class="form-error" style="grid-column:1/-1">{{ $message }}</span>@enderror
    </div>

    <div class="form-section">
        <h3>Controles</h3>
        <p>Kies welke onderdelen van dit domein worden bewaakt.</p>
    </div>

    <div class="form-field full">
        <div class="check-group">
            @foreach (['http_enabled' => 'HTTP-beschikbaarheid', 'ssl_enabled' => 'SSL/TLS-certificaat (alleen https)', 'dns_enabled' => 'DNS-records', 'expiry_enabled' => 'Domeinregistratie (RDAP)'] as $key => $label)
                <label class="check">
                    <input type="hidden" name="{{ $key }}" value="0">
                    <input type="checkbox" name="{{ $key }}" value="1" @checked($checked($key))>
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('check_interval_minutes')])>
        <label for="check_interval_minutes">Interval (minuten) *</label>
        <input id="check_interval_minutes" name="check_interval_minutes" type="number" min="{{ config('serverscope.monitoring.min_interval_minutes', 1) }}" max="1440" required value="{{ old('check_interval_minutes', $domain->check_interval_minutes ?? 5) }}">
        <span class="form-help">Hoe vaak de HTTP-controle draait. DNS minimaal elke {{ config('serverscope.monitoring.dns_interval_minutes') }} min, certificaat elke {{ intdiv(config('serverscope.monitoring.ssl_interval_minutes'), 60) }} uur, registratie dagelijks.</span>
        @error('check_interval_minutes')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('timeout_seconds')])>
        <label for="timeout_seconds">Time-out (seconden) *</label>
        <input id="timeout_seconds" name="timeout_seconds" type="number" min="2" max="30" required value="{{ old('timeout_seconds', $domain->timeout_seconds ?? 10) }}">
        @error('timeout_seconds')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('failure_threshold')])>
        <label for="failure_threshold">Faaldrempel *</label>
        <input id="failure_threshold" name="failure_threshold" type="number" min="1" max="10" required value="{{ old('failure_threshold', $domain->failure_threshold ?? 3) }}">
        <span class="form-help">Aantal opeenvolgende mislukte controles voordat er een incident ontstaat.</span>
        @error('failure_threshold')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('recovery_threshold')])>
        <label for="recovery_threshold">Hersteldrempel *</label>
        <input id="recovery_threshold" name="recovery_threshold" type="number" min="1" max="10" required value="{{ old('recovery_threshold', $domain->recovery_threshold ?? 2) }}">
        <span class="form-help">Aantal geslaagde controles op rij voordat een storing als hersteld geldt.</span>
        @error('recovery_threshold')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('expected_status_codes')])>
        <label for="expected_status_codes">Verwachte statuscodes</label>
        <input id="expected_status_codes" name="expected_status_codes" type="text" maxlength="100" value="{{ old('expected_status_codes', $domain->expected_status_codes) }}" placeholder="200-399">
        <span class="form-help">Bijv. <code>200</code> of <code>200-299,301</code>. Leeg = 200–399.</span>
        @error('expected_status_codes')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('expected_content')])>
        <label for="expected_content">Verwachte inhoud (optioneel)</label>
        <input id="expected_content" name="expected_content" type="text" maxlength="255" value="{{ old('expected_content', $domain->expected_content) }}" placeholder="Bijv. een tekst die altijd op de pagina staat">
        <span class="form-help">Hoofdlettergevoelig; doorzoekt de eerste {{ number_format(config('serverscope.monitoring.max_response_bytes') / 1048576, 0) }} MB van het antwoord.</span>
        @error('expected_content')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div class="form-field full">
        <label class="check">
            <input type="hidden" name="follow_redirects" value="0">
            <input type="checkbox" name="follow_redirects" value="1" @checked($checked('follow_redirects'))>
            Redirects volgen (maximaal {{ config('serverscope.monitoring.max_redirects') }}; elke stap wordt gevalideerd)
        </label>
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('ssl_warning_days') || $errors->has('ssl_warning_days.*')])>
        <label for="ssl_warning_days">Certificaatwaarschuwingen (dagen)</label>
        <input id="ssl_warning_days" name="ssl_warning_days" type="text" value="{{ $oldDays('ssl_warning_days') }}">
        @error('ssl_warning_days')<span class="form-error">{{ $message }}</span>@enderror
        @error('ssl_warning_days.*')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('expiry_warning_days') || $errors->has('expiry_warning_days.*')])>
        <label for="expiry_warning_days">Vervalwaarschuwingen registratie (dagen)</label>
        <input id="expiry_warning_days" name="expiry_warning_days" type="text" value="{{ $oldDays('expiry_warning_days') }}">
        @error('expiry_warning_days')<span class="form-error">{{ $message }}</span>@enderror
        @error('expiry_warning_days.*')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field full', 'has-error' => $errors->has('notes')])>
        <label for="notes">Notities</label>
        <textarea id="notes" name="notes" maxlength="2000">{{ old('notes', $domain->notes) }}</textarea>
    </div>

    <div class="form-section">
        <h3>Onderhoudsvenster</h3>
        <p>Tijdens onderhoud lopen de controles door, maar worden er geen incidenten aangemaakt. Tijden in {{ Time::zone() }}.</p>
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('maintenance_starts_at')])>
        <label for="maintenance_starts_at">Start</label>
        <input id="maintenance_starts_at" name="maintenance_starts_at" type="datetime-local" value="{{ old('maintenance_starts_at', Time::input($domain->maintenance_starts_at)) }}">
        @error('maintenance_starts_at')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div @class(['form-field', 'has-error' => $errors->has('maintenance_ends_at')])>
        <label for="maintenance_ends_at">Einde</label>
        <input id="maintenance_ends_at" name="maintenance_ends_at" type="datetime-local" value="{{ old('maintenance_ends_at', Time::input($domain->maintenance_ends_at)) }}">
        @error('maintenance_ends_at')<span class="form-error">{{ $message }}</span>@enderror
    </div>

    <div class="form-field full">
        <label for="maintenance_reason">Reden</label>
        <input id="maintenance_reason" name="maintenance_reason" type="text" maxlength="255" value="{{ old('maintenance_reason', $domain->maintenance_reason) }}">
    </div>
</div>
