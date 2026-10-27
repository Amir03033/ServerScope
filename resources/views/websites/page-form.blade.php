@php
    use App\Http\Requests\PageRequest;
    use App\Support\Severity;

    $editing = $page->exists;
    $value = fn (string $key, $default = null) => old($key, $page->{$key} ?? $default);
    $checked = fn (string $key, bool $default) => (bool) old($key, $page->exists ? $page->{$key} : $default);
    $headerText = old('header_assertions_input', PageRequest::headerAssertionsText($page->header_assertions));
    $exclusions = old('content_exclusions', $page->content_exclusions ?? []);
    $exclusions = is_array($exclusions) ? implode("\n", $exclusions) : $exclusions;
    $monitoredHeaders = old('monitored_headers', $page->monitored_headers ?? []);
    $monitoredHeaders = is_array($monitoredHeaders) ? implode(', ', $monitoredHeaders) : $monitoredHeaders;
@endphp

<x-layouts.app :title="$editing ? 'Pagina bewerken' : 'Pagina\'s toevoegen'">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> <a href="{{ route('websites.pages', $domain) }}">Pagina's</a> <span>›</span> {{ $editing ? 'Bewerken' : 'Toevoegen' }}</x-slot:breadcrumb>
    <x-slot:subtitle>{{ $editing ? $page->url : 'Voeg een of meer pagina\'s van '.$domain->displayName().' toe. Elke URL wordt vóór opslaan gecontroleerd op een publiek adres.' }}</x-slot:subtitle>

    <section class="panel">
        <form method="POST" action="{{ $editing ? route('pages.update', $page) : route('websites.pages.store', $domain) }}" class="form-cols" novalidate>
            @csrf
            @if ($editing) @method('PUT') @endif

            @if ($editing)
                <div @class(['form-field full', 'has-error' => $errors->has('url')])>
                    <label for="url">URL *</label>
                    <input id="url" name="url" type="url" required maxlength="2048" value="{{ old('url', $page->url) }}">
                    @error('url')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            @else
                <div @class(['form-field full', 'has-error' => $errors->has('urls')])>
                    <label for="urls">URL's (één per regel) *</label>
                    <textarea id="urls" name="urls" required rows="6" placeholder="{{ $domain->scheme }}://{{ $domain->name }}/&#10;/prijzen&#10;/docs&#10;/api/health">{{ old('urls') }}</textarea>
                    <span class="form-help">Volledige URL's of paden (beginnend met /). Alleen {{ $domain->name }} en subdomeinen daarvan; maximaal {{ PageRequest::MAX_URLS_PER_REQUEST }} per keer.</span>
                    @error('urls')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            @endif

            <div class="form-section"><h3>Controle</h3><p>Een pagina geldt pas als onbereikbaar na het ingestelde aantal mislukte controles op rij.</p></div>

            @foreach ([['check_interval_minutes', 'Interval (minuten)', 5, 1, 1440], ['timeout_seconds', 'Time-out (seconden)', 10, 2, 30], ['failure_threshold', 'Faaldrempel (controles)', 2, 1, 10], ['recovery_threshold', 'Hersteldrempel (controles)', 2, 1, 10]] as [$key, $label, $default, $min, $max])
                <div @class(['form-field', 'has-error' => $errors->has($key)])>
                    <label for="{{ $key }}">{{ $label }} *</label>
                    <input id="{{ $key }}" name="{{ $key }}" type="number" min="{{ $min }}" max="{{ $max }}" required value="{{ $value($key, $default) }}">
                    @error($key)<span class="form-error">{{ $message }}</span>@enderror
                </div>
            @endforeach

            <div @class(['form-field', 'has-error' => $errors->has('expected_status_codes')])>
                <label for="expected_status_codes">Verwachte statuscodes</label>
                <input id="expected_status_codes" name="expected_status_codes" type="text" maxlength="100" placeholder="200-399" value="{{ $value('expected_status_codes') }}">
                <span class="form-help">Bijv. 200 of 200-299,301. Leeg = 200-399.</span>
                @error('expected_status_codes')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div @class(['form-field', 'has-error' => $errors->has('max_response_ms')])>
                <label for="max_response_ms">Maximale responstijd (ms)</label>
                <input id="max_response_ms" name="max_response_ms" type="number" min="50" max="60000" value="{{ $value('max_response_ms') }}" placeholder="optioneel">
                <span class="form-help">Daarboven heet de pagina "verminderd"; dat is geen storing.</span>
                @error('max_response_ms')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-field">
                <label class="check"><input type="hidden" name="follow_redirects" value="0"><input type="checkbox" name="follow_redirects" value="1" @checked($checked('follow_redirects', true))> Redirects volgen (elke stap wordt opnieuw gevalideerd)</label>
            </div>

            <div @class(['form-field', 'has-error' => $errors->has('expected_final_url')])>
                <label for="expected_final_url">Verwachte eind-URL na redirects</label>
                <input id="expected_final_url" name="expected_final_url" type="url" maxlength="2048" value="{{ $value('expected_final_url') }}" placeholder="optioneel">
                @error('expected_final_url')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-section"><h3>Inhoud en headers</h3><p>Een mislukte inhouds- of headercontrole is iets anders dan een netwerkfout en wordt apart gemeld.</p></div>

            <div class="form-field"><label for="keyword_present">Moet bevatten</label><input id="keyword_present" name="keyword_present" type="text" maxlength="255" value="{{ $value('keyword_present') }}"></div>
            <div class="form-field"><label for="keyword_absent">Mag niet bevatten</label><input id="keyword_absent" name="keyword_absent" type="text" maxlength="255" value="{{ $value('keyword_absent') }}" placeholder="bijv. Fatal error"></div>

            <div @class(['form-field', 'has-error' => $errors->has('header_assertions') || $errors->has('header_assertions.*.name')])>
                <label for="header_assertions">Headercontroles</label>
                <textarea id="header_assertions" name="header_assertions" rows="4" placeholder="Strict-Transport-Security&#10;Content-Type ~ text/html&#10;!X-Powered-By">{{ $headerText }}</textarea>
                <span class="form-help">Per regel: <code>Naam</code> (aanwezig), <code>!Naam</code> (afwezig), <code>Naam = waarde</code> of <code>Naam ~ deel</code>.</span>
                @error('header_assertions')<span class="form-error">{{ $message }}</span>@enderror
                @error('header_assertions.*.name')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div @class(['form-field', 'has-error' => $errors->has('monitored_headers') || $errors->has('monitored_headers.*')])>
                <label for="monitored_headers">Headers volgen op wijzigingen (configuratiedrift)</label>
                <input id="monitored_headers" name="monitored_headers" type="text" value="{{ $monitoredHeaders }}" placeholder="content-security-policy, strict-transport-security">
                <span class="form-help">Kommagescheiden. Wijzigingen worden vastgelegd in de geschiedenis.</span>
                @error('monitored_headers.*')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-field">
                <label class="check"><input type="hidden" name="content_monitoring" value="0"><input type="checkbox" name="content_monitoring" value="1" @checked($checked('content_monitoring', false))> Inhoudswijzigingen volgen</label>
                <span class="form-help">Alleen tekst telt; datums, tijden, tokens en scripts worden standaard genegeerd.</span>
            </div>

            <div @class(['form-field', 'has-error' => $errors->has('content_selector')])>
                <label for="content_selector">Alleen dit deel vergelijken</label>
                <input id="content_selector" name="content_selector" type="text" maxlength="255" value="{{ $value('content_selector') }}" placeholder="bijv. main, #content of .pricing">
                @error('content_selector')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-field full">
                <label for="content_exclusions">Uitsluiten (dynamische blokken, één selector per regel)</label>
                <textarea id="content_exclusions" name="content_exclusions" rows="3" placeholder=".advertentie&#10;#laatste-nieuws">{{ $exclusions }}</textarea>
            </div>

            <div class="form-section"><h3>Meldingen</h3></div>

            <div class="form-field">
                <label class="check"><input type="hidden" name="alerts_enabled" value="0"><input type="checkbox" name="alerts_enabled" value="1" @checked($checked('alerts_enabled', true))> Meldingen voor storingen van deze pagina</label>
                <span class="form-help">Uit: incidenten worden wel vastgelegd, maar er gaat geen melding uit.</span>
            </div>

            <div @class(['form-field', 'has-error' => $errors->has('alert_severity')])>
                <label for="alert_severity">Ernst bij storing *</label>
                <select id="alert_severity" name="alert_severity">@foreach (Severity::options() as $v => $l)<option value="{{ $v }}" @selected($value('alert_severity', 'high') === $v)>{{ $l }}</option>@endforeach</select>
            </div>

            <div class="form-field">
                <label class="check"><input type="hidden" name="notify_content_changes" value="0"><input type="checkbox" name="notify_content_changes" value="1" @checked($checked('notify_content_changes', false))> Melden bij significante inhoudswijziging</label>
            </div>

            <div @class(['form-field', 'has-error' => $errors->has('sla_target')])>
                <label for="sla_target">Beschikbaarheidsdoel (%)</label>
                <input id="sla_target" name="sla_target" type="text" inputmode="decimal" value="{{ $value('sla_target') }}" placeholder="standaard van het domein">
                @error('sla_target')<span class="form-error">{{ $message }}</span>@enderror
            </div>

            <div class="full form-footer">
                <a href="{{ $editing ? route('pages.show', $page) : route('websites.pages', $domain) }}" class="button">Annuleren</a>
                <button class="button button-primary" type="submit">{{ $editing ? 'Opslaan' : 'Toevoegen' }}</button>
            </div>
        </form>
    </section>
</x-layouts.app>
